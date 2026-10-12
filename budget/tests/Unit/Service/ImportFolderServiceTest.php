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
	/** @var \WeakMap<Folder, \ArrayObject> what each in-memory folder holds, by name */
	private \WeakMap $children;
	/** @var \WeakMap<File, string> where each file was moved to */
	private \WeakMap $movedTo;
	/** @var string[] paths of folders that are someone else's, shared with Alice */
	private array $sharedWithAlice = [];

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
		$notifications->method('createNotification')->willReturnCallback(function () {
			$notification = $this->createMock(INotification::class);
			$notification->method('setSubject')->willReturnCallback(function (string $subject, array $parameters = []) use ($notification) {
				$this->sent[] = [$subject, $parameters];
				return $notification;
			});
			$notification->method($this->anything())->willReturnSelf();
			return $notification;
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
			$mail, $factory, $time, $l, $this->createMock(LoggerInterface::class),
		);

		$this->accounts = [$this->account(3, 'Conto Mario')];
	}

	// ── helpers ─────────────────────────────────────────────────────

	private function account(int $id, string $name): Account {
		$account = new Account();
		$account->setId($id);
		$account->setName($name);
		$account->setUserId('alice');
		return $account;
	}

	private function row(int $id, string $name, int $accountId = 3, ?int $templateId = null, bool $enabled = true): ImportFolder {
		$row = new ImportFolder();
		$row->setId($id);
		$row->setUserId('alice');
		$row->setName($name);
		$row->setAccountId($accountId);
		$row->setTemplateId($templateId);
		$row->setMode('auto');
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
		$folder->method('getPath')->willReturn($path);
		$folder->method('getName')->willReturn(basename($path));
		// Alice's own, unless the test says someone shared it with her
		$folder->method('getOwner')->willReturnCallback(fn () => $this->createConfiguredMock(
			\OCP\IUser::class,
			['getUID' => in_array($path, $this->sharedWithAlice, true) ? 'mallory' : 'alice']
		));
		$folder->method('nodeExists')->willReturnCallback(static fn (string $name): bool => isset($children[$name]));
		$folder->method('get')->willReturnCallback(static fn (string $name) => $children[$name]);
		$folder->method('newFolder')->willReturnCallback(function (string $name) use ($children, $path) {
			return $children[$name] = $this->folder($path . '/' . $name);
		});
		$folder->method('getDirectoryListing')->willReturnCallback(static fn (): array => array_values($children->getArrayCopy()));
		return $folder;
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

		$row = $this->service->create('alice', '  Conto Mario ', 3, 5);

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
	 * A file in a folder. $age is seconds since it was last modified.
	 *
	 * @return File&MockObject
	 */
	private function fileIn($folder, string $name, int $id = 100, int $age = 600, int $size = 230, ?string $content = null, string $owner = 'alice', bool $movable = true) {
		$content ??= self::CSV;
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($id);
		// What Files has recorded, which is not always what is there
		$file->method('getSize')->willReturn($size);
		$file->method('getMTime')->willReturn(self::NOW - $age);
		$file->method('getOwner')->willReturn($this->createConfiguredMock(\OCP\IUser::class, ['getUID' => $owner]));
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

		$this->assertSame(['imported' => 1, 'failed' => 0, 'waiting' => 0], $summary);
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

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 1], $this->service->check('alice'));
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

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0], $this->service->check('alice'));
	}

	public function testASubfolderSomeoneElseOwnsIsNeverRead(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Theirs', 3, 5), $this->row(2, 'Conto', 3, 5)];
		$theirs = $this->fileIn($this->at('Budget/Import/Theirs'), 'planted.csv', 1);
		$this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv', 2);
		$this->sharedWithAlice = ['/alice/files/Budget/Import/Theirs'];
		$this->givenACsvUpload();
		$this->importService->expects($this->once())->method('processImport');

		$this->assertSame(['imported' => 1, 'failed' => 0, 'waiting' => 0], $this->service->check('alice'));
		$this->assertNull($this->whereItWent($theirs));
	}

	public function testAnImportFolderSomeoneElseOwnsIsNeverRead(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$this->fileIn($this->at('Budget/Import/Conto'), 'planted.csv');
		$this->sharedWithAlice = ['/alice/files/Budget/Import'];
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0], $this->service->check('alice'));
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

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0], $this->service->check('alice'));
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

		$this->assertSame(['imported' => 0, 'failed' => 1, 'waiting' => 0], $this->service->check('alice'));
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

	public function testAnOfxNeedsNoTemplateAndRoutesEverySourceAccountToTheFoldersAccount(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->fileIn($this->at('Budget/Import/Conto'), 'estratto.ofx');
		$this->importService->method('processUpload')->willReturn([
			'fileId' => 'f.ofx', 'format' => 'ofx',
			'sourceAccounts' => [['accountId' => 'IT60X', 'transactionCount' => 4], ['accountId' => 'IT61Y', 'transactionCount' => 1]],
		]);
		$this->importService->expects($this->never())->method('dataPreview');
		$this->importService->method('previewImport')->willReturn(['accountSummaries' => [['transactionCount' => 4, 'duplicates' => 0], ['transactionCount' => 1, 'duplicates' => 0]]]);
		$this->importService->expects($this->once())->method('processImport')
			->with('alice', 'f.ofx', [], 3, ['IT60X' => 3, 'IT61Y' => 3], true, true, ',', null, null)
			->willReturn(['imported' => 5, 'skipped' => 0, 'errors' => []]);

		$this->assertSame(1, $this->service->check('alice')['imported']);
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

		$this->assertSame(['imported' => 2, 'failed' => 0, 'waiting' => 1], $this->service->check('alice', 2));
	}

	public function testAFileSomeoneElseIsAlreadyImportingIsSkipped(): void {
		$this->csvTemplate(5);
		$this->rows = [$this->row(1, 'Conto', 3, 5)];
		$file = $this->fileIn($this->at('Budget/Import/Conto'), 'ottobre.csv');
		$this->locking->method('acquireLock')->willThrowException(new LockedException('budget/import-folder/100'));
		$this->importService->expects($this->never())->method('processUpload');

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0], $this->service->check('alice'));
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

		$this->assertSame(['imported' => 0, 'failed' => 0, 'waiting' => 0], $this->service->check('alice'));
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
}
