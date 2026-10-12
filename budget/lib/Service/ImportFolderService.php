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
use OCA\Budget\Service\Import\TemplateColumnResolver;
use OCA\Budget\Service\Import\TransactionNormalizer;
use OCA\Budget\Service\Mail\BudgetMailService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
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
	public const IMPORTED_FOLDER = 'Imported';
	public const FAILED_FOLDER = 'Failed';

	/** Files taken per user in one run; the rest wait for the next */
	public const FILES_PER_RUN = 20;

	private const MAX_NAME_LENGTH = 100;

	/** A file changed this recently may still be uploading */
	private const SETTLE_SECONDS = 120;

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
		$this->childFolder($this->mainFolder($userId), $name, $userId);

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

	/**
	 * Import what is waiting in the user's switched-on folders.
	 *
	 * @return array{imported: int, failed: int, waiting: int} Files, not transactions:
	 *                                                         waiting ones are still uploading or over this run's limit
	 */
	public function check(string $userId, int $limit = self::FILES_PER_RUN): array {
		$summary = ['imported' => 0, 'failed' => 0, 'waiting' => 0];
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
		foreach ($rows as $row) {
			try {
				// Recreated if it was deleted or renamed in Files
				$folder = $this->childFolder($main, (string)$row->getName(), $userId);
			} catch (\InvalidArgumentException $e) {
				// Not the user's own, or a file has its name: skipped, so one
				// folder that cannot be read does not stop the others
				$this->logger->warning("Budget import folder: {$row->getName()} skipped for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
				continue;
			}
			foreach ($this->statements($folder, $userId) as $file) {
				if ($taken >= $limit || $this->time->getTime() - $file->getMTime() < self::SETTLE_SECONDS) {
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
		return $summary;
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
	 * @return 'imported'|'failed'|null Null when another run already has the file
	 */
	private function handle(string $userId, ImportFolder $row, Folder $folder, File $file): ?string {
		// The job and "Check now" can overlap; whoever holds this imports the file
		$lock = 'budget/import-folder/' . $file->getId();
		try {
			$this->locking->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		} catch (LockedException) {
			return null;
		}

		try {
			$failure = null;
			$result = null;
			try {
				$result = $this->import($userId, $row, $file);
			} catch (ImportFolderFailure $e) {
				$failure = ['reason' => $e->reason] + $e->details;
			} catch (ValidationException $e) {
				// Written for the user (a spreadsheet that cannot be read, say)
				$failure = ['reason' => 'message', 'message' => $e->getMessage()];
			} catch (\Throwable $e) {
				$this->logger->error('Budget import folder: ' . $file->getName() . ' could not be imported: ' . $e->getMessage(), ['app' => Application::APP_ID, 'exception' => $e]);
				$failure = ['reason' => 'error'];
			}

			if ($failure !== null) {
				$this->audit->logImportFailed($userId, $file->getName(), $failure['message'] ?? $failure['reason']);
			}

			$moved = $this->moveInto($userId, $folder, $file, $failure === null ? self::IMPORTED_FOLDER : self::FAILED_FOLDER);

			if ($result !== null) {
				$this->notify($userId, 'import_folder_done', $file, $result);
			} else {
				$this->notify($userId, 'import_folder_failed', $file, $failure + ['moved' => $moved ? '1' : '0']);
			}

			if (!$moved) {
				// Left where it is, it would be imported again on every run:
				// duplicate detection would stop double entries, not the notifications
				$row->setEnabled(false);
				$row->setUpdatedAt($this->now());
				$this->mapper->update($row);
				if ($result !== null) {
					$this->notify($userId, 'import_folder_failed', $file, ['reason' => 'not_moved', 'moved' => '0']);
				}
			}

			return $failure === null ? 'imported' : 'failed';
		} finally {
			$this->locking->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
		}
	}

	/**
	 * @return array<string, string> The parameters of the "imported" notification
	 * @throws ImportFolderFailure
	 * @throws ValidationException
	 */
	private function import(string $userId, ImportFolder $row, File $file): array {
		$name = $file->getName();
		$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (!in_array($extension, $this->fileValidator->getAllowedExtensions(), true)) {
			throw new ImportFolderFailure('unsupported_type');
		}
		if ($file->getSize() > $this->fileValidator->getMaxFileSize()) {
			throw new ImportFolderFailure('too_large');
		}
		$accountId = (int)$row->getAccountId();
		$account = $this->openAccount($userId, $accountId) ?? throw new ImportFolderFailure('account_unavailable');

		$content = $this->read($file);
		$temporary = $this->tempManager->getTemporaryFile('.' . $extension);
		if ($temporary === false || file_put_contents($temporary, $content) === false) {
			throw new \RuntimeException('No temporary file for ' . $name);
		}
		$upload = $this->importService->processUpload($userId, ['name' => $name, 'tmp_name' => $temporary, 'size' => strlen($content)]);
		$fileId = (string)$upload['fileId'];
		$format = (string)$upload['format'];

		$template = $this->template($userId, $row->getTemplateId());
		$arguments = $format === 'csv'
			? $this->csvArguments($userId, $fileId, $name, $template)
			: $this->statementArguments($upload, $format, $template, $accountId);

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
	private function csvArguments(string $userId, string $fileId, string $name, ?ImportTemplate $template): array {
		if ($template === null || ($template->getFormat() ?? 'csv') !== 'csv') {
			throw new ImportFolderFailure('no_template');
		}
		$stored = $template->getParsedMapping();
		$delimiter = $template->getDelimiter() ?: ',';
		if ($delimiter === '\\t') {
			$delimiter = "\t";
		}
		$encoding = $template->getEncoding() ?: null;
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
	 * accounts, and every one goes to the folder's account. A template of the
	 * same format supplies which text goes where; without one, the defaults.
	 *
	 * @param array<string, mixed> $upload
	 * @return array{mapping: array<string, mixed>, accountMapping: ?array<string, int>, skipDuplicates: bool, applyRules: bool, delimiter: string, encoding: ?string}
	 */
	private function statementArguments(array $upload, string $format, ?ImportTemplate $template, int $accountId): array {
		$usable = $template !== null && $template->getFormat() === $format ? $template : null;

		$accountMapping = [];
		foreach ($upload['sourceAccounts'] ?? [] as $source) {
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
	private function notify(string $userId, string $subject, File $file, array $parameters): void {
		$notification = $this->notifications->createNotification();
		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime($this->time->getDateTime())
			->setObject('import_folder', (string)$file->getId())
			->setSubject($subject, ['fileId' => (string)$file->getId(), 'fileName' => $file->getName()] + $parameters);
		$this->notifications->notify($notification);
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

	private function isOwn(Node $node, string $userId): bool {
		try {
			return $node->getOwner()?->getUID() === $userId;
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
