<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\ImportFolder;
use OCA\Budget\Db\ImportFolderMapper;
use OCA\Budget\Db\ImportTemplate;
use OCA\Budget\Db\ImportTemplateMapper;
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
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportFolderServiceTest extends TestCase {
	private const NOW = 1791800000;

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

	public function testListGivesTheMainFolderAndTheRows(): void {
		$this->rows = [$this->row(1, 'Conto')];
		$this->assertSame(['folder' => 'Budget/Import', 'folders' => $this->rows], $this->service->list('alice'));
	}
}
