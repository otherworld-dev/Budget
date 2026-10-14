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
use OCA\Budget\Exception\ValidationException;
use OCA\Budget\Service\Import\FileValidator;
use OCA\Budget\Service\Import\ImportFolderFailure;
use OCA\Budget\Service\Import\Spreadsheet\SpreadsheetConverter;
use OCA\Budget\Service\Import\TemplateColumnResolver;
use OCA\Budget\Service\Import\TransactionNormalizer;
use OCA\Budget\Service\Mail\BudgetMailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IHomeStorage;
use OCP\Files\InvalidPathException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\IConfig;
use OCP\IL10N;
use OCP\ITempManager;
use OCP\L10N\IFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
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
	public const MODE_REVIEW = 'review';
	public const MODES = [self::MODE_AUTO, self::MODE_REVIEW];
	public const IMPORTED_FOLDER = 'Imported';
	public const FAILED_FOLDER = 'Failed';

	/** Where a review folder's files wait, and where skipped ones go */
	public const REVIEW_FOLDER = 'Review';
	public const SKIPPED_FOLDER = 'Skipped';

	/** Files taken per user in one run; the rest wait for the next */
	public const FILES_PER_RUN = 20;

	private const MAX_NAME_LENGTH = 100;

	/** Waiting files listed at once; more than this is a backlog to work through */
	public const REVIEW_LIST_LIMIT = 100;

	/** A file changed this recently may still be uploading */
	private const SETTLE_SECONDS = 120;

	/**
	 * The user preference recording the files that were already in a
	 * subfolder when Budget started reading it, which it leaves alone: by
	 * subfolder, each file's id and a marker of its etag. In the server's
	 * preferences, not Budget's settings: file ids belong to this server, so
	 * they are not for a backup (a restore records the restored folders'
	 * files afresh), and a reset of Budget's settings must not let Budget
	 * loose on them. Not a lazy preference, which NC 30 does not have; kept
	 * small instead, as check() forgets what has left its folder.
	 */
	private const LEFT_ALONE_KEY = 'import_folder_left_alone';

	/** Tries at the lock on that record, 50 ms apart */
	private const LEFT_ALONE_LOCK_ATTEMPTS = 10;

	/**
	 * The user preference recording the files being read right now: each
	 * file's id, a marker of its etag and when. Set before a file is handed
	 * to the importer and cleared once it is dealt with, so one still there
	 * is a file whose reading stopped the process that read it: PHP running
	 * out of memory or time is a fatal error, which nothing can catch. That
	 * file is set aside, not read again, or it would stop every later run,
	 * for every user after its owner. Written over since, it is read again.
	 */
	private const ATTEMPTS_KEY = 'import_folder_attempts';

	/** Entries kept no longer than this, in case one was never cleared */
	private const ATTEMPT_KEEP_SECONDS = 7 * 86400;

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
		private IConfig $config,
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
	 * A folder that is already there is taken as it is, and whatever it
	 * holds stays where it is: only files added from now on are read.
	 *
	 * @return array{folder: ImportFolder, leftAlone: int} The new row, and how
	 *                                                     many files already in the folder are left alone
	 * @throws \InvalidArgumentException With a message for the user
	 */
	public function create(string $userId, string $name, int $accountId, ?int $templateId, string $mode = self::MODE_AUTO): array {
		$name = $this->validName($name);
		$this->assertMode($mode);
		foreach ($this->mapper->findAll($userId) as $existing) {
			if (mb_strtolower((string)$existing->getName()) === mb_strtolower($name)) {
				throw new \InvalidArgumentException($this->l->t('You already have an import folder called %1$s.', [$name]));
			}
		}
		$this->assertOwnOpenAccount($userId, $accountId);
		$this->assertOwnTemplate($userId, $templateId);

		// In Files first: a row whose folder could not be made would never import anything
		try {
			$main = $this->mainFolder($userId);
			$existing = $this->existingFolder($main, $name, $userId);
			// Before the row exists, so no run can get to them first
			$leftAlone = $existing === null ? 0 : $this->leaveAlone($userId, $existing);
			$this->childFolder($main, $name, $userId);
		} catch (InvalidPathException|NotFoundException|NotPermittedException $e) {
			// A name the storage will not take (Files has rules of its own on
			// top of ours), or a folder that cannot be written to
			throw new \InvalidArgumentException($this->l->t('The folder could not be created in your Files. Try another name.'), 0, $e);
		}

		$folder = new ImportFolder();
		$folder->setUserId($userId);
		$folder->setName($name);
		$folder->setAccountId($accountId);
		$folder->setTemplateId($templateId);
		$folder->setMode($mode);
		$folder->setEnabled(true);
		$folder->setCreatedAt($this->now());
		return ['folder' => $this->mapper->insert($folder), 'leftAlone' => $leftAlone];
	}

	/**
	 * Before the main folder setting moves to $mainFolder: the files already
	 * in the subfolders there are left alone, as they are for a new row.
	 * Nothing is created on the way. A subfolder that cannot be listed is
	 * an error, not skipped: its files would be read once it could be.
	 *
	 * @return array<string, int> How many files are left alone, by the name
	 *                            of each subfolder that holds any
	 */
	public function leaveExistingAlone(string $userId, string $mainFolder): array {
		$main = $this->existingPath($userId, $mainFolder);
		if ($main === null) {
			return [];
		}

		$leftAlone = [];
		foreach ($this->mapper->findAll($userId) as $row) {
			$name = (string)$row->getName();
			$folder = $this->existingFolder($main, $name, $userId);
			$count = $folder === null ? 0 : $this->leaveAlone($userId, $folder);
			if ($count > 0) {
				$leftAlone[$name] = $count;
			}
		}
		return $leftAlone;
	}

	/**
	 * After a backup is restored. Its import folders come back switched on,
	 * but not the record of what they held, which belongs to the server it
	 * was made on: the files in their subfolders now are left alone, as for
	 * a folder added now. A subfolder not in Files yet has nothing to leave
	 * alone; whatever arrives in it later is new. Never throws: the restore
	 * is done by now. If the subfolders cannot be read, the restored folders
	 * are switched off instead, so nothing that was there is imported.
	 *
	 * @return bool False when they had to be switched off
	 */
	public function leaveRestoredAlone(string $userId): bool {
		try {
			if ($this->mapper->findAll($userId) === []) {
				return true;
			}
			$this->leaveExistingAlone($userId, $this->mainFolderFor($userId));
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning("Budget import folder: what the restored folders hold could not be listed for {$userId}, so they are switched off: " . $e->getMessage(), ['app' => Application::APP_ID]);
		}
		try {
			foreach ($this->mapper->findEnabled($userId) as $row) {
				$row->setEnabled(false);
				$row->setUpdatedAt($this->now());
				$this->mapper->update($row);
			}
		} catch (\Throwable $e) {
			$this->logger->error("Budget import folder: the restored folders could not be switched off for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
		}
		return false;
	}

	/**
	 * Mark the files in a folder as there before Budget, so check() leaves
	 * them where they are. By id, not by date: a file moved or copied in
	 * Files keeps its dates, so a statement moved in later would look as old
	 * as these. With its etag: writing over a file (Files' "Replace", the
	 * desktop client) keeps its id, and a bank export always saved under the
	 * same name is a new statement each time.
	 *
	 * @return int How many files that is
	 */
	private function leaveAlone(string $userId, Folder $folder): int {
		$files = [];
		foreach ($this->statements($folder, $userId) as $file) {
			$files[(int)$file->getId()] = self::marker($file);
		}
		if ($files !== []) {
			// What the folder holds now is the whole of it: anything recorded
			// for it before that is not there any more is gone
			$folderId = (int)$folder->getId();
			$this->changeLeftAlone($userId, static function (array $record) use ($folderId, $files): array {
				$record[$folderId] = $files;
				return $record;
			});
		}
		return count($files);
	}

	/**
	 * What tells a file left alone from the same file written over: its
	 * etag, which Files changes whenever the file is written, and not when
	 * it is read, shared or tagged. Hashed short, as it is kept for every
	 * file left alone.
	 */
	private static function marker(File $file): string {
		return hash('crc32b', (string)$file->getEtag());
	}

	/**
	 * The files check() leaves alone, by the folder they are in. Under folder
	 * 0, with no marker, the ids an earlier build recorded on their own: one
	 * of those is taken to be unchanged, and given its folder and marker the
	 * first time check() finds it.
	 *
	 * @param bool $fresh Read past this request's cache, as a change must
	 * @return array<int, array<int, ?string>> folder id => [file id => marker]
	 */
	private function leftAlone(string $userId, bool $fresh = false): array {
		$stored = $fresh
			? ($this->config->getUserValueForUsers(Application::APP_ID, self::LEFT_ALONE_KEY, [$userId])[$userId] ?? '')
			: $this->config->getUserValue($userId, Application::APP_ID, self::LEFT_ALONE_KEY, '');
		$decoded = json_decode((string)$stored, true);
		if (!is_array($decoded)) {
			return [];
		}
		$record = [];
		foreach ($decoded as $key => $value) {
			if (is_int($value)) {
				$record[0][$value] = null;
			} elseif (is_array($value)) {
				foreach ($value as $fileId => $marker) {
					$record[(int)$key][(int)$fileId] = is_string($marker) ? $marker : null;
				}
			}
		}
		return $record;
	}

	/**
	 * Change the record of what is left alone. Under a lock, from what is
	 * stored now rather than what this request read earlier: two changes at
	 * once (a folder added while check() tidies up) each keep the other's.
	 *
	 * @param callable(array<int, array<int, ?string>>): array<int, array<int, ?string>> $change
	 * @throws LockedException If another change holds the record for too long
	 */
	private function changeLeftAlone(string $userId, callable $change): void {
		$this->underLock('budget/import-folder-left-alone/' . $userId, function () use ($userId, $change): void {
			$record = array_filter($change($this->leftAlone($userId, true)));
			if ($record === []) {
				$this->config->deleteUserValue($userId, Application::APP_ID, self::LEFT_ALONE_KEY);
			} else {
				// An object throughout, so a record of one folder is not read back as old ids
				$this->config->setUserValue($userId, Application::APP_ID, self::LEFT_ALONE_KEY, json_encode($record, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));
			}
		});
	}

	/**
	 * Run $work holding an exclusive lock, tried for a little while.
	 *
	 * @throws LockedException If something else holds it for too long
	 */
	private function underLock(string $lock, callable $work): void {
		for ($attempt = 1; ; $attempt++) {
			try {
				$this->locking->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
				break;
			} catch (LockedException $e) {
				if ($attempt >= self::LEFT_ALONE_LOCK_ATTEMPTS) {
					throw $e;
				}
				usleep(50000);
			}
		}
		try {
			$work();
		} finally {
			$this->locking->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * The files being read, as stored now (past this request's cache: the
	 * process that wrote an entry may be another one, and gone).
	 *
	 * @return array<int, array{0: string, 1: int}> file id => [etag marker, when]
	 */
	private function attempts(string $userId): array {
		$stored = $this->config->getUserValueForUsers(Application::APP_ID, self::ATTEMPTS_KEY, [$userId])[$userId] ?? '';
		$decoded = json_decode((string)$stored, true);
		if (!is_array($decoded)) {
			return [];
		}
		$attempts = [];
		foreach ($decoded as $fileId => $entry) {
			if (is_array($entry) && is_string($entry[0] ?? null)) {
				$attempts[(int)$fileId] = [$entry[0], (int)($entry[1] ?? 0)];
			}
		}
		return $attempts;
	}

	/**
	 * @param callable(array<int, array{0: string, 1: int}>): array<int, array{0: string, 1: int}> $change
	 * @throws LockedException
	 */
	private function changeAttempts(string $userId, callable $change): void {
		$this->underLock('budget/import-folder-attempts/' . $userId, function () use ($userId, $change): void {
			$attempts = $change($this->attempts($userId));
			if ($attempts === []) {
				$this->config->deleteUserValue($userId, Application::APP_ID, self::ATTEMPTS_KEY);
			} else {
				// An object of file ids, each entry a pair
				$this->config->setUserValue($userId, Application::APP_ID, self::ATTEMPTS_KEY, json_encode((object)$attempts, JSON_THROW_ON_ERROR));
			}
		});
	}

	/** Whether reading this file, as it is now, stopped the process that last read it */
	private function stoppedOn(string $userId, File $file): bool {
		return ($this->attempts($userId)[(int)$file->getId()][0] ?? null) === self::marker($file);
	}

	/**
	 * Record that this file is about to be read. Stored before a byte of it
	 * is parsed, so it outlives a process the file brings down.
	 *
	 * @return bool False when it could not be recorded: then it is not read
	 */
	private function recordAttempt(string $userId, File $file): bool {
		$fileId = (int)$file->getId();
		$entry = [self::marker($file), $this->time->getTime()];
		$oldest = $entry[1] - self::ATTEMPT_KEEP_SECONDS;
		try {
			$this->changeAttempts($userId, static function (array $attempts) use ($fileId, $entry, $oldest): array {
				$attempts = array_filter($attempts, static fn (array $attempt): bool => $attempt[1] >= $oldest);
				$attempts[$fileId] = $entry;
				return $attempts;
			});
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Budget import folder: ' . $file->getName() . " is left for the next run, as reading it could not be recorded for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
			return false;
		}
	}

	/** The file is dealt with: it no longer counts as being read. Best effort. */
	private function clearAttempt(string $userId, int $fileId): void {
		try {
			if (!isset($this->attempts($userId)[$fileId])) {
				return;
			}
			$this->changeAttempts($userId, static function (array $attempts) use ($fileId): array {
				unset($attempts[$fileId]);
				return $attempts;
			});
		} catch (\Throwable $e) {
			// Left, it sets the file aside if it is ever read again unchanged
			$this->logger->warning("Budget import folder: a file read could not be cleared for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	/**
	 * Which of these users have a file still recorded as being read: most
	 * likely, one that stopped the last run. ImportFolderJob takes them
	 * last, so whatever else such a file does, the others go first.
	 *
	 * @param string[] $userIds
	 * @return string[]
	 */
	public function interrupted(array $userIds): array {
		if ($userIds === []) {
			return [];
		}
		$stored = $this->config->getUserValueForUsers(Application::APP_ID, self::ATTEMPTS_KEY, $userIds);
		return array_values(array_filter($userIds, static fn (string $userId): bool => ($stored[$userId] ?? '') !== ''));
	}

	/**
	 * After check() has read the folders: forget the files left alone that
	 * are no longer in their folder, or were written over (check() has just
	 * taken those as new), give old id-only entries their folder and marker,
	 * and drop the record of a folder that is gone from Files. Only what
	 * check() itself saw is changed, so what is recorded meanwhile stays.
	 * A folder that was not read (switched off, or not listable) keeps its
	 * record: nothing can be told about it. Best effort: tried again on the
	 * next run.
	 *
	 * @param array<int, array<int, ?string>> $before The record check() went by
	 * @param array<int, array<int, string>> $seen Folder id => the files left alone found in it, for each folder read
	 * @param bool $readAll Whether every one of the user's folders was read
	 */
	private function tidyLeftAlone(string $userId, array $before, array $seen, bool $readAll): void {
		$gone = [];
		$adopted = [];
		foreach ($seen as $folderId => $present) {
			foreach ($before[$folderId] ?? [] as $fileId => $marker) {
				if (!array_key_exists($fileId, $present)) {
					$gone[$folderId][$fileId] = $marker;
				}
			}
			foreach ($present as $fileId => $marker) {
				if (!array_key_exists($fileId, $before[$folderId] ?? [])) {
					$adopted[$folderId][$fileId] = $marker;
				}
			}
		}
		$foldersGone = [];
		foreach ($before as $folderId => $files) {
			if ($folderId !== 0 && !isset($seen[$folderId]) && !$this->folderExists($userId, $folderId)) {
				$foldersGone[$folderId] = $files;
			}
		}
		// Old ids that none of the user's folders holds
		$placed = [];
		foreach ($adopted as $files) {
			$placed += $files;
		}
		$unplaced = $readAll ? array_diff_key($before[0] ?? [], $placed) : [];
		if ($gone === [] && $adopted === [] && $foldersGone === [] && $unplaced === []) {
			return;
		}

		try {
			$this->changeLeftAlone($userId, static function (array $record) use ($gone, $adopted, $foldersGone, $unplaced): array {
				foreach ($gone as $folderId => $files) {
					foreach ($files as $fileId => $marker) {
						// Unless it was recorded again since
						if (array_key_exists($fileId, $record[$folderId] ?? []) && $record[$folderId][$fileId] === $marker) {
							unset($record[$folderId][$fileId]);
						}
					}
				}
				foreach ($adopted as $folderId => $files) {
					foreach ($files as $fileId => $marker) {
						if (array_key_exists($fileId, $record[0] ?? [])) {
							unset($record[0][$fileId]);
							$record[$folderId][$fileId] ??= $marker;
						}
					}
				}
				foreach ($foldersGone as $folderId => $files) {
					if (($record[$folderId] ?? null) === $files) {
						unset($record[$folderId]);
					}
				}
				foreach (array_keys($unplaced) as $fileId) {
					unset($record[0][$fileId]);
				}
				return $record;
			});
		} catch (\Throwable $e) {
			$this->logger->warning("Budget import folder: the files left alone could not be tidied up for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	/** Whether a folder is still somewhere in the user's Files */
	private function folderExists(string $userId, int $folderId): bool {
		try {
			return $this->rootFolder->getUserFolder($userId)->getFirstNodeById($folderId) instanceof Folder;
		} catch (\Throwable) {
			// Not known to be gone
			return true;
		}
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
		if (array_key_exists('mode', $changes)) {
			$mode = (string)$changes['mode'];
			$this->assertMode($mode);
			$folder->setMode($mode);
		}
		if (array_key_exists('enabled', $changes)) {
			$folder->setEnabled((bool)$changes['enabled']);
		}
		$folder->setUpdatedAt($this->now());
		return $this->mapper->update($folder);
	}

	/**
	 * Removes the row. The folder in Files, and anything in it, is left alone.
	 * Its waiting files drop off the Import page, so the notifications that
	 * point there go, and so does the record of the files it left alone: a
	 * folder added again under that name records them afresh.
	 *
	 * @throws DoesNotExistException
	 */
	public function delete(string $userId, int $id): void {
		$row = $this->mapper->find($id, $userId);
		try {
			$waiting = array_filter($this->waiting($userId), static fn (array $entry): bool => (int)$entry['row']->getId() === $id);
		} catch (\Throwable $e) {
			// Files cannot be read: the row goes all the same
			$this->logger->warning("Budget import folder: the waiting files of {$row->getName()} could not be listed for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
			$waiting = [];
		}
		$this->mapper->delete($row);
		foreach ($waiting as ['file' => $file]) {
			$this->dismissReview($userId, (int)$file->getId());
		}
		$this->forgetLeftAlone($userId, (string)$row->getName());
	}

	/** Drop the record of the files a subfolder left alone, once it is no import folder */
	private function forgetLeftAlone(string $userId, string $name): void {
		try {
			$main = $this->existingPath($userId, $this->mainFolderFor($userId));
			$folder = $main === null ? null : $this->existingFolder($main, $name, $userId);
			$folderId = $folder === null ? null : (int)$folder->getId();
			if ($folderId === null || !isset($this->leftAlone($userId)[$folderId])) {
				return;
			}
			$this->changeLeftAlone($userId, static function (array $record) use ($folderId): array {
				unset($record[$folderId]);
				return $record;
			});
		} catch (\Throwable $e) {
			// Kept, which only costs a little room
			$this->logger->warning("Budget import folder: the files {$name} left alone could not be forgotten for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	/**
	 * Import what is waiting in the user's switched-on folders.
	 *
	 * @return array{imported: int, failed: int, waiting: int, review: int} Files, not
	 *                                                                      transactions: waiting ones are still uploading or over this run's
	 *                                                                      limit, review ones were held for a look first
	 */
	public function check(string $userId, int $limit = self::FILES_PER_RUN): array {
		$summary = ['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 0];
		$rows = $this->mapper->findEnabled($userId);
		if ($rows === []) {
			return $summary;
		}

		try {
			$main = $this->mainFolder($userId);
		} catch (\InvalidArgumentException $e) {
			$this->logger->warning("Budget import folder: nothing read for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
			return $summary;
		}
		$taken = 0;
		$leftAlone = $this->leftAlone($userId);
		// The files left alone found where they were left, unchanged, by folder
		$seen = [];
		foreach ($rows as $row) {
			try {
				// Recreated if it was deleted or renamed in Files
				$folder = $this->childFolder($main, (string)$row->getName(), $userId);
				$files = $this->statements($folder, $userId);
			} catch (\Throwable $e) {
				// Not the user's own, a file has its name, or the storage will
				// not list it right now: skipped, so one folder that cannot be
				// read does not stop the others
				$this->logger->warning("Budget import folder: {$row->getName()} skipped for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
				continue;
			}
			$folderId = (int)$folder->getId();
			$seen[$folderId] ??= [];
			foreach ($files as $file) {
				$fileId = (int)$file->getId();
				$marker = self::marker($file);
				// Written over since, it is a new statement
				if (($leftAlone[$folderId][$fileId] ?? null) === $marker || array_key_exists($fileId, $leftAlone[0] ?? [])) {
					$seen[$folderId][$fileId] = $marker;
					continue;
				}
				if ($taken >= $limit || !$this->settled($file)) {
					$summary['waiting']++;
					continue;
				}
				$outcome = $this->handle($userId, $row, $folder, $file);
				if ($outcome !== null) {
					$summary[$outcome]++;
					$taken++;
				}
			}
		}
		if ($leftAlone !== []) {
			// Ids recorded on their own are only known to be gone once every folder was read
			$readAll = ($leftAlone[0] ?? []) !== [] && count($seen) === count($this->mapper->findAll($userId));
			$this->tidyLeftAlone($userId, $leftAlone, $seen, $readAll);
		}
		return $summary;
	}

	/**
	 * Whether a file is done uploading: nothing has changed it for a while.
	 * Its upload time where Files has one, and its modified time, the later
	 * of the two. A modified time still to come (a client's clock, or a date
	 * carried over from elsewhere) would hold the file back until that day,
	 * so it is not counted; with nothing else to go on, the file is settled
	 * once that time is far enough from now either way.
	 */
	private function settled(File $file): bool {
		$now = $this->time->getTime();
		$modified = (int)$file->getMTime();
		$times = array_filter([$modified, $file->getUploadTime()], static fn (int $time): bool => $time > 0 && $time <= $now);
		if ($times === []) {
			return abs($now - $modified) >= self::SETTLE_SECONDS;
		}
		return $now - max($times) >= self::SETTLE_SECONDS;
	}

	/**
	 * The files waiting in the user's Review folders, oldest first.
	 *
	 * @return list<array{fileId: int, name: string, size: int, modified: int, folderId: int, folderName: string, accountId: int, templateId: ?int}>
	 */
	public function listReview(string $userId): array {
		$waiting = $this->waiting($userId);
		usort($waiting, static fn (array $a, array $b): int => $a['file']->getMTime() <=> $b['file']->getMTime());

		$list = [];
		foreach (array_slice($waiting, 0, self::REVIEW_LIST_LIMIT) as ['file' => $file, 'row' => $row]) {
			$list[] = [
				'fileId' => (int)$file->getId(),
				'name' => $file->getName(),
				'size' => (int)$file->getSize(),
				'modified' => (int)$file->getMTime(),
				'folderId' => (int)$row->getId(),
				'folderName' => (string)$row->getName(),
				'accountId' => (int)$row->getAccountId(),
				'templateId' => $row->getTemplateId(),
			];
		}
		return $list;
	}

	/**
	 * Read a waiting file in as an upload, for the import wizard. The file
	 * stays where it is until finishReview().
	 *
	 * @return array<string, mixed> The upload response, plus 'review': the
	 *                              file and the folder it belongs to
	 * @throws DoesNotExistException If the file is not one of the user's waiting files
	 * @throws \InvalidArgumentException|ValidationException With a message for the user
	 */
	public function openReview(string $userId, int $fileId): array {
		['file' => $file, 'row' => $row, 'folder' => $folder] = $this->waitingFile($userId, $fileId);
		if ($this->stoppedOn($userId, $file)) {
			// Opening it last time stopped the server: not read again
			$moved = $this->setAside($userId, $folder, $file);
			throw new \InvalidArgumentException($moved
				? $this->l->t('Budget stopped while reading this file last time, so it has been moved to the Failed folder. Try downloading the statement from your bank again.')
				: $this->l->t('Budget stopped while reading this file last time. Move it out of the Review folder, and try downloading the statement from your bank again.'));
		}
		// Read here, on the server, as the job reads one: recorded the same way
		$recorded = $this->recordAttempt($userId, $file);
		try {
			['content' => $content, 'extension' => $extension] = $this->statementBytes($file);
			$upload = $this->upload($userId, $file->getName(), $extension, $content);
		} catch (ImportFolderFailure $e) {
			throw new \InvalidArgumentException(match ($e->reason) {
				'unsupported_type' => $this->l->t('This type of file cannot be imported.'),
				'too_large' => $this->l->t('The file is larger than 10 MB.'),
				'empty' => $this->l->t('The file is empty.'),
				default => (string)($e->details['message'] ?? $this->l->t('The file could not be read.')),
			}, 0, $e);
		} finally {
			if ($recorded) {
				$this->clearAttempt($userId, $fileId);
			}
		}

		return $upload + ['review' => [
			'fileId' => $fileId,
			'folderId' => (int)$row->getId(),
			'accountId' => (int)$row->getAccountId(),
			'templateId' => $row->getTemplateId(),
		]];
	}

	/**
	 * Move a waiting file out of Review: to Imported once the wizard has
	 * imported it, or to Skipped. The caller is trusted that the import
	 * happened: a wrong call only files one of the user's own statements
	 * under Imported, which they could do by dragging it there.
	 *
	 * @param 'imported'|'skipped' $outcome
	 * @throws DoesNotExistException If the file is not one of the user's waiting files
	 * @throws \InvalidArgumentException With a message for the user
	 */
	public function finishReview(string $userId, int $fileId, string $outcome): void {
		$target = match ($outcome) {
			'imported' => self::IMPORTED_FOLDER,
			'skipped' => self::SKIPPED_FOLDER,
			default => throw new \InvalidArgumentException($this->l->t('A waiting file is either imported or skipped.')),
		};
		['file' => $file, 'folder' => $folder] = $this->waitingFile($userId, $fileId);

		if (!$this->moveInto($userId, $folder, $file, $target)) {
			throw new \InvalidArgumentException($this->l->t('The file could not be moved. Move it out of the Review folder yourself.'));
		}
		$this->dismissReview($userId, $fileId);
	}

	/**
	 * A waiting file whose reading stopped the server: into Failed, unread,
	 * and the user told why.
	 *
	 * @return bool Whether it could be moved
	 */
	private function setAside(string $userId, Folder $folder, File $file): bool {
		$name = $file->getName();
		$fileId = (int)$file->getId();
		$this->logger->warning('Budget import folder: ' . $name . " stopped the server while it was read, so it is set aside for {$userId}", ['app' => Application::APP_ID]);
		$this->audit->logImportFailed($userId, $name, 'stopped');
		$moved = $this->moveInto($userId, $folder, $file, self::FAILED_FOLDER);
		$this->dismissReview($userId, $fileId);
		$this->notify($userId, 'import_folder_failed', $file, $name, ['reason' => 'stopped', 'moved' => $moved ? '1' : '0']);
		$this->clearAttempt($userId, $fileId);
		return $moved;
	}

	/** The "ready to review" notification of a file that is no longer waiting */
	private function dismissReview(string $userId, int $fileId): void {
		try {
			$notification = $this->notifications->createNotification();
			$notification->setApp(Application::APP_ID)
				->setUser($userId)
				->setObject('import_folder', (string)$fileId)
				->setSubject('import_folder_review');
			$this->notifications->markProcessed($notification);
		} catch (\Throwable $e) {
			$this->logger->warning("Budget import folder: a notification could not be dismissed for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
		}
	}

	/**
	 * @return array{file: File, row: ImportFolder, folder: Folder}
	 * @throws DoesNotExistException
	 */
	private function waitingFile(string $userId, int $fileId): array {
		foreach ($this->waiting($userId) as $entry) {
			if ((int)$entry['file']->getId() === $fileId) {
				return $entry;
			}
		}
		throw new DoesNotExistException('Not a waiting file');
	}

	/**
	 * Every file in the Review folder of one of the user's subfolders, with
	 * the row and subfolder it belongs to. Whatever the row's mode or state:
	 * a folder switched to automatic, or switched off, still has what it
	 * held. Nothing is created on the way, and only what is the user's own
	 * is looked at.
	 *
	 * @return list<array{file: File, row: ImportFolder, folder: Folder}>
	 */
	private function waiting(string $userId): array {
		$main = $this->existingPath($userId, $this->mainFolderFor($userId));
		if ($main === null) {
			return [];
		}

		$waiting = [];
		foreach ($this->mapper->findAll($userId) as $row) {
			try {
				$folder = $this->existingFolder($main, (string)$row->getName(), $userId);
				$review = $folder === null ? null : $this->existingFolder($folder, self::REVIEW_FOLDER, $userId);
				if ($folder === null || $review === null) {
					continue;
				}
				foreach ($review->getDirectoryListing() as $node) {
					if ($node instanceof File && $this->isOwn($node, $userId)) {
						$waiting[] = ['file' => $node, 'row' => $row, 'folder' => $folder];
					}
				}
			} catch (\Throwable $e) {
				$this->logger->warning("Budget import folder: {$row->getName()}'s waiting files could not be listed for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
			}
		}
		return $waiting;
	}

	/** The folder at a path in the user's Files, if it is there and every step is theirs. Never creates one. */
	private function existingPath(string $userId, string $path): ?Folder {
		$folder = $this->rootFolder->getUserFolder($userId);
		foreach (explode('/', $path) as $segment) {
			$folder = $this->existingFolder($folder, $segment, $userId);
			if ($folder === null) {
				return null;
			}
		}
		return $folder;
	}

	/** A folder that is already there and the user's own, or null. Never creates one. */
	private function existingFolder(Folder $parent, string $name, string $userId): ?Folder {
		if (!$parent->nodeExists($name)) {
			return null;
		}
		$node = $parent->get($name);
		return $node instanceof Folder && $this->isOwn($node, $userId) ? $node : null;
	}

	/**
	 * The files directly in a folder that could be statements, oldest first.
	 * Subfolders (Imported, Failed) are never read, nor are hidden files,
	 * Office lock files or partial uploads.
	 *
	 * @return File[]
	 */
	private function statements(Folder $folder, string $userId): array {
		$files = [];
		foreach ($folder->getDirectoryListing() as $node) {
			if (!$node instanceof File) {
				continue;
			}
			$name = $node->getName();
			if (str_starts_with($name, '.') || str_starts_with($name, '~$')
				|| str_ends_with($name, '.part') || str_ends_with($name, '.tmp')) {
				continue;
			}
			// A file someone shared with the user, sitting in their folder,
			// is still the sharer's: not the user's statement to import
			if (!$this->isOwn($node, $userId)) {
				continue;
			}
			$files[] = $node;
		}
		usort($files, static fn (File $a, File $b): int => $a->getMTime() <=> $b->getMTime());
		return $files;
	}

	/**
	 * Import one file, move it out of the way and tell the user.
	 *
	 * @return 'imported'|'failed'|'review'|null Null when another run already has the file
	 */
	private function handle(string $userId, ImportFolder $row, Folder $folder, File $file): ?string {
		// The job and "Check now" can overlap; whoever holds this imports the file
		$lock = 'budget/import-folder/' . $file->getId();
		try {
			$this->locking->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return null;
		}

		// The name it was dropped under, for the notification: it may be
		// renamed on its way into Imported or Failed
		$name = $file->getName();
		$fileId = (int)$file->getId();
		// Whether this file is recorded as being read, by this run or by one it stopped
		$recorded = false;
		try {
			// The folder was listed before this file's turn came. Another run
			// may have imported and moved it since, or the user taken it away:
			// that is not a failure, and nothing to switch the folder off for
			if (!$this->stillThere($folder, $file)) {
				return null;
			}

			$review = ($row->getMode() ?? self::MODE_AUTO) === self::MODE_REVIEW;

			$failure = null;
			$result = null;
			if ($this->stoppedOn($userId, $file)) {
				// Reading it stopped the run that last tried, and would stop
				// this one: set aside unread. Whatever the folder's mode now
				$recorded = true;
				$this->logger->warning('Budget import folder: ' . $name . " stopped the run that read it, so it is set aside for {$userId}", ['app' => Application::APP_ID]);
				$failure = ['reason' => 'stopped'];
			} elseif (!$review && !$this->recordAttempt($userId, $file)) {
				return null;
			} else {
				$recorded = !$review;
				try {
					// A review folder's files are not read as statements here:
					// that happens in the wizard, in front of the user
					$result = $review ? $this->admit($userId, $row, $file) : $this->import($userId, $row, $file);
				} catch (ImportFolderFailure $e) {
					$failure = ['reason' => $e->reason] + $e->details;
				} catch (ValidationException $e) {
					// Written for the user (a spreadsheet that cannot be read, say)
					$failure = ['reason' => 'message', 'message' => $e->getMessage()];
				} catch (\Throwable $e) {
					$this->logger->error('Budget import folder: ' . $name . ' could not be imported: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
					$failure = ['reason' => 'error'];
				}
			}

			if ($failure !== null) {
				$this->audit->logImportFailed($userId, $name, $failure['message'] ?? $failure['reason']);
			}

			$target = match (true) {
				$failure !== null => self::FAILED_FOLDER,
				$review => self::REVIEW_FOLDER,
				default => self::IMPORTED_FOLDER,
			};
			$moved = $this->moveInto($userId, $folder, $file, $target);

			if ($result !== null) {
				$this->notify($userId, $review ? 'import_folder_review' : 'import_folder_done', $file, $name, $result);
			} else {
				$this->notify($userId, 'import_folder_failed', $file, $name, ($failure ?? []) + ['moved' => $moved ? '1' : '0']);
			}

			if (!$moved) {
				// Left where it is, it would be handled again on every run:
				// duplicate detection would stop double entries, not the notifications
				$row->setEnabled(false);
				$row->setUpdatedAt($this->now());
				$this->mapper->update($row);
				// Said every time: a folder switched off without a word just stops working
				$this->notify($userId, 'import_folder_failed', $file, $name, [
					'reason' => 'not_moved',
					'moved' => '0',
					'imported' => $result !== null && !$review ? '1' : '0',
				]);
			}

			return match (true) {
				$failure !== null => 'failed',
				$review => 'review',
				default => 'imported',
			};
		} finally {
			// Read to the end, whatever came of it: only a run that died keeps it
			if ($recorded) {
				$this->clearAttempt($userId, $fileId);
			}
			$this->locking->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * What a review folder checks before holding a file: that it could be a
	 * statement at all, and that there is still an account for it.
	 *
	 * @return array<string, string> The parameters of the "ready to review" notification
	 * @throws ImportFolderFailure
	 */
	private function admit(string $userId, ImportFolder $row, File $file): array {
		$this->statementBytes($file);
		$accountId = (int)$row->getAccountId();
		$account = $this->openAccount($userId, $accountId) ?? throw new ImportFolderFailure('account_unavailable');

		return ['accountId' => (string)$accountId, 'accountName' => (string)$account->getName()];
	}

	/**
	 * A file's contents, once it has passed what every dropped file must:
	 * a statement's type, within the size limit, not empty.
	 *
	 * @return array{content: string, extension: string}
	 * @throws ImportFolderFailure
	 */
	private function statementBytes(File $file): array {
		$extension = strtolower(pathinfo($file->getName(), PATHINFO_EXTENSION));
		if (!in_array($extension, $this->fileValidator->getAllowedExtensions(), true)) {
			throw new ImportFolderFailure('unsupported_type');
		}
		if ($file->getSize() > $this->fileValidator->getMaxFileSize()) {
			throw new ImportFolderFailure('too_large');
		}
		$content = $this->read($file);
		if ($content === '') {
			throw new ImportFolderFailure('empty');
		}
		return ['content' => $content, 'extension' => $extension];
	}

	/**
	 * Hand a statement's bytes to the importer as an upload, after the
	 * checks an upload gets, and return its upload response.
	 *
	 * @return array<string, mixed>
	 * @throws ImportFolderFailure
	 * @throws ValidationException
	 */
	private function upload(string $userId, string $name, string $extension, string $content): array {
		$temporary = $this->tempManager->getTemporaryFile('.' . $extension);
		if ($temporary === false || file_put_contents($temporary, $content) === false) {
			throw new \RuntimeException('No temporary file for ' . $name);
		}
		try {
			try {
				// Run here so the validator's reasons (a file that is not what
				// its name says) reach the user instead of "something went wrong"
				$this->fileValidator->validate($name, strlen($content), $temporary);
			} catch (\Exception $e) {
				throw new ImportFolderFailure('message', ['message' => $e->getMessage()]);
			}
			return $this->importService->processUpload($userId, ['name' => $name, 'tmp_name' => $temporary, 'size' => strlen($content)]);
		} finally {
			// One run can take many files of up to 10 MB: not left for the end of the process
			if (is_file($temporary)) {
				unlink($temporary);
			}
		}
	}

	/**
	 * @return array<string, string> The parameters of the "imported" notification
	 * @throws ImportFolderFailure
	 * @throws ValidationException
	 */
	private function import(string $userId, ImportFolder $row, File $file): array {
		$name = $file->getName();
		['content' => $content, 'extension' => $extension] = $this->statementBytes($file);
		$accountId = (int)$row->getAccountId();
		$account = $this->openAccount($userId, $accountId) ?? throw new ImportFolderFailure('account_unavailable');
		$upload = $this->upload($userId, $name, $extension, $content);
		$fileId = (string)$upload['fileId'];
		try {
			return $this->importUpload($userId, $row, $account, $name, $fileId, $upload);
		} catch (\Throwable $e) {
			// processImport() deletes it once imported. Given up on here,
			// nothing else would until the cleanup job, a day later
			$this->importService->discardUpload($userId, $fileId);
			throw $e;
		}
	}

	/**
	 * The rest of import(), once the file is stored as an upload.
	 *
	 * @param array<string, mixed> $upload
	 * @return array<string, string>
	 * @throws ImportFolderFailure
	 * @throws ValidationException
	 */
	private function importUpload(string $userId, ImportFolder $row, Account $account, string $name, string $fileId, array $upload): array {
		$accountId = (int)$account->getId();
		$format = (string)$upload['format'];

		$template = $this->template($userId, $row->getTemplateId());
		$arguments = $format === 'csv'
			? $this->csvArguments($userId, $fileId, $name, $template, $upload)
			: $this->statementArguments($upload, $format, $template, $account);

		$preview = $this->importService->previewImport(
			$userId, $fileId, $arguments['mapping'], $accountId, $arguments['accountMapping'],
			$arguments['skipDuplicates'], $arguments['delimiter'], null, $arguments['encoding'], $arguments['applyRules']
		);
		foreach ($preview['directionWarnings'] ?? [] as $warning) {
			// Nearly every row heading the opposite way to the account's
			// history: almost always the wrong template, and there is no
			// undoing an import
			if (($warning['kind'] ?? '') === 'direction') {
				throw new ImportFolderFailure('wrong_direction');
			}
		}
		$summaries = $preview['accountSummaries'] ?? [];
		$valid = $summaries !== [] ? array_sum(array_column($summaries, 'transactionCount')) : (int)($preview['validTransactions'] ?? 0);
		$duplicates = $summaries !== [] ? array_sum(array_column($summaries, 'duplicates')) : (int)($preview['duplicates'] ?? 0);
		if ($valid === 0 && $duplicates === 0) {
			throw new ImportFolderFailure('nothing_read');
		}

		$this->audit->logImportStarted($userId, $name, $format);
		$result = $this->importService->processImport(
			$userId, $fileId, $arguments['mapping'], $accountId, $arguments['accountMapping'],
			$arguments['skipDuplicates'], $arguments['applyRules'], $arguments['delimiter'], null, $arguments['encoding']
		);
		$imported = (int)($result['imported'] ?? 0);
		$skipped = (int)($result['skipped'] ?? 0);
		$this->audit->logImportCompleted($userId, $accountId, $imported, $skipped);

		return [
			'accountId' => (string)$accountId,
			'accountName' => (string)$account->getName(),
			'imported' => (string)$imported,
			'skipped' => (string)$skipped,
			'errors' => (string)count($result['errors'] ?? []),
		];
	}

	/**
	 * How a CSV (or a spreadsheet, which arrives as one) is read: by the
	 * folder's template, its columns matched against this file's header row.
	 *
	 * @return array{mapping: array<string, mixed>, accountMapping: null, skipDuplicates: bool, applyRules: bool, delimiter: string, encoding: ?string}
	 * @throws ImportFolderFailure
	 */
	private function csvArguments(string $userId, string $fileId, string $name, ?ImportTemplate $template, array $upload): array {
		if ($template === null || ($template->getFormat() ?? 'csv') !== 'csv') {
			throw new ImportFolderFailure('no_template');
		}
		$stored = $template->getParsedMapping();
		// An account or currency column would send each row to whichever
		// account its cell names, creating it if need be. A folder imports
		// into its own account, whatever the file (which may come from
		// someone the folder is shared with) says
		unset($stored['account'], $stored['currency']);
		$delimiter = $template->getDelimiter() ?: ',';
		if ($delimiter === '\\t') {
			$delimiter = "\t";
		}
		$encoding = $template->getEncoding() ?: null;
		if (SpreadsheetConverter::handles($name)) {
			// What was stored is the converter's CSV (comma-separated UTF-8),
			// or the text file a mis-named "spreadsheet" really was: read as
			// the upload found it, not as the template's own files are
			$delimiter = (string)($upload['delimiter'] ?? ',') ?: ',';
			$encoding = null;
		}
		$hasHeaderRow = (bool)($stored['skipFirstRow'] ?? $template->getSkipFirstRow() ?? true);
		$applyRules = (bool)($stored['applyRules'] ?? $template->getApplyRules() ?? true);

		$sheet = $this->importService->dataPreview($userId, $fileId, $name, $delimiter, $hasHeaderRow, $encoding);
		// A blank or repeated header is stored under its "Column: N" label, in
		// the language the template was saved in: the user's, or English
		$userLanguage = $this->l10nFactory->get(Application::APP_ID, $this->mail->getUserLanguage($userId));
		$resolved = $this->resolver->resolve(
			$stored,
			array_map('strval', $sheet['columns'] ?? []),
			array_values(array_unique([$userLanguage->t('Column: {number}'), 'Column: {number}']))
		);
		if ($resolved['missing'] !== []) {
			throw new ImportFolderFailure('columns_missing', ['columns' => implode(', ', $resolved['missing'])]);
		}

		return [
			'mapping' => TransactionNormalizer::normalizeMapping($resolved['mapping']) + ['skipFirstRow' => $hasHeaderRow, 'applyRules' => $applyRules],
			'accountMapping' => null,
			'skipDuplicates' => $template->getSkipDuplicates() ?? true,
			'applyRules' => $applyRules,
			'delimiter' => $delimiter,
			'encoding' => $encoding,
		];
	}

	/**
	 * How an OFX, QIF or camt statement is read. Those name their own
	 * accounts: one goes to the folder's account. A statement of several
	 * (a current account and a card, say) is not for one account, nor is one
	 * in another currency: those are imported from the Import page, where
	 * each can be given its own. A template of the same format supplies
	 * which text goes where; without one, the defaults.
	 *
	 * @param array<string, mixed> $upload
	 * @return array{mapping: array<string, mixed>, accountMapping: ?array<string, int>, skipDuplicates: bool, applyRules: bool, delimiter: string, encoding: ?string}
	 * @throws ImportFolderFailure
	 */
	private function statementArguments(array $upload, string $format, ?ImportTemplate $template, Account $account): array {
		$usable = $template !== null && $template->getFormat() === $format ? $template : null;
		$accountId = (int)$account->getId();
		/** @var list<array<string, mixed>> $sources */
		$sources = $upload['sourceAccounts'] ?? [];
		// What holds no transactions does not count: an OFX of every account
		// at the bank names the ones with nothing new too. Nor does the same
		// account named again: a camt file has a statement for each day
		$active = array_filter($sources, static fn (array $source): bool => (int)($source['transactionCount'] ?? 1) > 0);
		$named = array_unique(array_map(static fn (array $source): string => (string)($source['accountId'] ?? ''), $active));
		if (count($named) > 1) {
			throw new ImportFolderFailure('several_accounts');
		}
		$accountCurrency = strtoupper(trim((string)$account->getCurrency()));
		foreach ($active as $source) {
			// Unknown (QIF never says) is taken to be the account's own
			$currency = strtoupper(trim((string)($source['currency'] ?? '')));
			if ($currency !== '' && $accountCurrency !== '' && $currency !== $accountCurrency) {
				throw new ImportFolderFailure('other_currency', ['currency' => $currency, 'accountCurrency' => $accountCurrency]);
			}
		}

		// Only those: an import remembers where each account went, and the
		// empty ones did not go to this account. With nothing in any of them,
		// all are taken, and nothing is read
		$accountMapping = [];
		foreach ($active ?: $sources as $source) {
			if (isset($source['accountId'])) {
				$accountMapping[(string)$source['accountId']] = $accountId;
			}
		}

		return [
			'mapping' => $usable !== null ? TransactionNormalizer::normalizeMapping($usable->getParsedMapping()) : [],
			'accountMapping' => $accountMapping !== [] ? $accountMapping : null,
			'skipDuplicates' => $usable?->getSkipDuplicates() ?? true,
			'applyRules' => $usable?->getApplyRules() ?? true,
			'delimiter' => ',',
			'encoding' => null,
		];
	}

	/**
	 * Move a handled file into the folder's Imported or Failed subfolder,
	 * under a new name if that one is taken.
	 */
	private function moveInto(string $userId, Folder $folder, File $file, string $subfolder): bool {
		try {
			$target = $this->childFolder($folder, $subfolder, $userId);
			$file->move($target->getPath() . '/' . $this->freeName($target, $file->getName()));
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Budget import folder: could not move ' . $file->getName() . ': ' . $e->getMessage(), ['app' => Application::APP_ID]);
			return false;
		}
	}

	private function freeName(Folder $folder, string $name): string {
		if (!$folder->nodeExists($name)) {
			return $name;
		}
		$dot = strrpos($name, '.');
		$base = $dot === false ? $name : substr($name, 0, $dot);
		$extension = $dot === false ? '' : substr($name, $dot);
		for ($i = 1; $i < 100; $i++) {
			if (!$folder->nodeExists($base . '-' . $i . $extension)) {
				return $base . '-' . $i . $extension;
			}
		}
		return $base . '-' . $this->time->getTime() . $extension;
	}

	/**
	 * @param array<string, string> $parameters
	 */
	private function notify(string $userId, string $subject, File $file, string $name, array $parameters): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime($this->time->getDateTime())
			->setObject('import_folder', (string)$file->getId())
			->setSubject($subject, ['fileId' => (string)$file->getId(), 'fileName' => $name] + $parameters);
		$this->notifications->notify($notification);
	}

	private function assertMode(string $mode): void {
		if (!in_array($mode, self::MODES, true)) {
			throw new \InvalidArgumentException($this->l->t('A folder\'s files are either imported automatically or held for review.'));
		}
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

	/**
	 * The user's main import folder in Files, created if it is not there.
	 *
	 * @throws \InvalidArgumentException If it is not the user's own
	 */
	private function mainFolder(string $userId): Folder {
		// The user's Files root is theirs; every step down from it is checked
		// before the next one is taken, so nothing is ever created inside a
		// folder that is someone else's
		$folder = $this->rootFolder->getUserFolder($userId);
		foreach (explode('/', $this->mainFolderFor($userId)) as $segment) {
			$folder = $this->childFolder($folder, $segment, $userId);
		}
		return $folder;
	}

	/**
	 * What is read from an import folder ends up in the user's accounts, so
	 * the folder has to be theirs. A folder someone shared with them is the
	 * sharer's to fill: left unchecked, sharing one at the path an import
	 * folder resolves to would let the sharer plant statements. Sharing your
	 * own import folder with other people is fine: it stays yours.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function assertOwn(Node $node, string $userId): void {
		if (!$this->isOwn($node, $userId)) {
			throw new \InvalidArgumentException($this->l->t('The import folder has to be in your own Files, not in a folder someone shared with you.'));
		}
	}

	/**
	 * In the user's home storage and owned by them. Both: an external mount
	 * has no owner of its own and reports whoever is logged in (nobody, in a
	 * background job), so the owner alone would let everyone who can see an
	 * admin's mount through, and fail for the same folder at night. A folder
	 * shared with the user sits on the sharer's home storage, which is where
	 * the owner check tells them apart.
	 */
	private function isOwn(Node $node, string $userId): bool {
		try {
			return $node->getOwner()?->getUID() === $userId
				&& $node->getStorage()->instanceOfStorage(IHomeStorage::class);
		} catch (\Throwable) {
			return false;
		}
	}

	/** Whether a file listed earlier is still in its folder, the same file */
	private function stillThere(Folder $folder, File $file): bool {
		try {
			$name = $file->getName();
			return $folder->nodeExists($name) && $folder->get($name)->getId() === $file->getId();
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * A folder inside one that is already known to be the user's own:
	 * created there if it is missing, and checked to be theirs if it is not
	 * (it may be someone else's share mounted under that name).
	 *
	 * @throws \InvalidArgumentException If it is not the user's own, or the name is a file's
	 */
	private function childFolder(Folder $parent, string $name, string $userId): Folder {
		$node = $parent->nodeExists($name) ? $parent->get($name) : $parent->newFolder($name);
		if (!$node instanceof Folder) {
			throw new \InvalidArgumentException($this->l->t('%1$s is a file, so it cannot be used as a folder.', [$name]));
		}
		$this->assertOwn($node, $userId);
		return $node;
	}

	/**
	 * A file's contents, read through a stream that stops just past the size
	 * limit: the size Files has recorded can be stale or wrong, and a
	 * statement is never worth reading a whole large file into memory for.
	 *
	 * @throws ImportFolderFailure
	 */
	private function read(File $file): string {
		$limit = $this->fileValidator->getMaxFileSize();
		$handle = $file->fopen('r');
		if (!is_resource($handle)) {
			throw new \RuntimeException('Cannot open ' . $file->getName());
		}
		try {
			$content = stream_get_contents($handle, $limit + 1);
		} finally {
			fclose($handle);
		}
		if ($content === false) {
			throw new \RuntimeException('Cannot read ' . $file->getName());
		}
		if (strlen($content) > $limit) {
			throw new ImportFolderFailure('too_large');
		}
		return $content;
	}

	private function now(): string {
		return date('Y-m-d H:i:s', $this->time->getTime());
	}
}
