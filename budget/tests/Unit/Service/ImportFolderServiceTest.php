<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\ImportFolder;
use OCA\Budget\Db\ImportFolderMapper;
use OCA\Budget\Db\ImportTemplate;
use OCA\Budget\Db\ImportTemplateMapper;
use OCA\Budget\Exception\ValidationException;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\Import\FileValidator;
use OCA\Budget\Service\Import\TemplateColumnResolver;
use OCA\Budget\Service\ImportFolderService;
use OCA\Budget\Service\ImportService;
use OCA\Budget\Service\Mail\BudgetMailService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportFolderServiceTest extends TestCase {
	private const NOW = 1791800000;
	private const CSV = "Data,Descrizione,Importo\n2026-10-01,Bar,-3.5\n";

	private ImportFolderService $service;
	/** @var ImportFolderMapper&MockObject */
	private $mapper;
	/** @var ImportService&MockObject */
	private $importService;
	/** @var ILockingProvider&MockObject */
	private $locking;
	/** @var AuditService&MockObject */
	private $audit;

	/** @var ImportFolder[] the user's rows */
	private array $rows = [];
	/** @var Account[] the user's open accounts */
	private array $accounts = [];
	/** @var array<int, ImportTemplate> */
	private array $templates = [];
	private ?string $mainFolderSetting = null;
	/** @var Folder&MockObject the user's Files root */
	private $root;
	/** @var list<array{string, array<string, mixed>}> [subject, parameters] of each notification sent */
	private array $sent = [];
	/** @var list<array{string, string, string}> [subject, object type, object id] of each notification dismissed */
	private array $dismissed = [];
	/** @var array<string, string> the user's preferences, app.key => value */
	private array $prefs = [];
	/** @var \WeakMap<Folder, \ArrayObject> what each in-memory folder holds, by name */
	private \WeakMap $children;
	/** @var \WeakMap<File, string> where each file was moved to */
	private \WeakMap $movedTo;
	/** @var string[] paths of folders that are someone else's, shared with Alice */
	private array $sharedWithAlice = [];
	/** @var string[] paths of folders on an external storage mount */
	private array $external = [];
	/** @var string[] paths of folders whose contents cannot be listed right now */
	private array $unlistable = [];
	/** @var string[] folder names Files will not create */
	private array $namesFilesRefuses = [];
	/** @var array<int, Folder> every in-memory folder still in Files, by id */
	private array $foldersById = [];
	private int $nextFolderId = 1000;
	/** @var array<int, string> each file's etag, by id, where a test sets one */
	private array $etags = [];

	protected function setUp(): void {
		$this->children = new \WeakMap();
		$this->movedTo = new \WeakMap();

		$this->mapper = $this->createMock(ImportFolderMapper::class);
		$this->mapper->method('findAll')->willReturnCallback(fn () => $this->rows);
		$this->mapper->method('findEnabled')->willReturnCallback(
			fn () => array_values(array_filter($this->rows, static fn (ImportFolder $f) => $f->isEnabled()))
		);
		$this->mapper->method('find')->willReturnCallback(function (int $id) {
			foreach ($this->rows as $row) {
				if ($row->getId() === $id) {
					return $row;
				}
			}
			throw new DoesNotExistException('no row');
		});
		$this->mapper->method('insert')->willReturnCallback(function (ImportFolder $folder) {
			$folder->setId(count($this->rows) + 1);
			$this->rows[] = $folder;
			return $folder;
		});
		$this->mapper->method('update')->willReturnArgument(0);

		$accountMapper = $this->createMock(AccountMapper::class);
		$accountMapper->method('findOpen')->willReturnCallback(fn () => $this->accounts);

		$templateMapper = $this->createMock(ImportTemplateMapper::class);
		$templateMapper->method('find')->willReturnCallback(
			fn (int $id) => $this->templates[$id] ?? throw new DoesNotExistException('no template')
		);

		$settings = $this->createMock(SettingService::class);
		$settings->method('get')->willReturnCallback(
			fn (string $userId, string $key) => $key === 'import_folder' ? $this->mainFolderSetting : null
		);

		$this->root = $this->folder('/alice/files');
		$rootFolder = $this->createMock(IRootFolder::class);
		$rootFolder->method('getUserFolder')->willReturn($this->root);

		$this->importService = $this->createMock(ImportService::class);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->audit = $this->createMock(AuditService::class);

		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTemporaryFile')->willReturnCallback(static fn (): string => tempnam(sys_get_temp_dir(), 'ifs'));

		$notifications = $this->createMock(INotificationManager::class);
		$built = new \WeakMap();
		$notifications->method('createNotification')->willReturnCallback(function () use ($built) {
			$notification = $this->createMock(INotification::class);
			$state = new \ArrayObject(['subject' => '', 'parameters' => [], 'objectType' => '', 'objectId' => '']);
			$built[$notification] = $state;
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters = []) use ($notification, $state) {
				$state['subject'] = $subject;
				$state['parameters'] = $parameters;
				return $notification;
			});
			$notification->method('setObject')->willReturnCallback(function (string $type, string $id) use ($notification, $state) {
				$state['objectType'] = $type;
				$state['objectId'] = $id;
				return $notification;
			});
			$notification->method($this->anything())->willReturnSelf();
			return $notification;
		});
		$notifications->method('notify')->willReturnCallback(function (INotification $notification) use ($built): void {
			$this->sent[] = [$built[$notification]['subject'], $built[$notification]['parameters']];
		});
		$notifications->method('markProcessed')->willReturnCallback(function (INotification $notification) use ($built): void {
			$this->dismissed[] = [$built[$notification]['subject'], $built[$notification]['objectType'], $built[$notification]['objectId']];
		});

		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $userId, string $app, string $key, $default = '') => $this->prefs[$app . '.' . $key] ?? $default
		);
		$config->method('setUserValue')->willReturnCallback(function (string $userId, string $app, string $key, $value): void {
			$this->prefs[$app . '.' . $key] = (string)$value;
		});
		$config->method('getUserValueForUsers')->willReturnCallback(
			fn (string $app, string $key, array $userIds) => isset($this->prefs[$app . '.' . $key]) ? [$userIds[0] => $this->prefs[$app . '.' . $key]] : []
		);
		$config->method('deleteUserValue')->willReturnCallback(function (string $userId, string $app, string $key): void {
			unset($this->prefs[$app . '.' . $key]);
		});

		$mail = $this->createMock(BudgetMailService::class);
		$mail->method('getUserLanguage')->willReturn('it');

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text, array $parameters = []): string => vsprintf($text, $parameters));
		$italian = $this->createMock(IL10N::class);
		$italian->method('t')->willReturnCallback(
			static fn (string $text, array $parameters = []): string => $text === 'Column: {number}' ? 'Colonna: {number}' : vsprintf($text, $parameters)
		);
		$factory = $this->createMock(IFactory::class);
		$factory->method('get')->willReturn($italian);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::NOW);
		$time->method('getDateTime')->willReturn(new \DateTime('@' . self::NOW));

		$this->service = new ImportFolderService(
			$this->mapper, $accountMapper, $templateMapper, $settings, $rootFolder, $this->importService,
			new TemplateColumnResolver(), new FileValidator(), $temp, $this->locking, $notifications, $this->audit,
			$mail, $factory, $time, $config, $l, $this->createMock(LoggerInterface::class),
		);

		$this->accounts = [$this->account(3, 'Conto Mario')];
	}

	// ── helpers ─────────────────────────────────────────────────────

	private function account(int $id, string $name, string $currency = 'EUR'): Account {
		$account = new Account();
		$account->setId($id);
		$account->setName($name);
		$account->setCurrency($currency);
		$account->setUserId('alice');
		return $account;
	}

	private function row(int $id, string $name, int $accountId = 3, ?int $templateId = null, bool $enabled = true, string $mode = 'auto'): ImportFolder {
		$row = new ImportFolder();
		$row->setId($id);
		$row->setUserId('alice');
		$row->setName($name);
		$row->setAccountId($accountId);
		$row->setTemplateId($templateId);
		$row->setMode($mode);
		$row->setEnabled($enabled);
		return $row;
	}

	/**
	 * A Files folder kept in memory: created children are real to later calls.
	 *
	 * @return Folder&MockObject
	 */
	private function folder(string $path) {
		$children = new \ArrayObject();
		$folder = $this->createMock(Folder::class);
		$this->children[$folder] = $children;
		$id = $this->nextFolderId++;
		$this->foldersById[$id] = $folder;
		$folder->method('getId')->willReturn($id);
		$folder->method('getFirstNodeById')->willReturnCallback(fn (int $id) => $this->foldersById[$id] ?? null);
		$folder->method('getPath')->willReturn($path);
		$folder->method('getName')->willReturn(basename($path));
		// Alice's own, unless the test says someone shared it with her
		$folder->method('getOwner')->willReturnCallback(fn () => $this->createConfiguredMock(
			\OCP\IUser::class,
			['getUID' => in_array($path, $this->sharedWithAlice, true) ? 'mallory' : 'alice']
		));
		// In her home storage, unless the test puts it on an external mount
		$folder->method('getStorage')->willReturnCallback(fn () => $this->storage(!in_array($path, $this->external, true)));
		$folder->method('nodeExists')->willReturnCallback(static fn (string $name): bool => isset($children[$name]));
		$folder->method('get')->willReturnCallback(static fn (string $name) => $children[$name]);
		$folder->method('newFolder')->willReturnCallback(function (string $name) use ($children, $path) {
			if (in_array($name, $this->namesFilesRefuses, true)) {
				throw new \OCP\Files\InvalidPathException('refused');
			}
			return $children[$name] = $this->folder($path . '/' . $name);
		});
		$folder->method('getDirectoryListing')->willReturnCallback(function () use ($children, $path): array {
			if (in_array($path, $this->unlistable, true)) {
				throw new \OCP\Files\NotPermittedException('storage not available');
			}
			return array_values($children->getArrayCopy());
		});
		return $folder;
	}

	private function storage(bool $home) {
		$storage = $this->createMock(\OCP\Files\Storage\IStorage::class);
		$storage->method('instanceOfStorage')->willReturnCallback(
			static fn (string $class): bool => $home && $class === \OCP\Files\IHomeStorage::class
		);
		return $storage;
	}

	/** The folder at a path under the user's Files root, created on the way */
	private function at(string $path) {
		$folder = $this->root;
		foreach (explode('/', $path) as $segment) {
			$folder = $folder->nodeExists($segment) ? $folder->get($segment) : $folder->newFolder($segment);
		}
		return $folder;
	}

	// ── the main folder setting ─────────────────────────────────────

	public function testTheMainFolderDefaultsAndIsNormalised(): void {
		$this->assertSame('Budget/Import', $this->service->mainFolderFor('alice'));
		$this->mainFolderSetting = '\\Bank\\Statements\\';
		$this->assertSame('Bank/Statements', $this->service->mainFolderFor('alice'));
		$this->mainFolderSetting = '../escape';
		$this->assertSame('Budget/Import', $this->service->mainFolderFor('alice'));
	}

	public function testNormalizeMainFolderRefusesAPathOutsideFiles(): void {
		$this->assertSame('Bank/Statements', ImportFolderService::normalizeMainFolder('/Bank//Statements/'));
		$this->expectException(\InvalidArgumentException::class);
		ImportFolderService::normalizeMainFolder('Bank/../../etc');
	}

	// ── create / update / delete ────────────────────────────────────

	public function testCreateMakesTheRowAndTheFolderInFiles(): void {
		$this->templates[5] = new ImportTemplate();

		$row = $this->service->create('alice', '  Conto Mario ', 3, 5)['folder'];

		$this->assertSame('Conto Mario', $row->getName());
		$this->assertSame(3, $row->getAccountId());
		$this->assertSame(5, $row->getTemplateId());
		$this->assertSame('auto', $row->getMode());
		$this->assertTrue($row->isEnabled());
		$this->assertSame('alice', $row->getUserId());
		$this->assertTrue($this->at('Budget/Import')->nodeExists('Conto Mario'));
	}

	public function testCreateRefusesABadName(): void {
		foreach (['', '   ', 'a/b', 'a\\b', '..', str_repeat('x', 101), "tab\tname"] as $name) {
			try {
				$this->service->create('alice', $name, 3, null);
				$this->fail('Accepted ' . json_encode($name));
			} catch (\InvalidArgumentException $e) {
				$this->assertStringContainsString('folder name', $e->getMessage());
			}
		}
	}

	public function testCreateRefusesANameAlreadyUsedWhateverItsCase(): void {
		$this->rows = [$this->row(1, 'Conto Mario')];
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('already have an import folder called conto mario');
		$this->service->create('alice', 'conto mario', 3, null);
	}

	public function testCreateRefusesAnAccountThatIsNotTheUsersOwnOpenOne(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('one of your own open accounts');
		$this->service->create('alice', 'Shared', 99, null);
	}

	public function testCreateRefusesATemplateThatIsNotTheUsers(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('import template');
		$this->service->create('alice', 'Conto', 3, 77);
	}

	public function testUpdateChangesOnlyWhatItIsGiven(): void {
		$this->templates[5] = new ImportTemplate();
		$this->accounts[] = $this->account(4, 'Carta');
		$this->rows = [$this->row(1, 'Conto', 3, 5)];

		$row = $this->service->update('alice', 1, ['enabled' => false]);
		$this->assertFalse($row->isEnabled());
		$this->assertSame(3, $row->getAccountId());
		$this->assertSame(5, $row->getTemplateId());

		$row = $this->service->update('alice', 1, ['accountId' => 4, 'templateId' => null]);
		$this->assertSame(4, $row->getAccountId());
		$this->assertNull($row->getTemplateId());
		$this->assertFalse($row->isEnabled());
	}

	public function testUpdateValidatesLikeCreate(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->expectException(\InvalidArgumentException::class);
		$this->service->update('alice', 1, ['accountId' => 99]);
	}

	public function testDeleteRemovesTheRowAndLeavesFilesAlone(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->at('Budget/Import/Conto');
		$this->mapper->expects($this->once())->method('delete')->with($this->rows[0]);

		$this->service->delete('alice', 1);

		$this->assertTrue($this->at('Budget/Import')->nodeExists('Conto'));
	}

	public function testCreateRefusesAnImportFolderThatIsNotInTheUsersOwnFiles(): void {
		// Someone shared a folder with Alice at the path her import folder
		// resolves to: whoever owns it could feed statements into her accounts
		$this->at('Budget/Import');
		$this->sharedWithAlice = ['/alice/files/Budget/Import'];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('your own Files');
		$this->service->create('alice', 'Conto', 3, null);
	}

	public function testListGivesTheMainFolderAndTheRows(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->assertSame(['folder' => 'Budget/Import', 'folders' => $this->rows], $this->service->list('alice'));
	}

	// ── check(): helpers ────────────────────────────────────────────

	/**
	 * A file in a folder. $age is seconds since it was last modified (less
	 * than nothing: a date still to come), $uploadAge since it was uploaded
	 * (null: Files never recorded it, as for a file written by an app).
	 *
	 * @return File&MockObject
	 */
	private function fileIn($folder, string $name, int $id = 100, int $age = 600, int $size = 230, ?string $content = null, string $owner = 'alice', bool $movable = true, ?int $uploadAge = null) {
		$content ??= self::CSV;
		$file = $this->createMock(File::class);
		// Once moved, it answers to its new name, as a real node does
		$file->method('getName')->willReturnCallback(fn (): string => isset($this->movedTo[$file]) ? basename($this->movedTo[$file]) : $name);
		$file->method('getUploadTime')->willReturn($uploadAge === null ? 0 : self::NOW - $uploadAge);
		$file->method('getId')->willReturn($id);
		// What Files has recorded, which is not always what is there
		$file->method('getSize')->willReturn($size);
		$file->method('getMTime')->willReturn(self::NOW - $age);
		$file->method('getEtag')->willReturnCallback(fn (): string => $this->etags[$id] ?? 'etag' . $id);
		$file->method('getOwner')->willReturn($this->createConfiguredMock(\OCP\IUser::class, ['getUID' => $owner]));
		$file->method('getStorage')->willReturn($this->storage(true));
		$file->method('fopen')->willReturnCallback(static function () use ($content) {
			$stream = fopen('php://memory', 'r+');
			fwrite($stream, $content);
			rewind($stream);
			return $stream;
		});
		$file->method('move')->willReturnCallback(function (string $path) use ($file, $movable) {
			if (!$movable) {
				throw new \OCP\Files\NotPermittedException('read-only');
			}
			$this->movedTo[$file] = $path;
			return $file;
		});
		$this->children[$folder]->offsetSet($name, $file);
		return $file;
	}

	private function whereItWent(File $file): ?string {
		return $this->movedTo[$file] ?? null;
	}

	private function csvTemplate(int $id, array $mapping = ['date' => 'Data', 'description' => 'Descrizione', 'amount' => 'Importo', 'skipFirstRow' => true]): ImportTemplate {
		$template = new ImportTemplate();
		$template->setId($id);
		$template->setFormat('csv');
		$template->setMappingFromArray($mapping);
		$template->setDelimiter(',');
		$template->setSkipDuplicates(true);
		$template->setApplyRules(true);
		return $this->templates[$id] = $template;
	}

	/** The import service behaving for a CSV with the three usual columns */
	private function givenACsvUpload(array $preview = ['validTransactions' => 2, 'duplicates' => 1], array $result = ['imported' => 2, 'skipped' => 1, 'errors' => []]): void {
		$this->importService->method('processUpload')->willReturn(['fileId' => 'import_alice_abc.csv', 'format' => 'csv', 'sourceAccounts' => []]);
		$this->importService->method('dataPreview')->willReturn(['columns' => ['Data', 'Descrizione', 'Importo']]);
		$this->importService->method('previewImport')->willReturn($preview);
		$this->importService->method('processImport')->willReturn($result);
	}

	private function subjects(): array {
		return array_column($this->sent, 0);
	}

	// ── check() ─────────────────────────────────────────────────────

	public function testACsvIsImportedWithItsTemplateMovedAndReported(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$folder = $this->at('Budget/Import/Conto');
		$file = $this->fileIn($folder, 'ottobre.csv');
		$this->givenACsvUpload();

		$this->importService->expects($this->once())->method('processUpload')
			->with('alice', $this->callback(fn (array $upload) => $upload['name'] === 'ottobre.csv'
				// The bytes actually read, not the size Files had recorded
				&& $upload['size'] === strlen(self::CSV)
				&& file_get_contents($upload['tmp_name']) === self::CSV));
		$this->importService->expects($this->once())->method('processImport')->with(
			'alice', 'import_alice_abc.csv',
			['date' => '0', 'description' => '1', 'amount' => '2', 'skipFirstRow' => true, 'applyRules' => true],
			3, null, true, true, ',', null, null
		);
		$this->audit->expects($this->once())->method('logImportStarted')->with('alice', 'ottobre.csv', 'csv');
		$this->audit->expects($this->once())->method('logImportCompleted')->with('alice', 3, 2, 1);

		$summary = $this->service->check('alice');

		$this->assertSame(['imported' => 1, 'failed' => 0, 'waiting' => 0, 'review' => 0], $summary);
		$this->assertSame('/alice/files/Budget/Import/Conto/Imported/ottobre.csv', $this->whereItWent($file));
		$this->assertSame([['import_folder_done', [
			'fileId' => '100', 'fileName' => 'ottobre.csv', 'accountId' => '3', 'accountName' => 'Conto Mario',
			'imported' => '2', 'skipped' => '1', 'errors' => '0',
		]]], $this->sent);
	}

	public function testAFileStillBeingWrittenWaits(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv', age: 30);
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 1, 'review' => 0], $this->service->check('alice'));
		$this->assertNull($this->whereItWent($file));
	}

	public function testHiddenAndPartialFilesAndSubfoldersAreLeftAlone(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$folder = $this->at('Budget/Import/Conto');
		$this->fileIn($folder, '.DS_Store', 1);
		$this->fileIn($folder, '~$estratto.xlsx', 2);
		$this->fileIn($folder, 'estratto.csv.part', 3);
		$folder->newFolder('Imported');
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
	}

	public function testASubfolderSomeoneElseOwnsIsNeverRead(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Theirs', 3, 5), $this->row(2, 'Conto', 3, 5)];
		$theirs = $this->fileIn($this->at('Budget/Import/Theirs'), 'planted.csv', 1);
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv', 2);
		$this->sharedWithAlice = ['/alice/files/Budget/Import/Theirs'];
		$this->givenACsvUpload();
		$this->importService->expects($this->once())->method('processImport');

		$this->assertSame(['imported' => 1, 'failed' => 0, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
		$this->assertNull($this->whereItWent($theirs));
	}

	public function testAnImportFolderSomeoneElseOwnsIsNeverRead(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'planted.csv');
		$this->sharedWithAlice = ['/alice/files/Budget/Import'];
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
	}

	public function testASubfolderNameTakenByAFileDoesNotStopTheOtherFolders(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Broken', 3, 5), $this->row(2, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import'), 'Broken', 1);
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv', 2);
		$this->givenACsvUpload();

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testASwitchedOffFolderIsNotRead(): void {
		$this->rows = [$this->row(1, 'Conto', 3, null, false)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
	}

	public function testAFolderDeletedInFilesIsRecreatedAndTheOthersStillRun(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Gone', 3, 5), $this->row(2, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload();

		$this->assertSame(1, $this->service->check('alice')['imported']);
		$this->assertTrue($this->at('Budget/Import')->nodeExists('Gone'));
	}

	public function testAFileThatIsNotAStatementFailsWithoutReachingTheParsers(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'ricevuta.pdf');
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(['imported' => 0, 'failed' => 1, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
		$this->assertSame('/alice/files/Budget/Import/Conto/Failed/ricevuta.pdf', $this->whereItWent($file));
		$this->assertSame('unsupported_type', $this->sent[0][1]['reason']);
		$this->assertSame('1', $this->sent[0][1]['moved']);
	}

	public function testAFileOverTheSizeLimitFails(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'enorme.csv', size: 11 * 1024 * 1024);
		$this->importService->expects($this->never())->method('processUpload');

		$this->service->check('alice');
		$this->assertSame('too_large', $this->sent[0][1]['reason']);
	}

	public function testACsvInAFolderWithNoCsvTemplateFails(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload();
		$this->importService->expects($this->never())->method('processImport');

		$this->service->check('alice');
		$this->assertSame(['import_folder_failed'], $this->subjects());
		$this->assertSame('no_template', $this->sent[0][1]['reason']);
	}

	public function testColumnsTheFileLacksAreNamed(): void {
		$this->csvTemplate(5, ['date' => 'Booking date', 'description' => 'Descrizione', 'amount' => 'Amount', 'skipFirstRow' => true]);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload();
		$this->importService->expects($this->never())->method('processImport');

		$this->service->check('alice');
		$this->assertSame('columns_missing', $this->sent[0][1]['reason']);
		$this->assertSame('Booking date, Amount', $this->sent[0][1]['columns']);
	}

	public function testATemplateSavedWithATranslatedColumnLabelResolvesInTheUsersLanguage(): void {
		$this->csvTemplate(5, ['date' => 'Data', 'description' => 'Colonna: 2', 'amount' => 'Importo', 'skipFirstRow' => true]);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->importService->method('processUpload')->willReturn(['fileId' => 'f.csv', 'format' => 'csv', 'sourceAccounts' => []]);
		$this->importService->method('dataPreview')->willReturn(['columns' => ['Data', '', 'Importo']]);
		$this->importService->method('previewImport')->willReturn(['validTransactions' => 1, 'duplicates' => 0]);
		$this->importService->expects($this->once())->method('processImport')
			->with($this->anything(), $this->anything(), $this->callback(fn (array $mapping) => $mapping['description'] === '1'))
			->willReturn(['imported' => 1, 'skipped' => 0, 'errors' => []]);

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testABatchThatLooksTheWrongWayRoundIsNotImported(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload(['validTransactions' => 9, 'duplicates' => 0, 'directionWarnings' => [['kind' => 'direction', 'accountId' => 3]]]);
		$this->importService->expects($this->never())->method('processImport');

		$this->service->check('alice');
		$this->assertSame('wrong_direction', $this->sent[0][1]['reason']);
	}

	public function testRowsWithNoTypeAreOnlyAWarningAndStillImport(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload(['validTransactions' => 2, 'duplicates' => 0, 'directionWarnings' => [['kind' => 'unresolved-type']]]);

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testAFileWithNothingReadableFails(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload(['validTransactions' => 0, 'duplicates' => 0, 'errors' => [['row' => 1, 'error' => 'Invalid date']]]);
		$this->importService->expects($this->never())->method('processImport');

		$this->service->check('alice');
		$this->assertSame('nothing_read', $this->sent[0][1]['reason']);
	}

	public function testAStatementAlreadyImportedByHandEndsInImportedWithNothingNew(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload(['validTransactions' => 0, 'duplicates' => 12], ['imported' => 0, 'skipped' => 12, 'errors' => []]);

		$this->assertSame(1, $this->service->check('alice')['imported']);
		$this->assertStringEndsWith('/Imported/ottobre.csv', $this->whereItWent($file));
		$this->assertSame('0', $this->sent[0][1]['imported']);
		$this->assertSame('12', $this->sent[0][1]['skipped']);
	}

	public function testRowsThatCouldNotBeReadAreCountedAndTheRestImported(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload(
			['validTransactions' => 2, 'duplicates' => 0],
			['imported' => 2, 'skipped' => 0, 'errors' => [['row' => 4, 'error' => 'Invalid date'], ['row' => 9, 'error' => 'Invalid date']]]
		);

		$this->service->check('alice');
		$this->assertSame('2', $this->sent[0][1]['errors']);
	}

	/** The import service reading an OFX that names these accounts */
	private function givenAnOfxUpload(array $sourceAccounts, string $format = 'ofx'): void {
		$this->importService->method('processUpload')->willReturn(['fileId' => 'f.ofx', 'format' => $format, 'sourceAccounts' => $sourceAccounts]);
		$this->importService->method('previewImport')->willReturn(['accountSummaries' => array_map(
			static fn (array $source) => ['transactionCount' => $source['transactionCount'] ?? 1, 'duplicates' => 0],
			$sourceAccounts
		)]);
	}

	public function testAnOfxNeedsNoTemplateAndGoesToTheFoldersAccount(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'estratto.ofx', content: "OFXHEADER:100\n<OFX></OFX>\n");
		$this->givenAnOfxUpload([['accountId' => 'IT60X', 'currency' => 'eur', 'transactionCount' => 4]]);
		$this->importService->expects($this->never())->method('dataPreview');
		$this->importService->expects($this->once())->method('processImport')
			->with('alice', 'f.ofx', [], 3, ['IT60X' => 3], true, true, ',', null, null)
			->willReturn(['imported' => 4, 'skipped' => 0, 'errors' => []]);

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testAnOfxThatDoesNotSayItsCurrencyGoesToTheFoldersAccount(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'estratto.qif', content: "!Type:Bank\nD01/10/2026\nT-3.50\n^\n");
		$this->givenAnOfxUpload([['accountId' => 'Conto', 'transactionCount' => 1]]);
		$this->importService->expects($this->once())->method('processImport')->willReturn(['imported' => 1, 'skipped' => 0, 'errors' => []]);

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testAStatementOfSeveralAccountsIsNotPutIntoOne(): void {
		// A checking account and a credit card in one OFX: both would have
		// landed in the folder's one account, the card's payment included
		$this->rows = [$this->row(1, 'Conto')];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'estratto.ofx', content: "OFXHEADER:100\n<OFX></OFX>\n");
		$this->givenAnOfxUpload([
			['accountId' => 'IT60X', 'currency' => 'EUR', 'transactionCount' => 4],
			['accountId' => '4111', 'currency' => 'EUR', 'transactionCount' => 5],
		]);
		$this->importService->expects($this->never())->method('processImport');

		$this->assertSame(['imported' => 0, 'failed' => 1, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
		$this->assertStringEndsWith('/Failed/estratto.ofx', $this->whereItWent($file));
		$this->assertSame('several_accounts', $this->sent[0][1]['reason']);
	}

	public function testACamtOfSeveralDailyStatementsForOneAccountIsImported(): void {
		// A camt.053 has a <Stmt> per day, each naming the same IBAN
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'estratto.xml', content: '<Document><BkToCstmrStmt/></Document>');
		$this->givenAnOfxUpload([
			['accountId' => 'IT60X', 'currency' => 'EUR', 'transactionCount' => 3],
			['accountId' => 'IT60X', 'currency' => 'EUR', 'transactionCount' => 2],
			['accountId' => 'IT60X', 'currency' => 'EUR', 'transactionCount' => 0],
		], 'camt');
		$this->importService->expects($this->once())->method('processImport')
			->with('alice', 'f.ofx', [], 3, ['IT60X' => 3], true, true, ',', null, null)
			->willReturn(['imported' => 5, 'skipped' => 0, 'errors' => []]);

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testAnAccountWithNothingInTheStatementDoesNotCount(): void {
		// An OFX of every account at the bank: only the current account had
		// anything this month, and the empty card is in dollars
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'estratto.ofx', content: "OFXHEADER:100\n<OFX></OFX>\n");
		$this->givenAnOfxUpload([
			['accountId' => 'IT60X', 'currency' => 'EUR', 'transactionCount' => 4],
			['accountId' => '4111', 'currency' => 'USD', 'transactionCount' => 0],
		]);
		// The empty one is not routed anywhere, so it is not remembered as this account's
		$this->importService->expects($this->once())->method('processImport')
			->with('alice', 'f.ofx', [], 3, ['IT60X' => 3], true, true, ',', null, null)
			->willReturn(['imported' => 4, 'skipped' => 0, 'errors' => []]);

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testAStatementWithNothingInItSaysSoRatherThanSeveralAccounts(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'estratto.ofx', content: "OFXHEADER:100\n<OFX></OFX>\n");
		$this->givenAnOfxUpload([
			['accountId' => 'IT60X', 'currency' => 'EUR', 'transactionCount' => 0],
			['accountId' => '4111', 'currency' => 'USD', 'transactionCount' => 0],
		]);
		$this->importService->expects($this->never())->method('processImport');

		$this->assertSame(1, $this->service->check('alice')['failed']);
		$this->assertStringEndsWith('/Failed/estratto.ofx', $this->whereItWent($file));
		$this->assertSame('nothing_read', $this->sent[0][1]['reason']);
	}

	public function testAStatementInAnotherCurrencyIsNotImported(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'carta.ofx', content: "OFXHEADER:100\n<OFX></OFX>\n");
		$this->givenAnOfxUpload([['accountId' => '4111', 'currency' => 'USD', 'transactionCount' => 5]]);
		$this->importService->expects($this->never())->method('processImport');

		$this->assertSame(1, $this->service->check('alice')['failed']);
		$this->assertStringEndsWith('/Failed/carta.ofx', $this->whereItWent($file));
		$this->assertSame('other_currency', $this->sent[0][1]['reason']);
		$this->assertSame('USD', $this->sent[0][1]['currency']);
		$this->assertSame('EUR', $this->sent[0][1]['accountCurrency']);
	}

	public function testAReasonWrittenForTheUserIsPassedOn(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'estratto.xlsx');
		$this->importService->method('processUpload')->willThrowException(new ValidationException('This spreadsheet has no table of transactions to import.'));
		$this->audit->expects($this->once())->method('logImportFailed')->with('alice', 'estratto.xlsx', $this->anything());

		$this->assertSame(1, $this->service->check('alice')['failed']);
		$this->assertSame('message', $this->sent[0][1]['reason']);
		$this->assertSame('This spreadsheet has no table of transactions to import.', $this->sent[0][1]['message']);
		$this->assertStringEndsWith('/Failed/estratto.xlsx', $this->whereItWent($file));
	}

	public function testAnUnexpectedErrorIsReportedWithoutItsDetails(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->importService->method('processUpload')->willThrowException(new \RuntimeException('SQLSTATE[HY000] something internal'));

		$this->service->check('alice');
		$this->assertSame('error', $this->sent[0][1]['reason']);
		$this->assertArrayNotHasKey('message', $this->sent[0][1]);
	}

	public function testAnAccountThatWasClosedSinceFailsItsFiles(): void {
		$this->rows = [$this->row(1, 'Conto', 42)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->importService->expects($this->never())->method('processUpload');

		$this->service->check('alice');
		$this->assertSame('account_unavailable', $this->sent[0][1]['reason']);
	}

	public function testANameAlreadyInImportedGetsANumber(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$folder = $this->at('Budget/Import/Conto');
		$this->fileIn($this->at('Budget/Import/Conto/Imported'), 'ottobre.csv', 50);
		$file = $this->fileIn($folder, 'ottobre.csv');
		$this->givenACsvUpload();

		$this->service->check('alice');
		$this->assertStringEndsWith('/Imported/ottobre-1.csv', $this->whereItWent($file));
	}

	public function testOnlySoManyFilesAreTakenInOneRun(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$folder = $this->at('Budget/Import/Conto');
		$this->fileIn($folder, 'a.csv', 1, 900);
		$this->fileIn($folder, 'b.csv', 2, 800);
		$this->fileIn($folder, 'c.csv', 3, 700);
		$this->givenACsvUpload();

		$this->assertSame(['imported' => 2, 'failed' => 0, 'waiting' => 1, 'review' => 0], $this->service->check('alice', 2));
	}

	public function testAFileSomeoneElseIsAlreadyImportingIsSkipped(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->locking->method('acquireLock')->willThrowException(new LockedException('budget/import-folder/100'));
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
		$this->assertNull($this->whereItWent($file));
		$this->assertSame([], $this->sent);
	}

	public function testTheLockIsAlwaysReleased(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->importService->method('processUpload')->willThrowException(new \RuntimeException('boom'));
		$this->locking->expects($this->once())->method('acquireLock')->with('budget/import-folder/100', ILockingProvider::LOCK_EXCLUSIVE);
		$this->locking->expects($this->once())->method('releaseLock')->with('budget/import-folder/100', ILockingProvider::LOCK_EXCLUSIVE);

		$this->service->check('alice');
	}

	public function testAFileThatCannotBeMovedSwitchesTheFolderOff(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv', movable: false);
		$this->givenACsvUpload();
		$this->mapper->expects($this->once())->method('update')->with($this->callback(fn (ImportFolder $row) => !$row->isEnabled()));

		$this->service->check('alice');

		$this->assertSame(['import_folder_done', 'import_folder_failed'], $this->subjects());
		$this->assertSame('not_moved', $this->sent[1][1]['reason']);
		$this->assertSame('0', $this->sent[1][1]['moved']);
	}

	// ── what is read has to be the user's own, and no more than the limit ──

	public function testAFileBiggerThanFilesSaysItIsStillStopsAtTheLimit(): void {
		// The recorded size can be stale or wrong; the read itself is bounded
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'enorme.csv', size: 100, content: str_repeat('x', 10 * 1024 * 1024 + 1));
		$this->importService->expects($this->never())->method('processUpload');

		$this->service->check('alice');
		$this->assertSame('too_large', $this->sent[0][1]['reason']);
	}

	public function testAFileSomeoneElseOwnsIsLeftAlone(): void {
		// A file shared with the user that ended up in their folder is not theirs to import
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'planted.csv', owner: 'mallory');
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
		$this->assertNull($this->whereItWent($file));
	}

	public function testNothingIsCreatedInsideAFolderSomeoneElseOwns(): void {
		// "Budget" was shared with Alice and holds no "Import" yet: it must
		// be refused before anything is made inside it
		$budget = $this->root->newFolder('Budget');
		$this->sharedWithAlice = ['/alice/files/Budget'];

		try {
			$this->service->create('alice', 'Conto', 3, null);
			$this->fail('Created an import folder inside a folder shared with the user');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('your own Files', $e->getMessage());
		}
		$this->assertFalse($budget->nodeExists('Import'));
	}

	public function testAFileIsNotMovedIntoAnImportedFolderSomeoneElseOwns(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->at('Budget/Import/Conto/Imported');
		$this->sharedWithAlice = ['/alice/files/Budget/Import/Conto/Imported'];
		$this->givenACsvUpload();

		$this->service->check('alice');

		$this->assertNull($this->whereItWent($file));
		$this->assertSame('not_moved', $this->sent[1][1]['reason']);
	}

	// ── found in the final review ───────────────────────────────────

	public function testAFileAnotherRunHandledMeanwhileIsLeftAlone(): void {
		// Listed by this run, then imported and moved by "Check now" before
		// this run got to it: not a failure, and no reason to switch the folder off
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$folder = $this->at('Budget/Import/Conto');
		$this->fileIn($folder, 'ottobre.csv');
		$this->locking->method('acquireLock')->willReturnCallback(function () use ($folder): void {
			$this->children[$folder]->offsetUnset('ottobre.csv');
		});
		$this->importService->expects($this->never())->method('processUpload');
		$this->mapper->expects($this->never())->method('update');

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
		$this->assertSame([], $this->sent);
	}

	public function testATemplatesAccountAndCurrencyColumnsAreNotUsed(): void {
		// They would send rows to whichever account a cell names, creating
		// it if need be: a folder imports into its own account only
		$this->csvTemplate(5, ['date' => 'Data', 'description' => 'Descrizione', 'amount' => 'Importo', 'account' => 'Conto', 'currency' => 'Valuta', 'skipFirstRow' => true]);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->importService->method('processUpload')->willReturn(['fileId' => 'f.csv', 'format' => 'csv', 'sourceAccounts' => []]);
		$this->importService->method('dataPreview')->willReturn(['columns' => ['Data', 'Descrizione', 'Importo', 'Conto', 'Valuta']]);
		$this->importService->method('previewImport')->willReturn(['validTransactions' => 1, 'duplicates' => 0]);
		$this->importService->expects($this->once())->method('processImport')
			->with($this->anything(), $this->anything(), $this->callback(
				fn (array $mapping) => !array_key_exists('account', $mapping) && !array_key_exists('currency', $mapping) && $mapping['amount'] === '2'
			))
			->willReturn(['imported' => 1, 'skipped' => 0, 'errors' => []]);

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testAFolderOnExternalStorageIsNotAnImportFolder(): void {
		// An external mount reports whoever is logged in as its owner (and
		// nobody at all in a background job), so ownership alone says nothing there
		$this->at('Budget/Import');
		$this->external = ['/alice/files/Budget/Import'];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('your own Files');
		$this->service->create('alice', 'Conto', 3, null);
	}

	public function testAFailedFileThatCannotBeMovedSaysTheFolderWasSwitchedOff(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ricevuta.pdf', movable: false);
		$this->mapper->expects($this->once())->method('update')->with($this->callback(fn (ImportFolder $row) => !$row->isEnabled()));

		$this->service->check('alice');

		$this->assertSame(['import_folder_failed', 'import_folder_failed'], $this->subjects());
		$this->assertSame('unsupported_type', $this->sent[0][1]['reason']);
		$this->assertSame('not_moved', $this->sent[1][1]['reason']);
		$this->assertSame('0', $this->sent[1][1]['imported']);
	}

	public function testAnEmptyFileIsCalledEmpty(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'vuoto.csv', content: '');
		$this->importService->expects($this->never())->method('processUpload');

		$this->service->check('alice');

		$this->assertSame('empty', $this->sent[0][1]['reason']);
	}

	public function testAFileThatIsNotWhatItsNameSaysGetsTheValidatorsOwnReason(): void {
		// The checks an upload gets: their message, not "something went wrong"
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'estratto.ofx', content: "Just a note, not a statement.\n");
		$this->importService->expects($this->never())->method('processUpload');

		$this->service->check('alice');

		// Which check refuses it first (the file's type, or its content)
		// depends on the PHP version's file-type detection; either way the
		// user is given the validator's reason, naming the format
		$this->assertSame('message', $this->sent[0][1]['reason']);
		$this->assertMatchesRegularExpression('/ofx/i', $this->sent[0][1]['message']);
	}

	public function testOneFolderThatCannotBeListedDoesNotStopTheOthers(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Broken', 3, 5), $this->row(2, 'Conto', 3, 5)];
		$this->at('Budget/Import/Broken');
		$this->unlistable = ['/alice/files/Budget/Import/Broken'];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload();

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testANameFilesWillNotCreateIsRefusedWithAReason(): void {
		$this->namesFilesRefuses = ['Conto.'];

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('could not be created');
		$this->service->create('alice', 'Conto.', 3, null);
	}

	public function testASpreadsheetIsReadAsItWasConvertedWhateverTheTemplateSays(): void {
		// A template saved from the bank's semicolon CSV, used for its .xlsx:
		// the converted file is comma-separated UTF-8
		$template = $this->csvTemplate(5);
		$template->setDelimiter(';');
		$template->setEncoding('Windows-1252');
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'estratto.xlsx', content: "PK\x03\x04binary");
		$this->importService->method('processUpload')->willReturn(['fileId' => 'f.csv', 'format' => 'csv', 'sourceAccounts' => [], 'delimiter' => ',']);
		$this->importService->expects($this->once())->method('dataPreview')
			->with('alice', 'f.csv', 'estratto.xlsx', ',', true, null)
			->willReturn(['columns' => ['Data', 'Descrizione', 'Importo']]);
		$this->importService->method('previewImport')->willReturn(['validTransactions' => 1, 'duplicates' => 0]);
		$this->importService->expects($this->once())->method('processImport')
			->with('alice', 'f.csv', $this->anything(), 3, null, true, true, ',', null, null)
			->willReturn(['imported' => 1, 'skipped' => 0, 'errors' => []]);

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testTheTemporaryCopyIsRemovedAfterEachFile(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$temporary = null;
		$this->importService->method('processUpload')->willReturnCallback(function (string $userId, array $upload) use (&$temporary) {
			$temporary = $upload['tmp_name'];
			return ['fileId' => 'f.csv', 'format' => 'csv', 'sourceAccounts' => []];
		});
		$this->importService->method('dataPreview')->willReturn(['columns' => ['Data', 'Descrizione', 'Importo']]);
		$this->importService->method('previewImport')->willReturn(['validTransactions' => 1, 'duplicates' => 0]);
		$this->importService->method('processImport')->willReturn(['imported' => 1, 'skipped' => 0, 'errors' => []]);

		$this->service->check('alice');

		$this->assertNotNull($temporary);
		$this->assertFileDoesNotExist($temporary);
	}

	// ── review mode (#425) ──────────────────────────────────────────

	public function testAReviewFolderHoldsAFileAndSaysItIsReady(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5, mode: 'review')];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->importService->expects($this->never())->method('processUpload');
		$this->importService->expects($this->never())->method('processImport');

		$summary = $this->service->check('alice');

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 1], $summary);
		$this->assertSame('/alice/files/Budget/Import/Conto/Review/ottobre.csv', $this->whereItWent($file));
		$this->assertSame([['import_folder_review', [
			'fileId' => '100', 'fileName' => 'ottobre.csv', 'accountId' => '3', 'accountName' => 'Conto Mario',
		]]], $this->sent);
	}

	public function testAReviewFolderStillRefusesWhatIsNotAStatement(): void {
		$this->rows = [$this->row(1, 'Conto', mode: 'review')];
		$folder = $this->at('Budget/Import/Conto');
		$pdf = $this->fileIn($folder, 'ricevuta.pdf', 1);
		$empty = $this->fileIn($folder, 'vuoto.csv', 2, content: '');

		$this->assertSame(['imported' => 0, 'failed' => 2, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
		$this->assertStringEndsWith('/Failed/ricevuta.pdf', $this->whereItWent($pdf));
		$this->assertStringEndsWith('/Failed/vuoto.csv', $this->whereItWent($empty));
		$this->assertEqualsCanonicalizing(['unsupported_type', 'empty'], array_column(array_column($this->sent, 1), 'reason'));
	}

	public function testAFileAlreadyWaitingIsNotPickedUpAgain(): void {
		// Also what makes switching a folder from review to automatic leave
		// its waiting files alone: only the folder's own files are read
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$waiting = $this->fileIn($this->at('Budget/Import/Conto/Review'), 'settembre.csv', 1);
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
		$this->assertNull($this->whereItWent($waiting));
	}

	public function testAReviewFileThatCannotBeMovedSwitchesTheFolderOff(): void {
		$this->rows = [$this->row(1, 'Conto', mode: 'review')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv', movable: false);
		$this->mapper->expects($this->once())->method('update')->with($this->callback(fn (ImportFolder $row) => !$row->isEnabled()));

		$this->service->check('alice');

		$this->assertSame('not_moved', $this->sent[1][1]['reason']);
		$this->assertSame('0', $this->sent[1][1]['imported']);
	}

	public function testCreateAndUpdateTakeAMode(): void {
		$row = $this->service->create('alice', 'Conto', 3, null, 'review')['folder'];
		$this->assertSame('review', $row->getMode());

		$this->assertSame('auto', $this->service->update('alice', $row->getId(), ['mode' => 'auto'])->getMode());
	}

	public function testAnUnknownModeIsRefused(): void {
		try {
			$this->service->create('alice', 'Conto', 3, null, 'later');
			$this->fail('Accepted an unknown mode');
		} catch (\InvalidArgumentException $e) {
			$this->assertStringContainsString('automatically or held for review', $e->getMessage());
		}
		$this->rows = [$this->row(1, 'Conto')];
		$this->expectException(\InvalidArgumentException::class);
		$this->service->update('alice', 1, ['mode' => '']);
	}

	// ── waiting files ───────────────────────────────────────────────

	public function testListsWhatIsWaitingOldestFirst(): void {
		$this->rows = [$this->row(1, 'Conto', 3, 5, mode: 'review'), $this->row(2, 'Carta', 3, null, false)];
		$this->fileIn($this->at('Budget/Import/Conto/Review'), 'ottobre.csv', 11, 300, 230);
		$this->fileIn($this->at('Budget/Import/Conto/Review'), 'settembre.csv', 12, 900, 150);
		// A switched-off folder's waiting files are still the user's to deal with
		$this->fileIn($this->at('Budget/Import/Carta/Review'), 'carta.ofx', 13, 600, 80);
		// Not waiting: still in the folder itself, or already dealt with
		$this->fileIn($this->at('Budget/Import/Conto'), 'nuovo.csv', 14);
		$this->fileIn($this->at('Budget/Import/Conto/Imported'), 'agosto.csv', 15);

		$this->assertSame([
			['fileId' => 12, 'name' => 'settembre.csv', 'size' => 150, 'modified' => self::NOW - 900, 'folderId' => 1, 'folderName' => 'Conto', 'accountId' => 3, 'templateId' => 5],
			['fileId' => 13, 'name' => 'carta.ofx', 'size' => 80, 'modified' => self::NOW - 600, 'folderId' => 2, 'folderName' => 'Carta', 'accountId' => 3, 'templateId' => null],
			['fileId' => 11, 'name' => 'ottobre.csv', 'size' => 230, 'modified' => self::NOW - 300, 'folderId' => 1, 'folderName' => 'Conto', 'accountId' => 3, 'templateId' => 5],
		], $this->service->listReview('alice'));
	}

	public function testListingCreatesNothingAndSkipsWhatIsNotTheUsersOwn(): void {
		$this->rows = [$this->row(1, 'Never made'), $this->row(2, 'Conto'), $this->row(3, 'Theirs')];
		$this->fileIn($this->at('Budget/Import/Conto/Review'), 'planted.csv', 1, owner: 'mallory');
		$this->fileIn($this->at('Budget/Import/Theirs/Review'), 'x.csv', 2);
		$this->sharedWithAlice = ['/alice/files/Budget/Import/Theirs'];

		$this->assertSame([], $this->service->listReview('alice'));
		$this->assertFalse($this->at('Budget/Import')->nodeExists('Never made'));
	}

	public function testAWaitingFileWhoseFolderRowIsGoneIsNotListed(): void {
		$this->fileIn($this->at('Budget/Import/Conto/Review'), 'ottobre.csv', 11);
		$this->rows = [];

		$this->assertSame([], $this->service->listReview('alice'));
	}

	public function testOpeningAWaitingFileGivesTheUploadResponseAndWhereItBelongs(): void {
		$this->rows = [$this->row(1, 'Conto', 3, 5, mode: 'review')];
		$file = $this->fileIn($this->at('Budget/Import/Conto/Review'), 'ottobre.csv', 11);
		$this->importService->expects($this->once())->method('processUpload')
			->with('alice', $this->callback(fn (array $upload) => $upload['name'] === 'ottobre.csv' && $upload['size'] === strlen(self::CSV)))
			->willReturn(['fileId' => 'import_alice_abc.csv', 'format' => 'csv', 'columns' => ['Data', 'Descrizione', 'Importo']]);

		$this->assertSame([
			'fileId' => 'import_alice_abc.csv',
			'format' => 'csv',
			'columns' => ['Data', 'Descrizione', 'Importo'],
			'review' => ['fileId' => 11, 'folderId' => 1, 'accountId' => 3, 'templateId' => 5],
		], $this->service->openReview('alice', 11));
		$this->assertNull($this->whereItWent($file));
	}

	public function testOnlyAWaitingFileCanBeOpened(): void {
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		// In the folder itself, not in Review: the background's to handle
		$this->fileIn($this->at('Budget/Import/Conto'), 'nuovo.csv', 14);
		$this->importService->expects($this->never())->method('processUpload');

		$this->expectException(DoesNotExistException::class);
		$this->service->openReview('alice', 14);
	}

	public function testAWaitingFileThatCannotBeReadSaysWhy(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto/Review'), 'vuoto.csv', 11, content: '');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('The file is empty.');
		$this->service->openReview('alice', 11);
	}

	public function testFinishingMovesTheFileToImportedOrSkipped(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$imported = $this->fileIn($this->at('Budget/Import/Conto/Review'), 'ottobre.csv', 11);
		$skipped = $this->fileIn($this->at('Budget/Import/Conto/Review'), 'doppio.csv', 12);

		$this->service->finishReview('alice', 11, 'imported');
		$this->service->finishReview('alice', 12, 'skipped');

		$this->assertSame('/alice/files/Budget/Import/Conto/Imported/ottobre.csv', $this->whereItWent($imported));
		$this->assertSame('/alice/files/Budget/Import/Conto/Skipped/doppio.csv', $this->whereItWent($skipped));
	}

	public function testFinishingAFileThatIsNoLongerWaitingSaysSo(): void {
		// The second of two tabs that both had it open
		$this->rows = [$this->row(1, 'Conto')];
		$this->at('Budget/Import/Conto/Review');

		$this->expectException(DoesNotExistException::class);
		$this->service->finishReview('alice', 11, 'imported');
	}

	public function testFinishingTakesOnlyTheTwoOutcomes(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$file = $this->fileIn($this->at('Budget/Import/Conto/Review'), 'ottobre.csv', 11);

		try {
			$this->service->finishReview('alice', 11, 'deleted');
			$this->fail('Accepted an unknown outcome');
		} catch (\InvalidArgumentException) {
			$this->assertNull($this->whereItWent($file));
		}
	}

	public function testAFileThatCannotBeMovedOutOfReviewSaysSo(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto/Review'), 'ottobre.csv', 11, movable: false);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('could not be moved');
		$this->service->finishReview('alice', 11, 'imported');
	}

	// ── a folder that was already there ─────────────────────────────

	public function testCreatingAFolderThatAlreadyHoldsFilesLeavesThemAlone(): void {
		// Naming a folder the user already keeps things in must not file
		// their notes under Failed, nor import an old statement in it
		$folder = $this->at('Budget/Import/Conto');
		$notes = $this->fileIn($folder, 'appunti.pdf', 1);
		$old = $this->fileIn($folder, 'vecchio.csv', 2);
		$this->fileIn($folder, '.nascosto', 3);

		$this->assertSame(2, $this->service->create('alice', 'Conto', 3, null)['leftAlone']);

		$new = $this->fileIn($folder, 'ricevuta.pdf', 4);
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(['imported' => 0, 'failed' => 1, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
		$this->assertNull($this->whereItWent($notes));
		$this->assertNull($this->whereItWent($old));
		$this->assertStringEndsWith('/Failed/ricevuta.pdf', $this->whereItWent($new));
		$this->assertSame(['4'], array_column(array_column($this->sent, 1), 'fileId'));
	}

	public function testAFolderMadeNewHasNothingToLeaveAlone(): void {
		$this->assertSame(0, $this->service->create('alice', 'Conto', 3, null)['leftAlone']);
	}

	public function testAnOldFileMovedInAfterwardsIsStillImported(): void {
		// Moving or copying a file in Files keeps its dates, so they cannot
		// tell a file that was there from one moved in since
		$this->csvTemplate(5);
		$folder = $this->at('Budget/Import/Conto');
		$this->service->create('alice', 'Conto', 3, 5);
		$file = $this->fileIn($folder, 'ottobre.csv', 7, age: 90 * 86400, uploadAge: 90 * 86400);
		$this->givenACsvUpload();

		$this->assertSame(1, $this->service->check('alice')['imported']);
		$this->assertStringEndsWith('/Imported/ottobre.csv', $this->whereItWent($file));
	}

	public function testMovingTheMainFolderLeavesWhatItsSubfoldersHoldAlone(): void {
		$this->rows = [$this->row(1, 'Conto'), $this->row(2, 'Carta')];
		$notes = $this->fileIn($this->at('Banca/Conto'), 'appunti.pdf', 1);

		$this->assertSame(['Conto' => 1], $this->service->leaveExistingAlone('alice', 'Banca'));
		// Looking creates nothing
		$this->assertFalse($this->at('Banca')->nodeExists('Carta'));

		$this->mainFolderSetting = 'Banca';
		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
		$this->assertNull($this->whereItWent($notes));
	}

	public function testAMainFolderThatIsNotThereYetHasNothingToLeaveAlone(): void {
		$this->rows = [$this->row(1, 'Conto')];

		$this->assertSame([], $this->service->leaveExistingAlone('alice', 'Nowhere/Yet'));
		$this->assertFalse($this->root->nodeExists('Nowhere'));
	}

	// ── what is left alone, kept up to date ─────────────────────────

	/** @return array<int, array<int, string>> the stored record: folder id => [file id => marker] */
	private function leftAloneRecord(): array {
		$stored = $this->prefs['budget.import_folder_left_alone'] ?? null;
		return $stored === null ? [] : json_decode($stored, true);
	}

	public function testAFileLeftAloneIsImportedOnceItIsReplaced(): void {
		// A bank export always saved as statement.csv: Files "Replace" and
		// the desktop client write over it and keep its id
		$this->csvTemplate(5);
		$folder = $this->at('Budget/Import/Conto');
		$file = $this->fileIn($folder, 'statement.csv', 2);
		$this->service->create('alice', 'Conto', 3, 5);
		$this->givenACsvUpload();

		$this->assertSame(0, $this->service->check('alice')['imported']);
		$this->assertNull($this->whereItWent($file));

		$this->etags[2] = 'written-over';

		$this->assertSame(1, $this->service->check('alice')['imported']);
		$this->assertStringEndsWith('/Imported/statement.csv', $this->whereItWent($file));
		$this->assertSame([], $this->leftAloneRecord());
	}

	public function testARecordOfIdsAloneIsTakenAsUnchangedUntilTheFileChanges(): void {
		// What the first 3.0 builds stored: the ids, with nothing to tell a
		// changed file by. Read as unchanged, and given the file's marker
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$folder = $this->at('Budget/Import/Conto');
		$file = $this->fileIn($folder, 'statement.csv', 2);
		$this->prefs['budget.import_folder_left_alone'] = '[2,9]';
		$this->givenACsvUpload();

		$this->assertSame(0, $this->service->check('alice')['imported']);
		$this->assertNull($this->whereItWent($file));
		// Every folder was read, so an id in none of them is gone
		$this->assertSame([$folder->getId() => [2 => hash('crc32b', 'etag2')]], $this->leftAloneRecord());

		$this->etags[2] = 'written-over';

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testWhatIsNoLongerInTheFolderIsForgotten(): void {
		$folder = $this->at('Budget/Import/Conto');
		$this->fileIn($folder, 'appunti.pdf', 1);
		$this->fileIn($folder, 'vecchio.csv', 2);
		$this->service->create('alice', 'Conto', 3, null);
		$this->assertSame([$folder->getId() => [1 => hash('crc32b', 'etag1'), 2 => hash('crc32b', 'etag2')]], $this->leftAloneRecord());

		// Moved elsewhere in Files
		$this->children[$folder]->offsetUnset('appunti.pdf');
		$this->service->check('alice');
		$this->assertSame([$folder->getId() => [2 => hash('crc32b', 'etag2')]], $this->leftAloneRecord());

		$this->children[$folder]->offsetUnset('vecchio.csv');
		$this->service->check('alice');
		$this->assertArrayNotHasKey('budget.import_folder_left_alone', $this->prefs);
	}

	public function testASwitchedOffFolderKeepsItsFilesLeftAlone(): void {
		// Not read, so nothing can be told about it: when it is switched
		// back on, what was there is still left where it is
		$folder = $this->at('Budget/Import/Conto');
		$old = $this->fileIn($folder, 'vecchio.csv', 2);
		$this->service->create('alice', 'Conto', 3, null);
		$this->service->update('alice', 1, ['enabled' => false]);
		$this->importService->expects($this->never())->method('processUpload');

		$this->service->check('alice');
		$this->assertSame([$folder->getId() => [2 => hash('crc32b', 'etag2')]], $this->leftAloneRecord());

		$this->service->update('alice', 1, ['enabled' => true]);
		$this->service->check('alice');
		$this->assertNull($this->whereItWent($old));
	}

	public function testTheRecordOfAFolderDeletedInFilesIsDropped(): void {
		$main = $this->at('Budget/Import');
		$folder = $this->at('Budget/Import/Conto');
		$this->fileIn($folder, 'vecchio.csv', 2);
		$this->service->create('alice', 'Conto', 3, null);

		$this->children[$main]->offsetUnset('Conto');
		unset($this->foldersById[$folder->getId()]);
		// Recreated empty, under a new id
		$this->service->check('alice');

		$this->assertSame([], $this->leftAloneRecord());
	}

	public function testRemovingAFolderForgetsWhatItLeftAlone(): void {
		$conto = $this->at('Budget/Import/Conto');
		$carta = $this->at('Budget/Import/Carta');
		$this->fileIn($conto, 'vecchio.csv', 2);
		$this->fileIn($carta, 'appunti.pdf', 3);
		$this->service->create('alice', 'Conto', 3, null);
		$this->service->create('alice', 'Carta', 3, null);

		$this->service->delete('alice', 1);

		$this->assertSame([$carta->getId() => [3 => hash('crc32b', 'etag3')]], $this->leftAloneRecord());
	}

	public function testWhatIsLeftAloneWhileACheckRunsIsKept(): void {
		$conto = $this->at('Budget/Import/Conto');
		$carta = $this->at('Budget/Import/Carta');
		$this->fileIn($conto, 'vecchio.csv', 2);
		$this->fileIn($carta, 'appunti.pdf', 3);
		$this->service->create('alice', 'Conto', 3, null);
		$this->children[$conto]->offsetUnset('vecchio.csv');

		$taken = [];
		$this->locking->method('acquireLock')->willReturnCallback(function (string $path) use (&$taken, $carta): void {
			$taken[] = $path;
			if ($path === 'budget/import-folder-left-alone/alice' && count($taken) === 1) {
				// Carta is added, by another request, after this check read
				// the record and before it writes it back
				$this->service->create('alice', 'Carta', 3, null);
			}
		});
		$this->locking->expects($this->atLeastOnce())->method('releaseLock');

		$this->service->check('alice');

		$this->assertSame([$carta->getId() => [3 => hash('crc32b', 'etag3')]], $this->leftAloneRecord());
	}

	public function testACheckThatCannotGetTheRecordLeavesItAsItIs(): void {
		$folder = $this->at('Budget/Import/Conto');
		$this->fileIn($folder, 'vecchio.csv', 2);
		$this->service->create('alice', 'Conto', 3, null);
		$this->children[$folder]->offsetUnset('vecchio.csv');
		$before = $this->leftAloneRecord();
		$this->locking->method('acquireLock')->willThrowException(new LockedException('budget/import-folder-left-alone/alice'));

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 0], $this->service->check('alice'));
		$this->assertSame($before, $this->leftAloneRecord());
	}

	// ── after a backup is restored ──────────────────────────────────

	public function testARestoredFolderLeavesWhatFilesAlreadyHoldsAlone(): void {
		// The rows come back switched on; what they held on the old server
		// is in Files already, and is not new
		$this->rows = [$this->row(1, 'Conto')];
		$old = $this->fileIn($this->at('Budget/Import/Conto'), 'vecchio.ofx', 2, content: "OFXHEADER:100\n<OFX></OFX>\n");
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertTrue($this->service->leaveRestoredAlone('alice'));
		$this->service->check('alice');

		$this->assertNull($this->whereItWent($old));
	}

	public function testARestoredFolderNotInFilesYetHasNothingToLeaveAlone(): void {
		$this->rows = [$this->row(1, 'Conto')];

		$this->assertTrue($this->service->leaveRestoredAlone('alice'));
		$this->assertArrayNotHasKey('budget.import_folder_left_alone', $this->prefs);
		$this->assertFalse($this->root->nodeExists('Budget'));
	}

	public function testRestoredFoldersAreSwitchedOffWhenFilesCannotBeRead(): void {
		$this->rows = [$this->row(1, 'Conto'), $this->row(2, 'Carta')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'vecchio.csv', 2);
		$this->unlistable = ['/alice/files/Budget/Import/Conto'];

		$this->assertFalse($this->service->leaveRestoredAlone('alice'));
		$this->assertFalse($this->rows[0]->isEnabled());
		$this->assertFalse($this->rows[1]->isEnabled());
	}

	// ── when a file is settled ──────────────────────────────────────

	public function testAFileDatedInTheFutureIsNotHeldBackOnceUploaded(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv', age: -86400, uploadAge: 600);
		$this->givenACsvUpload();

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testAFileDatedInTheFutureWithNoUploadTimeIsNotHeldBack(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv', age: -86400);
		$this->givenACsvUpload();

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testAFileJustUploadedWaitsWhateverDateItCarries(): void {
		// A sync client keeps a file's own date: an old one says nothing
		// about whether the upload has finished
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv', age: 30 * 86400, uploadAge: 30);
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(1, $this->service->check('alice')['waiting']);
	}

	// ── notifications ───────────────────────────────────────────────

	public function testTheNotificationNamesTheFileAsItWasDropped(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto/Imported'), 'ottobre.csv', 50);
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload();

		$this->service->check('alice');

		$this->assertStringEndsWith('/Imported/ottobre-1.csv', $this->whereItWent($file));
		$this->assertSame('ottobre.csv', $this->sent[0][1]['fileName']);
	}

	public function testFinishingAReviewDismissesItsReadyNotification(): void {
		$this->rows = [$this->row(1, 'Conto', mode: 'review')];
		$this->fileIn($this->at('Budget/Import/Conto/Review'), 'ottobre.csv', 11);
		$this->fileIn($this->at('Budget/Import/Conto/Review'), 'doppio.csv', 12);

		$this->service->finishReview('alice', 11, 'imported');
		$this->service->finishReview('alice', 12, 'skipped');

		$this->assertSame([
			['import_folder_review', 'import_folder', '11'],
			['import_folder_review', 'import_folder', '12'],
		], $this->dismissed);
	}

	public function testRemovingAFolderDismissesTheReadyNotificationsOfItsWaitingFiles(): void {
		// Its waiting files drop off the Import page with it
		$this->rows = [$this->row(1, 'Conto', mode: 'review'), $this->row(2, 'Carta', mode: 'review')];
		$this->fileIn($this->at('Budget/Import/Conto/Review'), 'ottobre.csv', 11);
		$this->fileIn($this->at('Budget/Import/Carta/Review'), 'carta.ofx', 13);

		$this->service->delete('alice', 1);

		$this->assertSame([['import_folder_review', 'import_folder', '11']], $this->dismissed);
	}

	// ── the stored upload of a file that is not imported ────────────

	public function testAFileGivenUpOnAfterItsUploadHasTheUploadDeleted(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload(['validTransactions' => 0, 'duplicates' => 0]);
		$this->importService->expects($this->once())->method('discardUpload')->with('alice', 'import_alice_abc.csv');

		$this->assertSame(1, $this->service->check('alice')['failed']);
	}

	public function testAnImportThatThrowsHasTheUploadDeleted(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'estratto.ofx', content: "OFXHEADER:100\n<OFX></OFX>\n");
		$this->givenAnOfxUpload([['accountId' => 'IT60X', 'currency' => 'EUR', 'transactionCount' => 4]]);
		$this->importService->method('processImport')->willThrowException(new \RuntimeException('SQLSTATE'));
		$this->importService->expects($this->once())->method('discardUpload')->with('alice', 'f.ofx');

		$this->assertSame(1, $this->service->check('alice')['failed']);
	}

	public function testAnImportedFileLeavesTheUploadToTheImport(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->givenACsvUpload();
		$this->importService->expects($this->never())->method('discardUpload');

		$this->assertSame(1, $this->service->check('alice')['imported']);
	}

	public function testAFileThatFailsBeforeItsUploadHasNoUploadToDelete(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'ricevuta.pdf');
		$this->importService->expects($this->never())->method('discardUpload');

		$this->assertSame(1, $this->service->check('alice')['failed']);
	}
}
