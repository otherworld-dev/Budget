<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\ImportFolder;
use OCA\Budget\Db\ImportFolderMapper;
use OCA\Budget\Db\ImportTemplate;
use OCA\Budget\Db\ImportTemplateMapper;
use OCA\Budget\Service\Import\FileValidator;
use OCA\Budget\Service\Import\TemplateColumnResolver;
use OCA\Budget\Service\Mail\BudgetMailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Import folders (#425): subfolders of one folder in the user's Files, each
 * tied to one of their accounts and, for CSV and spreadsheet statements, a
 * saved import template. A statement dropped into one is imported in the
 * background by check(), which ImportFolderJob runs and the settings page's
 * "Check now" calls.
 *
 * Only the user's own open accounts: an import runs as the user, not as an
 * account's owner, so it cannot post into an account someone shared with
 * them (the import page leaves those out for the same reason).
 */
class ImportFolderService {
	public const MAIN_FOLDER_KEY = 'import_folder';
	public const DEFAULT_MAIN_FOLDER = 'Budget/Import';
	public const MODE_AUTO = 'auto';
	public const IMPORTED_FOLDER = 'Imported';
	public const FAILED_FOLDER = 'Failed';

	/** Files taken per user in one run; the rest wait for the next */
	public const FILES_PER_RUN = 20;

	private const MAX_NAME_LENGTH = 100;

	public function __construct(
		private ImportFolderMapper $mapper,
		private AccountMapper $accountMapper,
		private ImportTemplateMapper $templateMapper,
		private SettingService $settings,
		private IRootFolder $rootFolder,
		private ImportService $importService,
		private TemplateColumnResolver $resolver,
		private FileValidator $fileValidator,
		private ITempManager $tempManager,
		private ILockingProvider $locking,
		private INotificationManager $notifications,
		private AuditService $audit,
		private BudgetMailService $mail,
		private IFactory $l10nFactory,
		private ITimeFactory $time,
		private IL10N $l,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * A user-supplied main folder as a clean path relative to the user's
	 * Files root. The same rules as the receipts folder: either slash style,
	 * stray separators dropped, nothing that leaves the user's own tree.
	 *
	 * @throws \InvalidArgumentException
	 */
	public static function normalizeMainFolder(string $raw): string {
		try {
			return AttachmentService::normalizeReceiptsFolder($raw);
		} catch (\InvalidArgumentException) {
			throw new \InvalidArgumentException('The import folder must be a path inside your Files');
		}
	}

	/**
	 * The user's main import folder, the default when unset or (should a bad
	 * value ever reach the store) invalid.
	 */
	public function mainFolderFor(string $userId): string {
		$stored = $this->settings->get($userId, self::MAIN_FOLDER_KEY);
		if ($stored === null || trim($stored) === '') {
			return self::DEFAULT_MAIN_FOLDER;
		}
		try {
			return self::normalizeMainFolder($stored);
		} catch (\InvalidArgumentException) {
			return self::DEFAULT_MAIN_FOLDER;
		}
	}

	/**
	 * @return array{folder: string, folders: ImportFolder[]}
	 */
	public function list(string $userId): array {
		return ['folder' => $this->mainFolderFor($userId), 'folders' => $this->mapper->findAll($userId)];
	}

	/**
	 * @throws \InvalidArgumentException With a message for the user
	 */
	public function create(string $userId, string $name, int $accountId, ?int $templateId): ImportFolder {
		$name = $this->validName($name);
		foreach ($this->mapper->findAll($userId) as $existing) {
			if (mb_strtolower((string)$existing->getName()) === mb_strtolower($name)) {
				throw new \InvalidArgumentException($this->l->t('You already have an import folder called %1$s.', [$name]));
			}
		}
		$this->assertOwnOpenAccount($userId, $accountId);
		$this->assertOwnTemplate($userId, $templateId);

		// In Files first: a row whose folder could not be made would never import anything
		$this->childFolder($this->mainFolder($userId), $name);

		$folder = new ImportFolder();
		$folder->setUserId($userId);
		$folder->setName($name);
		$folder->setAccountId($accountId);
		$folder->setTemplateId($templateId);
		$folder->setMode(self::MODE_AUTO);
		$folder->setEnabled(true);
		$folder->setCreatedAt($this->now());
		return $this->mapper->insert($folder);
	}

	/**
	 * @param array<string, mixed> $changes accountId, templateId (null clears it), enabled;
	 *                                      a key left out is not changed
	 * @throws DoesNotExistException
	 * @throws \InvalidArgumentException With a message for the user
	 */
	public function update(string $userId, int $id, array $changes): ImportFolder {
		$folder = $this->mapper->find($id, $userId);

		if (array_key_exists('accountId', $changes)) {
			$accountId = (int)$changes['accountId'];
			$this->assertOwnOpenAccount($userId, $accountId);
			$folder->setAccountId($accountId);
		}
		if (array_key_exists('templateId', $changes)) {
			$templateId = $changes['templateId'] === null ? null : (int)$changes['templateId'];
			$this->assertOwnTemplate($userId, $templateId);
			$folder->setTemplateId($templateId);
		}
		if (array_key_exists('enabled', $changes)) {
			$folder->setEnabled((bool)$changes['enabled']);
		}
		$folder->setUpdatedAt($this->now());
		return $this->mapper->update($folder);
	}

	/**
	 * Removes the row. The folder in Files, and anything in it, is left alone.
	 *
	 * @throws DoesNotExistException
	 */
	public function delete(string $userId, int $id): void {
		$this->mapper->delete($this->mapper->find($id, $userId));
	}

	private function validName(string $raw): string {
		$name = trim($raw);
		if ($name === '' || $name === '.' || $name === '..' || mb_strlen($name) > self::MAX_NAME_LENGTH
			|| preg_match('#[/\\\\\x00-\x1F]#', $name) === 1) {
			throw new \InvalidArgumentException($this->l->t('The folder name must be 1 to 100 characters, with no slashes.'));
		}
		return $name;
	}

	private function assertOwnOpenAccount(string $userId, int $accountId): void {
		if ($this->openAccount($userId, $accountId) === null) {
			throw new \InvalidArgumentException($this->l->t('An import folder needs one of your own open accounts.'));
		}
	}

	private function openAccount(string $userId, int $accountId): ?Account {
		foreach ($this->accountMapper->findOpen($userId) as $account) {
			if ((int)$account->getId() === $accountId) {
				return $account;
			}
		}
		return null;
	}

	private function assertOwnTemplate(string $userId, ?int $templateId): void {
		if ($templateId !== null && $this->template($userId, $templateId) === null) {
			throw new \InvalidArgumentException($this->l->t('That import template no longer exists.'));
		}
	}

	private function template(string $userId, ?int $templateId): ?ImportTemplate {
		if ($templateId === null) {
			return null;
		}
		try {
			return $this->templateMapper->find($templateId, $userId);
		} catch (DoesNotExistException) {
			return null;
		}
	}

	/** The user's main import folder in Files, created if it is not there */
	private function mainFolder(string $userId): Folder {
		$folder = $this->rootFolder->getUserFolder($userId);
		foreach (explode('/', $this->mainFolderFor($userId)) as $segment) {
			$folder = $this->childFolder($folder, $segment);
		}
		return $folder;
	}

	private function childFolder(Folder $parent, string $name): Folder {
		$node = $parent->nodeExists($name) ? $parent->get($name) : $parent->newFolder($name);
		if (!$node instanceof Folder) {
			throw new \InvalidArgumentException($this->l->t('%1$s is a file, so it cannot be used as a folder.', [$name]));
		}
		return $node;
	}

	private function now(): string {
		return date('Y-m-d H:i:s', $this->time->getTime());
	}
}
