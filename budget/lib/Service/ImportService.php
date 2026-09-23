<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Import\DuplicateDetector;
use OCA\Budget\Service\Import\EncodingNormalizer;
use OCA\Budget\Service\Import\FileValidator;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use OCA\Budget\Service\Import\ParserFactory;
use OCA\Budget\Service\Import\Preset\HeaderMappedPresetInterface;
use OCA\Budget\Service\Import\Preset\ImportPresetInterface;
use OCA\Budget\Service\Import\Preset\PresetRegistry;
use OCA\Budget\Service\Import\TransactionNormalizer;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IL10N;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates the import process for financial data files.
 */
class ImportService {
    private IAppData $appData;
    private TransactionService $transactionService;
    private TransactionMapper $transactionMapper;
    private AccountMapper $accountMapper;
    private AccountService $accountService;
    private FileValidator $fileValidator;
    private ParserFactory $parserFactory;
    private TransactionNormalizer $normalizer;
    private DuplicateDetector $duplicateDetector;
    private ImportRuleApplicator $ruleApplicator;
    private PresetRegistry $presetRegistry;
    private CategoryService $categoryService;
    private TagSetService $tagSetService;
    private TransactionTagService $transactionTagService;
    private ImportAccountLinkService $accountLinkService;
    private SettingService $settingService;
    private IL10N $l;
    private LoggerInterface $logger;

    /** @var array<int,string> Cache of destination account id => type for the account/account_type rule fields */
    private array $ruleAccountTypeCache = [];

    public function __construct(
        IAppData $appData,
        TransactionService $transactionService,
        TransactionMapper $transactionMapper,
        AccountMapper $accountMapper,
        AccountService $accountService,
        FileValidator $fileValidator,
        ParserFactory $parserFactory,
        TransactionNormalizer $normalizer,
        DuplicateDetector $duplicateDetector,
        ImportRuleApplicator $ruleApplicator,
        PresetRegistry $presetRegistry,
        CategoryService $categoryService,
        TagSetService $tagSetService,
        TransactionTagService $transactionTagService,
        ImportAccountLinkService $accountLinkService,
        private \OCA\Budget\Service\BillService $billService,
        SettingService $settingService,
        IL10N $l,
        LoggerInterface $logger
    ) {
        $this->appData = $appData;
        $this->transactionService = $transactionService;
        $this->transactionMapper = $transactionMapper;
        $this->accountMapper = $accountMapper;
        $this->accountService = $accountService;
        $this->fileValidator = $fileValidator;
        $this->parserFactory = $parserFactory;
        $this->normalizer = $normalizer;
        $this->duplicateDetector = $duplicateDetector;
        $this->ruleApplicator = $ruleApplicator;
        $this->presetRegistry = $presetRegistry;
        $this->categoryService = $categoryService;
        $this->tagSetService = $tagSetService;
        $this->transactionTagService = $transactionTagService;
        $this->accountLinkService = $accountLinkService;
        $this->settingService = $settingService;
        $this->l = $l;
        $this->logger = $logger;
    }

    /**
     * Record a row that could not be imported.
     *
     * The user is told "%n rows could not be imported — check the server log
     * for details", so the detail has to actually reach the log — until this
     * existed the only copy was in the browser console (#340).
     *
     * @param array<string, mixed> $context Extra fields to log alongside
     */
    private function logRowFailure(\Throwable $e, array $context = []): void {
        $this->logger->warning(
            'Budget import: row could not be imported — ' . $e->getMessage(),
            $context + ['app' => 'budget', 'exception' => $e]
        );
    }

    /**
     * Process an uploaded file and return preview information.
     */
    public function processUpload(string $userId, array $uploadedFile): array {
        $fileName = $uploadedFile['name'];
        $tmpPath = $uploadedFile['tmp_name'];
        $fileSize = $uploadedFile['size'];

        // Validate file
        $this->fileValidator->validate($fileName, $fileSize, $tmpPath);

        // Detect format
        $format = $this->parserFactory->detectFormat($fileName);

        // Generate unique file ID with extension
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) ?: 'dat';
        $fileId = 'import_' . $userId . '_' . bin2hex(random_bytes(16)) . '.' . $extension;

        try {
            // Store the file as uploaded. It used to be stored already
            // converted, which made the encoding a one-shot guess: getting it
            // wrong was unrecoverable because the original bytes were gone.
            // Keeping them lets the user re-declare the encoding afterwards,
            // when the preview is there to show whether it is right (#371).
            $importsFolder = $this->getOrCreateImportsFolder();
            $file = $importsFolder->newFile($fileId);
            $raw = file_get_contents($tmpPath);
            $file->putContent($raw);

            return $this->buildEncodedUploadResponse($userId, $fileId, $fileName, $format, $raw, $fileSize, null);

        } catch (\Exception $e) {
            throw new \Exception($this->l->t('Failed to process upload: %1$s', [$e->getMessage()]));
        }
    }

    /**
     * Whether an encoding name may be chosen in the import screen's picker.
     */
    public function isSupportedEncoding(string $encoding): bool {
        return (new EncodingNormalizer())->isSupported($encoding);
    }

    /**
     * Rebuild the upload response from a previously stored file under a new
     * delimiter/header assumption without re-uploading it.
     */
    public function dataPreview(
        string $userId,
        string $fileId,
        string $fileName,
        ?string $delimiter,
        bool $skipFirstRow = true,
        ?string $encoding = null
    ): array {
        $file = $this->getImportFile($userId, $fileId);
        $format = $this->parserFactory->detectFormat($fileId);
        $delimiter ??= ',';

        return $this->buildEncodedUploadResponse(
            $userId,
            $fileId,
            $fileName,
            $format,
            $file->getContent(),
            (int) $file->getSize(),
            $encoding,
            $delimiter,
            $skipFirstRow
        );
    }

    /**
     * Decode raw stored bytes and build the mapping-screen payload from them.
     */
    private function buildEncodedUploadResponse(
        string $userId,
        string $fileId,
        string $fileName,
        string $format,
        string $raw,
        int $fileSize,
        ?string $encoding,
        ?string $delimiterOverride = null,
        bool $skipFirstRow = true
    ): array {
        $normalizer = new EncodingNormalizer();
        $content = $normalizer->toUtf8($raw, $encoding);

        // Detect CSV delimiter if applicable unless the caller explicitly
        // overrides it to re-render the mapping screen under a different split.
        $delimiter = ',';
        if ($format === 'csv') {
            $delimiter = $delimiterOverride ?? $this->fileValidator->detectDelimiter($content);
        }

        // Parse preview
        $preview = $this->parserFactory->parse($content, $format, 5, $delimiter, $skipFirstRow);

        $response = $this->buildUploadResponse(
            $userId,
            $fileId,
            $fileName,
            $format,
            $content,
            $preview,
            $fileSize,
            $delimiter,
            $skipFirstRow
        );

        $response['encoding'] = $encoding;
        $response['detectedEncoding'] = $encoding ?? $normalizer->detectedEncoding($raw);
        $response['availableEncodings'] = $normalizer->supportedEncodings();

        return $response;
    }

    /**
     * Preview import with account mapping.
     */
    public function previewImport(
        string $userId,
        string $fileId,
        array $mapping,
        ?int $accountId = null,
        ?array $accountMapping = null,
        bool $skipDuplicates = true,
        string $delimiter = ',',
        ?string $presetId = null,
        ?string $encoding = null
    ): array {
        $file = $this->getImportFile($userId, $fileId);
        $format = $this->parserFactory->detectFormat($fileId);
        $content = $this->ensureUtf8($file->getContent(), $encoding);

        if ($this->isMultiAccountFormat($format) && !empty($accountMapping)) {
            return $this->previewMultiAccountImport($userId, $content, $format, $accountMapping, $skipDuplicates, $mapping);
        }

        return $this->previewSingleAccountImport($userId, $content, $format, $mapping, $accountId, $skipDuplicates, $delimiter, $presetId);
    }

    /**
     * Process import and create transactions.
     */
    public function processImport(
        string $userId,
        string $fileId,
        array $mapping,
        ?int $accountId = null,
        ?array $accountMapping = null,
        bool $skipDuplicates = true,
        bool $applyRules = true,
        string $delimiter = ',',
        ?string $presetId = null,
        ?string $encoding = null
    ): array {
        $file = $this->getImportFile($userId, $fileId);
        $format = $this->parserFactory->detectFormat($fileId);
        $content = $this->ensureUtf8($file->getContent(), $encoding);

        if ($this->isMultiAccountFormat($format) && !empty($accountMapping)) {
            $result = $this->executeMultiAccountImport($userId, $fileId, $content, $format, $accountMapping, $skipDuplicates, $applyRules, $mapping);
        } else {
            $result = $this->executeSingleAccountImport($userId, $fileId, $content, $format, $mapping, $accountId, $skipDuplicates, $applyRules, $delimiter, $presetId);
        }

        // Clean up import file
        try {
            $file->delete();
        } catch (\Exception $e) {
            // Log but don't fail on cleanup error
        }

        return $result;
    }

    /**
     * Get import templates for common bank formats.
     */
    public function getImportTemplates(): array {
        $templates = [
            'chase_checking' => [
                'name' => 'Chase Checking',
                'format' => 'csv',
                'mapping' => [
                    'date' => 'Transaction Date',
                    'description' => 'Description',
                    'amount' => 'Amount',
                    'type' => 'Type'
                ]
            ],
            'bank_of_america' => [
                'name' => 'Bank of America',
                'format' => 'csv',
                'mapping' => [
                    'date' => 'Date',
                    'description' => 'Description',
                    'amount' => 'Amount',
                    'balance' => 'Running Bal.'
                ]
            ],
            'wells_fargo' => [
                'name' => 'Wells Fargo',
                'format' => 'csv',
                'mapping' => [
                    'date' => 'Date',
                    'amount' => 'Amount',
                    'description' => 'Description'
                ]
            ]
        ];

        // Merge app-specific presets
        foreach ($this->presetRegistry->toArray() as $preset) {
            $templates[$preset['id']] = $preset;
        }

        return $templates;
    }

    public function getImportHistory(string $userId, int $limit = 10): array {
        return $this->transactionMapper->getRecentImports($userId, $limit);
    }

    public function validateFile(string $userId, string $fileId, ?string $encoding = null): array {
        $file = $this->getImportFile($userId, $fileId);
        $format = $this->parserFactory->detectFormat($fileId);

        try {
            $preview = $this->parserFactory->parse($this->ensureUtf8($file->getContent(), $encoding), $format, 10);

            return [
                'valid' => true,
                'format' => $format,
                'rowCount' => count($preview),
                'columns' => array_keys($preview[0] ?? []),
                'sample' => array_slice($preview, 0, 3)
            ];
        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    public function executeImport(string $userId, string $importId, int $accountId, array $transactionIds): array {
        return [
            'importId' => $importId,
            'accountId' => $accountId,
            'imported' => count($transactionIds),
            'success' => true,
            'message' => 'Import completed successfully'
        ];
    }

    public function rollbackImport(string $userId, int $importId): array {
        return [
            'importId' => $importId,
            'rolledBack' => true,
            'transactionsRemoved' => 0,
            'message' => 'Import rolled back successfully'
        ];
    }

    // Private helper methods

    private function getOrCreateImportsFolder() {
        try {
            return $this->appData->getFolder('imports');
        } catch (NotFoundException $e) {
            return $this->appData->newFolder('imports');
        }
    }

    /**
     * The caller's own uploaded import file. The id arrives from the client,
     * and every user's uploads share one app-data folder, so it must be one
     * this user was given ("import_<uid>_<32 hex>[.ext]"); anything else —
     * another user's file, a path — is reported exactly like a missing file.
     */
    private function getImportFile(string $userId, string $fileId) {
        if (!self::isOwnImportFileId($userId, $fileId)) {
            throw new \Exception($this->l->t('Import file not found'));
        }
        try {
            $importsFolder = $this->appData->getFolder('imports');
            return $importsFolder->getFile($fileId);
        } catch (NotFoundException $e) {
            try {
                return $importsFolder->getFile($fileId . '.dat');
            } catch (NotFoundException $e) {
                throw new \Exception($this->l->t('Import file not found'));
            }
        }
    }

    /**
     * Whether $fileId has the shape processUpload() hands to $userId. The 32
     * hex digits pin the owner exactly: without them "import_bob_" would
     * also match user bob_x's files.
     */
    public static function isOwnImportFileId(string $userId, string $fileId): bool {
        if ($userId === '' || str_contains($fileId, '/') || str_contains($fileId, '\\')
            || str_contains($fileId, "\0") || str_contains($fileId, '..')) {
            return false;
        }
        $pattern = '/^import_' . preg_quote($userId, '/') . '_[0-9a-f]{32}(?:\.[^.]+)?$/D';
        return preg_match($pattern, $fileId) === 1;
    }

    private function buildUploadResponse(
        string $userId,
        string $fileId,
        string $fileName,
        string $format,
        string $content,
        array $preview,
        int $fileSize,
        string $delimiter = ',',
        bool $skipFirstRow = true
    ): array {
        $columns = [];
        $rawPreview = [];
        $sourceAccounts = [];

        if ($format === 'csv') {
            $content = $this->parserFactory->stripBom($content);
            $lines = explode("\n", $content);
            $dataWidth = $this->parserFactory->detectDataWidth($lines, $delimiter);
            $isFirstRow = true;

            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                $row = str_getcsv($line, $delimiter, '"', '');
                // Skip rows that don't match the expected data width (metadata/preamble)
                if ($dataWidth > 0 && count($row) !== $dataWidth) {
                    continue;
                }

                if ($isFirstRow) {
                    if ($skipFirstRow) {
                        $columns = array_map('trim', $row);
                        $rawPreview[] = $columns;
                        $isFirstRow = false;
                        continue;
                    }

                    $columns = array_fill(0, $dataWidth, '');
                }

                $rawPreview[] = $row;
                $isFirstRow = false;
                if (count($rawPreview) > 6) {
                    break;
                }
            }

            if ($dataWidth > 0 && empty($columns)) {
                $columns = array_fill(0, $dataWidth, '');
            }
        } elseif ($format === 'ofx') {
            $parsedOfx = $this->parserFactory->parseFull($content, 'ofx');
            foreach ($parsedOfx['accounts'] as $account) {
                $sourceAccounts[] = [
                    'accountId' => $account['accountId'],
                    'bankId' => $account['bankId'] ?? null,
                    'type' => $account['type'],
                    'currency' => $account['currency'],
                    'transactionCount' => count($account['transactions']),
                    'ledgerBalance' => $account['ledgerBalance'],
                ];
            }

            // Auto-match source accounts to existing user accounts
            $accountMatches = $this->matchSourceAccounts($userId, $sourceAccounts);
            foreach ($sourceAccounts as &$sourceAccount) {
                $sourceAccount['suggestedMatch'] = $accountMatches[$sourceAccount['accountId']] ?? null;
            }
            unset($sourceAccount);

            $columns = ['date', 'amount', 'description', 'memo', 'type', 'reference'];
            $rawPreview = [$columns];
            foreach ($preview as $row) {
                $rawPreview[] = [
                    $row['date'] ?? '',
                    $row['rawAmount'] ?? $row['amount'] ?? '',
                    $row['description'] ?? '',
                    $row['memo'] ?? '',
                    $row['type'] ?? '',
                    $row['reference'] ?? $row['id'] ?? '',
                ];
            }
        } elseif ($format === 'camt') {
            $parsedCamt = $this->parserFactory->parseFull($content, 'camt');
            foreach ($parsedCamt['accounts'] as $account) {
                $sourceAccounts[] = [
                    'accountId' => $account['accountId'],
                    'bankId' => $account['bankId'] ?? null,
                    'name' => $account['name'] ?? null,
                    'bankName' => $account['bankName'] ?? null,
                    'type' => $account['type'],
                    'currency' => $account['currency'],
                    'transactionCount' => count($account['transactions']),
                    'ledgerBalance' => $account['ledgerBalance'],
                ];
            }

            // The camt account id is the IBAN, which matchSourceAccounts
            // already compares against each account's stored IBAN
            $accountMatches = $this->matchSourceAccounts($userId, $sourceAccounts);
            foreach ($sourceAccounts as &$sourceAccount) {
                $sourceAccount['suggestedMatch'] = $accountMatches[$sourceAccount['accountId']] ?? null;
            }
            unset($sourceAccount);

            // Keys must be what CamtParser emits (#338): 'name' is the counterparty
            $columns = ['date', 'amount', 'description', 'name', 'memo', 'type', 'reference'];
            $rawPreview = [$columns];
            foreach ($preview as $row) {
                $rawPreview[] = [
                    $row['date'] ?? '',
                    $row['rawAmount'] ?? $row['amount'] ?? '',
                    $row['description'] ?? '',
                    $row['name'] ?? '',
                    $row['memo'] ?? '',
                    $row['type'] ?? '',
                    $row['reference'] ?? $row['id'] ?? '',
                ];
            }
        } elseif ($format === 'qif') {
            $parsedQif = $this->parserFactory->parseFull($content, 'qif');
            foreach ($parsedQif['accounts'] as $account) {
                $sourceAccounts[] = [
                    'accountId' => $account['accountId'] ?? $account['name'] ?? 'Unknown',
                    'type' => $account['type'] ?? 'unknown',
                    'transactionCount' => count($account['transactions'] ?? []),
                ];
            }
            // Column names must be the keys QifParser actually emits, or the
            // preview cell is blank and the mapping cannot resolve it (#338).
            // The QIF payee arrives under 'description'; 'category' is an array.
            $columns = ['date', 'amount', 'description', 'memo', 'category', 'reference'];
            $rawPreview = [$columns];
            foreach ($preview as $row) {
                $category = $row['category'] ?? null;
                $rawPreview[] = [
                    $row['date'] ?? '',
                    $row['amount'] ?? '',
                    $row['description'] ?? '',
                    $row['memo'] ?? '',
                    is_array($category) ? ($category['name'] ?? '') : ($category ?? ''),
                    $row['reference'] ?? '',
                ];
            }
        } else {
            $columns = array_keys($preview[0] ?? []);
            $rawPreview = [$columns];
            foreach ($preview as $row) {
                $rawPreview[] = array_values($row);
            }
        }

        // Pre-fill destinations from previously remembered routings (gives QIF
        // auto-fill, and OFX a fallback where its account-number match missed).
        $sourceAccounts = $this->applyRememberedAccountLinks($userId, $format, $sourceAccounts);

        return [
            'fileId' => $fileId,
            'filename' => $fileName,
            'format' => $format,
            'preview' => $rawPreview,
            'columns' => $columns,
            'sourceAccounts' => $sourceAccounts,
            'recordCount' => $this->parserFactory->countRows($content, $format, $delimiter, $skipFirstRow),
            'size' => $fileSize,
            'delimiter' => $format === 'csv' ? $delimiter : null,
            'skipFirstRow' => $skipFirstRow,
            // The app export this file looks like, so the import screen can
            // offer its preset (null when it matches none)
            'suggestedPreset' => $format === 'csv' && $skipFirstRow ? $this->presetRegistry->detect($columns) : null,
        ];
    }

    /**
     * Make content-hash import IDs occurrence-aware so identical rows within
     * one file import as distinct transactions instead of being flagged as
     * duplicates of each other (#276) — e.g. two same-priced purchases on the
     * same day. The first occurrence keeps the plain legacy hash (so dedup
     * against previously imported data still works); repeats get an _occN
     * suffix, which also dedups correctly when the same file is re-imported.
     * OFX FITIDs pass through untouched: a repeated FITID genuinely is the
     * same transaction.
     *
     * @param string $baseId Import ID from TransactionNormalizer::generateImportId
     * @param int|string $accountKey Destination account discriminator for the counter
     * @param array &$counts Per-import occurrence counter, keyed by account + base ID
     */
    private function occurrenceAwareImportId(string $baseId, int|string $accountKey, array &$counts): string {
        if (!str_starts_with($baseId, 'hash_')) {
            return $baseId;
        }
        $key = $accountKey . '|' . $baseId;
        $counts[$key] = ($counts[$key] ?? 0) + 1;
        return $counts[$key] === 1 ? $baseId : $baseId . '_occ' . $counts[$key];
    }

    /**
     * With "skip duplicates" disabled the whole batch must import, but a
     * row whose import ID is already taken would still be rejected — by
     * TransactionService::create() and by the unique (account_id, import_id)
     * index. Suffix the ID until it is free so intentional duplicates can be
     * inserted (#275). Future skip-duplicates imports of the same statement
     * still match the original unsuffixed IDs.
     */
    private function ensureUniqueImportId(int $accountId, string $importId): string {
        $candidate = $importId;
        $n = 1;
        while ($this->duplicateDetector->isDuplicateByImportId($accountId, $candidate)) {
            $n++;
            $candidate = $importId . '_dup' . $n;
        }
        return $candidate;
    }

    /**
     * Formats whose files carry their own account list and are routed per
     * source account (OFX, QIF and ISO 20022 camt) rather than into one
     * chosen account like CSV.
     */
    private function isMultiAccountFormat(string $format): bool {
        return in_array($format, ['ofx', 'qif', 'camt'], true);
    }

    /**
     * The value the "Import Source" rule criterion matches on.
     *
     * Deliberately an untranslated literal, like the CSV and bank-sync ones:
     * it is compared against a pattern the user typed, so translating it would
     * break their rules whenever they changed language.
     */
    private function importSourceLabel(string $format): string {
        return match ($format) {
            'qif' => 'QIF Import',
            'camt' => 'camt.053 Import',
            default => 'OFX Import',
        };
    }

    private function previewMultiAccountImport(string $userId, string $content, string $format, array $accountMapping, bool $skipDuplicates, array $mapping = []): array {
        $parsedData = $this->parserFactory->parseFull($content, $format);
        $transactions = [];
        $duplicates = 0;
        $errors = [];
        $accountSummaries = [];
        $hashCounts = [];
        $seenImportIds = [];

        foreach ($parsedData['accounts'] as $sourceAccount) {
            $sourceId = $sourceAccount['accountId'];
            $destAccountId = $accountMapping[$sourceId] ?? null;

            if (!$destAccountId) continue;

            $destAccount = $this->accountMapper->find((int)$destAccountId, $userId);
            $accountSummaries[$sourceId] = [
                'sourceAccountId' => $sourceId,
                'destinationAccountId' => $destAccountId,
                'destinationAccountName' => $destAccount->getName(),
                'transactionCount' => 0,
                'duplicates' => 0,
            ];

            foreach ($sourceAccount['transactions'] as $index => $txn) {
                try {
                    $transaction = $this->normalizer->mapOfxTransaction($txn, $mapping);
                    $transaction['source'] = $this->importSourceLabel($format);
                    // The ID is derived from the unmapped row on purpose — see
                    // TransactionNormalizer::ofxImportIdentity (#338).
                    $importId = $this->occurrenceAwareImportId(
                        $this->normalizer->generateImportId('preview', $sourceId . '_' . $index, $this->normalizer->ofxImportIdentity($txn)),
                        (int)$destAccountId,
                        $hashCounts
                    );
                    // Within-batch repeats of non-hash IDs (e.g. a repeated OFX
                    // FITID = the same transaction twice in one file) are
                    // duplicates execute will skip — preview must agree.
                    $seenKey = $destAccountId . '|' . $importId;
                    $isDuplicate = $this->duplicateDetector->isDuplicate((int)$destAccountId, $transaction, $importId)
                        || isset($seenImportIds[$seenKey]);
                    $seenImportIds[$seenKey] = true;

                    if ($skipDuplicates && $isDuplicate) {
                        $duplicates++;
                        $accountSummaries[$sourceId]['duplicates']++;
                        continue;
                    }

                    if ($this->ruleApplicator) {
                        $transaction = $this->ruleApplicator->applyRules($userId, $this->withAccountContext($transaction, (int)$destAccountId, $userId));
                    }

                    // Preview what will actually be stored: the execute loop
                    // clamps after the rules run, so without this an
                    // append-to-notes rule previews text the import truncates.
                    $transaction = $this->normalizer->clampTransactionText($transaction);

                    $transactions[] = array_merge($transaction, [
                        'rowIndex' => $index,
                        'sourceAccountId' => $sourceId,
                        'destinationAccountId' => $destAccountId,
                        'isDuplicate' => $isDuplicate,
                    ]);

                    if ($isDuplicate) {
                        $duplicates++;
                        $accountSummaries[$sourceId]['duplicates']++;
                        // With skip-duplicates off these rows WILL import (#275)
                        $accountSummaries[$sourceId]['transactionCount']++;
                    } else {
                        $accountSummaries[$sourceId]['transactionCount']++;
                    }
                } catch (\Exception $e) {
                    $errors[] = ['row' => $index + 1, 'sourceAccountId' => $sourceId, 'error' => $e->getMessage()];
                }
            }
        }

        $totalRows = array_sum(array_map(fn($a) => count($a['transactions']), $parsedData['accounts']));

        return [
            'transactions' => array_slice($transactions, 0, 50),
            'totalRows' => $totalRows,
            'validTransactions' => count($transactions),
            'categorizedCount' => $this->countCategorized($transactions),
            'duplicates' => $duplicates,
            'errors' => $errors,
            'accountSummaries' => array_values($accountSummaries),
        ];
    }

    private function previewSingleAccountImport(string $userId, string $content, string $format, array $mapping, ?int $accountId, bool $skipDuplicates, string $delimiter = ',', ?string $presetId = null): array {
        // Load preset if specified
        $preset = $presetId ? $this->presetRegistry->get($presetId) : null;
        $hasAccountColumn = ($preset && !empty($preset->getOptions()['accountColumn'])) || TransactionNormalizer::mapsColumn($mapping, 'account');

        if (!$accountId && !$hasAccountColumn) {
            throw new \Exception($this->l->t('Account ID is required for single-account imports'));
        }

        if ($preset) {
            $mapping = $preset->getMapping();
            $delimiter = $preset->getDelimiter();
            if ($preset->getDateFormatHint()) {
                $this->normalizer->setDateFormatHint($preset->getDateFormatHint());
            }
        }

        $droppedByPreset = 0;
        $data = $this->readImportRows($content, $format, $delimiter, $mapping, $preset, $droppedByPreset);

        // Resolve accounts for multi-account imports (preset or manual account column mapping)
        $accountsToCreate = [];
        if ($hasAccountColumn) {
            $accountResolution = $this->resolvePresetAccounts($userId, $data, $preset, true, $mapping);
            $accountsToCreate = $accountResolution['created'];
        }

        $account = $accountId ? $this->accountMapper->find($accountId, $userId) : null;
        $transactions = [];
        $duplicates = 0;
        $errors = [];
        $categoriesToCreate = [];
        // Rows the preset dropped while reading (a split's total line)
        $skippedByPreset = $droppedByPreset;
        $hashCounts = [];
        $seenImportIds = [];
        $directionCounts = [];
        $unresolvedTypes = [];

        // Detect date format from all rows before processing individually,
        // unless the preset fixes it (a YNAB export follows the user's own
        // date setting, so its preset has to detect like a manual mapping)
        if (!$preset || $preset->getDateFormatHint() === null) {
            $dateColumn = $mapping['date'] ?? null;
            if ($dateColumn !== null) {
                $dateStrings = array_filter(array_map(
                    fn($row) => $row[$dateColumn] ?? '',
                    $data
                ));
                $this->normalizer->detectDateFormat($dateStrings);
            }
        }

        foreach ($data as $index => $row) {
            try {
                $transaction = $this->normalizer->mapRowToTransaction($row, $mapping);

                // Apply preset post-processing
                if ($preset) {
                    $transaction = $preset->postProcessRow($transaction, $row);
                    if ($transaction === null) {
                        $skippedByPreset++;
                        continue;
                    }

                    // Collect categories and tags that will be created
                    if (!empty($transaction['_categoryName'])) {
                        $catKey = $this->presetCategoryLabel($transaction);
                        if (!isset($categoriesToCreate[$catKey])) {
                            $categoriesToCreate[$catKey] = ['name' => $catKey, 'tags' => []];
                        }
                        foreach ($transaction['_tagNames'] ?? [] as $tagName) {
                            $categoriesToCreate[$catKey]['tags'][$tagName] = true;
                        }
                    }
                }

                // For multi-account preset: resolve accountId from row, skip if unresolvable
                $txAccountId = $accountId;
                if ($hasAccountColumn) {
                    $txAccountName = $transaction['_accountName'] ?? '';
                    if ($txAccountName === '') {
                        // Blank cell: same fallback the import itself makes,
                        // or the preview promises rows execute will drop.
                        if ($accountId === null) {
                            $errors[] = [
                                // 1-based, like the import's own errors: the
                                // review step renders these now, and two
                                // screens numbering the same row differently
                                // is worse than either numbering (#388).
                                'row' => $index + 1,
                                'error' => $this->l->t('This row has no account, and no account was chosen for the import'),
                                'reason' => 'no-account',
                                'data' => $row,
                            ];
                            continue;
                        }
                    } else {
                        // Find existing account ID for duplicate detection in preview
                        $existingAccount = $this->accountMapper->findByName($userId, $txAccountName);
                        $txAccountId = $existingAccount ? $existingAccount->getId() : null;
                    }
                }

                if ($txAccountId) {
                    $importId = $this->occurrenceAwareImportId(
                        $this->normalizer->generateImportId('preview', $index, $transaction),
                        $txAccountId,
                        $hashCounts
                    );
                    // Within-batch repeats of non-hash IDs are duplicates
                    // execute will skip — preview must agree.
                    $seenKey = $txAccountId . '|' . $importId;
                    $isDuplicate = $this->duplicateDetector->isDuplicate($txAccountId, $transaction, $importId)
                        || isset($seenImportIds[$seenKey]);
                    $seenImportIds[$seenKey] = true;

                    if ($skipDuplicates && $isDuplicate) {
                        $duplicates++;
                        continue;
                    }

                    $transaction = $this->ruleApplicator->applyRules($userId, $this->withAccountContext($transaction, $txAccountId, $userId));
                    // Preview what will actually be stored — see the identical
                    // clamp in executeSingleAccountImport (#340).
                    $transaction = $this->normalizer->clampTransactionText($transaction);
                    $transactions[] = array_merge($transaction, [
                        'rowIndex' => $index,
                        'isDuplicate' => $isDuplicate,
                    ]);

                    // Tally the direction each row will land on, post-rules, so
                    // the preview can flag a batch that is about to go in the
                    // wrong way (#333). Only existing accounts have a history
                    // worth comparing against.
                    $rowType = $transaction['type'] ?? '';
                    if ($rowType !== '') {
                        $directionCounts[$txAccountId][$rowType] = ($directionCounts[$txAccountId][$rowType] ?? 0) + 1;
                    }
                    if (!empty($transaction['_typeUnresolved'])) {
                        $unresolvedTypes[$txAccountId] = ($unresolvedTypes[$txAccountId] ?? 0) + 1;
                    }

                    if ($isDuplicate) {
                        $duplicates++;
                    }
                } else {
                    // Account doesn't exist yet — nothing in the DB to dedup
                    // against, but a repeated non-hash ID (e.g. duplicate OFX
                    // FITID) within this same file is still a duplicate that
                    // execute will skip after creating the account.
                    $newAccountKey = 'new:' . ($transaction['_accountName'] ?? '');
                    $importId = $this->occurrenceAwareImportId(
                        $this->normalizer->generateImportId('preview', $index, $transaction),
                        $newAccountKey,
                        $hashCounts
                    );
                    $seenKey = $newAccountKey . '|' . $importId;
                    $isDuplicate = isset($seenImportIds[$seenKey]);
                    $seenImportIds[$seenKey] = true;

                    if ($skipDuplicates && $isDuplicate) {
                        $duplicates++;
                        continue;
                    }

                    // Account doesn't exist yet, so $txAccountId is null and no
                    // account context is added — an account-scoped rule won't match.
                    $transaction = $this->ruleApplicator->applyRules($userId, $this->withAccountContext($transaction, $txAccountId, $userId));
                    // Preview what will actually be stored — see the identical
                    // clamp in executeSingleAccountImport (#340).
                    $transaction = $this->normalizer->clampTransactionText($transaction);
                    $transactions[] = array_merge($transaction, [
                        'rowIndex' => $index,
                        'isDuplicate' => $isDuplicate,
                    ]);

                    if ($isDuplicate) {
                        $duplicates++;
                    }
                }
            } catch (\Exception $e) {
                $errors[] = ['row' => $index + 1, 'error' => $e->getMessage(), 'data' => $row];
            }
        }

        $this->normalizer->resetDateFormat();

        // Build categories preview for preset or category-mapped imports
        $categoriesPreview = [];
        if (!empty($categoriesToCreate)) {
            foreach ($categoriesToCreate as $catData) {
                $entry = ['name' => $catData['name'], 'tags' => array_keys($catData['tags'])];
                $categoriesPreview[] = $entry;
            }
        }

        if ($hasAccountColumn) {
            $result = [
                'transactions' => array_slice($transactions, 0, 50),
                'totalRows' => count($data),
                'validTransactions' => count($transactions),
                'categorizedCount' => $this->countCategorized($transactions),
                'duplicates' => $duplicates,
                'skippedByPreset' => $skippedByPreset,
                'errors' => $errors,
                'accountsToCreate' => $accountsToCreate,
            ];
        } else {
            $result = [
                'transactions' => array_slice($transactions, 0, 50),
                'totalRows' => count($data),
                'validTransactions' => count($transactions),
                'categorizedCount' => $this->countCategorized($transactions),
                'duplicates' => $duplicates,
                'skippedByPreset' => $skippedByPreset,
                'errors' => $errors,
                'accountSummaries' => [[
                    'destinationAccountId' => $accountId,
                    'destinationAccountName' => $account->getName(),
                    'transactionCount' => count($transactions),
                    'duplicates' => $duplicates,
                ]],
            ];
        }

        if (!empty($categoriesPreview)) {
            $result['categoriesToCreate'] = $categoriesPreview;
        }

        $directionWarnings = $this->buildDirectionWarnings($userId, $directionCounts, $unresolvedTypes);
        if (!empty($directionWarnings)) {
            $result['directionWarnings'] = $directionWarnings;
        }

        $blankDescriptionRows = $this->blankDescriptionRows($transactions);
        if (!empty($blankDescriptionRows)) {
            $result['blankDescriptionRows'] = $blankDescriptionRows;
        }

        return $result;
    }

    /**
     * Rows that are about to be stored with no description, numbered from 1
     * like the skipped rows.
     *
     * Description is a required mapping, however nothing looked at the cells
     * under it, and an import rule that matches on the description cannot
     * categorize a row that has none. Judged after the rules have run, because
     * a "Set description" action fills the cell in before the row is stored
     * (#388).
     *
     * @param array[] $transactions Previewed rows, each carrying its rowIndex
     * @return int[]
     */
    private function blankDescriptionRows(array $transactions): array {
        $rows = [];
        foreach ($transactions as $transaction) {
            if (trim((string) ($transaction['description'] ?? '')) === '') {
                $rows[] = $transaction['rowIndex'] + 1;
            }
        }
        return $rows;
    }

    /**
     * Flag destination accounts where this batch would land almost entirely on
     * one side while the account's own history sits firmly on the other, and
     * rows that fell back to the amount's sign because the mapped type column
     * held nothing usable.
     *
     * A file whose amounts are all unsigned reads as income unless something
     * says otherwise, which silently doubled one user's balance error against
     * their bank (#333). The preview says so before anything is written.
     *
     * @param array<int, array<string, int>> $directionCounts Per-account credit/debit tallies
     * @param array<int, int> $unresolvedTypes Per-account count of rows with an unusable type value
     * @return array[] One entry per suspicious account
     */
    private function buildDirectionWarnings(string $userId, array $directionCounts, array $unresolvedTypes = []): array {
        // Deliberately conservative: a handful of salary rows into a current
        // account is normal, so only a sustained, near-total one-way batch
        // against a decisively opposite history is worth interrupting for.
        $minIncoming = 10;
        $minExisting = 20;
        $incomingShare = 0.95;
        $existingShare = 0.80;

        $warnings = [];
        $accountIds = array_unique(array_merge(array_keys($directionCounts), array_keys($unresolvedTypes)));

        foreach ($accountIds as $accountId) {
            $counts = $directionCounts[$accountId] ?? [];
            $credit = $counts['credit'] ?? 0;
            $debit = $counts['debit'] ?? 0;
            $incoming = $credit + $debit;
            if ($incoming === 0) {
                continue;
            }

            $accountName = null;

            // 1. Rows the type column couldn't answer for. Precise and always
            //    worth saying — those rows are going in on the amount's sign.
            $unresolved = $unresolvedTypes[$accountId] ?? 0;
            if ($unresolved > 0) {
                $accountName ??= $this->accountNameFor((int) $accountId, $userId);
                $warnings[] = [
                    'kind' => 'unresolved-type',
                    'accountId' => (int) $accountId,
                    'accountName' => $accountName,
                    'matching' => $unresolved,
                    'total' => $incoming,
                ];
            }

            // 2. A batch going almost entirely against the account's history.
            if ($incoming < $minIncoming) {
                continue;
            }

            // Which way is this batch going, and is it lopsided enough?
            $batchType = $credit >= $debit ? 'credit' : 'debit';
            $batchCount = $batchType === 'credit' ? $credit : $debit;
            if ($batchCount / $incoming < $incomingShare) {
                continue;
            }

            try {
                $existing = $this->transactionMapper->countByTypeForAccount((int) $accountId, $userId);
            } catch (\Exception $e) {
                continue;
            }

            $existingTotal = $existing['credit'] + $existing['debit'];
            if ($existingTotal < $minExisting) {
                continue;
            }

            // The account has to lean the other way, and lean hard.
            $oppositeType = $batchType === 'credit' ? 'debit' : 'credit';
            if ($existing[$oppositeType] / $existingTotal < $existingShare) {
                continue;
            }

            $accountName ??= $this->accountNameFor((int) $accountId, $userId);
            $warnings[] = [
                'kind' => 'direction',
                'accountId' => (int) $accountId,
                'accountName' => $accountName,
                'type' => $batchType,
                'matching' => $batchCount,
                'total' => $incoming,
                'existingOppositePercent' => (int) round(($existing[$oppositeType] / $existingTotal) * 100),
            ];
        }

        return $warnings;
    }

    /**
     * Account name for a warning, empty when it can't be read — a warning is
     * never worth failing a preview over.
     */
    private function accountNameFor(int $accountId, string $userId): string {
        try {
            return $this->accountMapper->find($accountId, $userId)->getName() ?? '';
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Add the destination account context ('account' id and 'account_type') to
     * a parsed transaction so import rules can match on the account they are
     * being imported into. When the account does not exist yet (a brand-new
     * account created later in the same import) the keys are omitted, so an
     * account-scoped rule simply doesn't match — which is the correct outcome.
     *
     * @param array $transaction Parsed transaction data
     * @param int|null $accountId Resolved destination account id
     * @param string $userId
     * @return array Transaction data enriched for rule evaluation
     */
    private function withAccountContext(array $transaction, ?int $accountId, string $userId): array {
        if (!$accountId) {
            return $transaction;
        }
        $transaction['account'] = $accountId;
        if (!array_key_exists($accountId, $this->ruleAccountTypeCache)) {
            try {
                $this->ruleAccountTypeCache[$accountId] = $this->accountMapper->find($accountId, $userId)->getType() ?? '';
            } catch (\Exception $e) {
                $this->ruleAccountTypeCache[$accountId] = '';
            }
        }
        $transaction['account_type'] = $this->ruleAccountTypeCache[$accountId];
        return $transaction;
    }

    private function executeMultiAccountImport(string $userId, string $fileId, string $content, string $format, array $accountMapping, bool $skipDuplicates, bool $applyRules, array $mapping = []): array {
        $parsedData = $this->parserFactory->parseFull($content, $format);
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $transferLinkIds = [];
        $accountResults = [];
        $hashCounts = [];
        $touchedAccounts = [];
        $createdForBillMatch = [];

        try {
        foreach ($parsedData['accounts'] as $sourceAccount) {
            $sourceId = $sourceAccount['accountId'];
            $destAccountId = $accountMapping[$sourceId] ?? null;

            if (!$destAccountId) continue;

            // An invalid mapped destination (deleted between preview and
            // execute, or a bad id) must not abort the whole import — earlier
            // accounts' rows are already persisted.
            try {
                $destAccount = $this->accountMapper->find((int)$destAccountId, $userId);
            } catch (\Exception $e) {
                $errors[] = ['sourceAccountId' => $sourceId, 'error' => $this->l->t('Destination account %1$s not found', [(string)$destAccountId])];
                continue;
            }
            $accountResults[$sourceId] = [
                'sourceAccountId' => $sourceId,
                'destinationAccountId' => $destAccountId,
                'destinationAccountName' => $destAccount->getName(),
                'imported' => 0,
                'skipped' => 0,
            ];

            foreach ($sourceAccount['transactions'] as $index => $txn) {
                try {
                    $transaction = $this->normalizer->mapOfxTransaction($txn, $mapping);
                    $transaction['source'] = $this->importSourceLabel($format);
                    // The ID is derived from the unmapped row on purpose — see
                    // TransactionNormalizer::ofxImportIdentity (#338).
                    $importId = $this->occurrenceAwareImportId(
                        $this->normalizer->generateImportId($fileId, $sourceId . '_' . $index, $this->normalizer->ofxImportIdentity($txn)),
                        (int)$destAccountId,
                        $hashCounts
                    );

                    if ($skipDuplicates && $this->duplicateDetector->isDuplicateByImportId((int)$destAccountId, $importId)) {
                        $skipped++;
                        $accountResults[$sourceId]['skipped']++;
                        continue;
                    }
                    if (!$skipDuplicates) {
                        // Import everything: free up the ID if it's already taken (#275)
                        $importId = $this->ensureUniqueImportId((int)$destAccountId, $importId);
                    }

                    if ($applyRules) {
                        $transaction = $this->ruleApplicator->applyRules($userId, $this->withAccountContext($transaction, (int)$destAccountId, $userId));
                    }

                    // Re-clamp: an "append to notes" rule action runs after the
                    // mapping clamp and can push a field back over its limit.
                    $transaction = $this->normalizer->clampTransactionText($transaction);

                    $createdTx = $this->transactionService->create(
                        $userId,
                        (int)$destAccountId,
                        $transaction['date'],
                        $transaction['description'],
                        $transaction['amount'],
                        $transaction['type'],
                        $transaction['categoryId'] ?? null,
                        $transaction['vendor'] ?? null,
                        $transaction['reference'] ?? null,
                        $transaction['notes'] ?? null,
                        $importId,
                        excludedFromForecast: !empty($transaction['excludedFromForecast']),
                        deferBalanceUpdate: true
                    );
                    $touchedAccounts[(int)$destAccountId] = true;
                    $createdForBillMatch[] = $createdTx;

                    // Apply deferred tag actions from import rules
                    if (!empty($transaction['_deferred_tags'])) {
                        $finalTagIds = [];
                        foreach ($transaction['_deferred_tags'] as $tagAction) {
                            $newTagIds = $tagAction['tagIds'] ?? [];
                            if (($tagAction['behavior'] ?? 'merge') === 'merge') {
                                $finalTagIds = array_values(array_unique(array_merge($finalTagIds, $newTagIds)));
                            } else {
                                $finalTagIds = $newTagIds;
                            }
                        }
                        if (!empty($finalTagIds)) {
                            $this->transactionTagService->setTransactionTags($createdTx->getId(), $userId, $finalTagIds);
                        }
                    }

                    if (!empty($transaction['_deferred_link_transfer'])) {
                        $transferLinkIds[] = $createdTx->getId();
                    }

                    $imported++;
                    $accountResults[$sourceId]['imported']++;
                } catch (\Exception $e) {
                    $this->logRowFailure($e, ['row' => $index + 1, 'sourceAccountId' => $sourceId, 'format' => $format]);
                    $errors[] = ['row' => $index + 1, 'sourceAccountId' => $sourceId, 'error' => $e->getMessage()];
                }
            }
        }

        } finally {
            // Balance updates were deferred per-row; recompute once per
            // account — in a finally so persisted rows can never be left
            // with a stale balance even if the import aborts mid-way. Each
            // recompute is guarded: one failing account must not skip the
            // others or replace an in-flight exception.
            foreach (array_keys($touchedAccounts) as $touchedAccountId) {
                try {
                    $this->transactionService->recalculateAccountBalance($touchedAccountId, $userId);
                } catch (\Exception $e) {
                    $this->logRowFailure($e, ['accountId' => $touchedAccountId, 'stage' => 'balance-recalculation']);
                    $errors[] = ['error' => $this->l->t('Failed to recalculate balance for account %1$s', [(string)$touchedAccountId])];
                }
            }
        }

        // Process deferred transfer linking
        $transfersLinked = 0;
        foreach ($transferLinkIds as $txId) {
            try {
                $matches = $this->transactionService->findPotentialMatches($txId, $userId, 3);
                if (!empty($matches)) {
                    $this->transactionService->linkTransactions($txId, $matches[0]->getId(), $userId);
                    $transfersLinked++;
                }
            } catch (\Exception $e) {
                // Silently skip
            }
        }

        $totalProcessed = array_sum(array_map(fn($a) => count($a['transactions']), $parsedData['accounts']));

        // Auto-mark bills paid from matching imported transactions (#274).
        // Best-effort: a matching failure must never fail the import.
        $billsMarkedPaid = 0;
        try {
            $billsMarkedPaid = $this->billService->autoMatchPaidFromImport($userId, $createdForBillMatch);
        } catch (\Exception $e) {
            // ignore
        }

        // Remember the routing so the next same-format import can pre-fill it.
        // Best-effort: never let this break a completed import.
        try {
            $this->accountLinkService->remember($userId, $format, $accountMapping);
        } catch (\Exception $e) {
            // ignore
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'totalProcessed' => $totalProcessed,
            'accountResults' => array_values($accountResults),
            'transfersLinked' => $transfersLinked,
            'billsMarkedPaid' => $billsMarkedPaid,
        ];
    }

    private function executeSingleAccountImport(string $userId, string $fileId, string $content, string $format, array $mapping, ?int $accountId, bool $skipDuplicates, bool $applyRules, string $delimiter = ',', ?string $presetId = null): array {
        // Load preset if specified
        $preset = $presetId ? $this->presetRegistry->get($presetId) : null;
        $hasAccountColumn = ($preset && !empty($preset->getOptions()['accountColumn'])) || TransactionNormalizer::mapsColumn($mapping, 'account');

        if (!$accountId && !$hasAccountColumn) {
            throw new \Exception($this->l->t('Account ID is required for single-account imports'));
        }

        if ($preset) {
            $mapping = $preset->getMapping();
            $delimiter = $preset->getDelimiter();
            if ($preset->getDateFormatHint()) {
                $this->normalizer->setDateFormatHint($preset->getDateFormatHint());
            }
        }

        $droppedByPreset = 0;
        $data = $this->readImportRows($content, $format, $delimiter, $mapping, $preset, $droppedByPreset);

        // Resolve accounts for multi-account imports (preset or manual account column mapping)
        $resolvedAccounts = [];
        $accountsCreated = 0;
        if ($hasAccountColumn) {
            $accountResolution = $this->resolvePresetAccounts($userId, $data, $preset, false, $mapping);
            $resolvedAccounts = $accountResolution['resolved'];
            $accountsCreated = count(array_filter($accountResolution['created'], fn($a) => !$a['exists']));
        }

        $account = $accountId ? $this->accountMapper->find($accountId, $userId) : null;
        $imported = 0;
        $skipped = $droppedByPreset;
        $errors = [];
        $categoriesCreated = 0;
        $presetTransferLegs = [];
        $transferLinkIds = [];
        $hashCounts = [];
        $touchedAccounts = [];
        $createdForBillMatch = [];

        // Detect date format from all rows before processing individually,
        // unless the preset fixes it (a YNAB export follows the user's own
        // date setting, so its preset has to detect like a manual mapping)
        if (!$preset || $preset->getDateFormatHint() === null) {
            $dateColumn = $mapping['date'] ?? null;
            if ($dateColumn !== null) {
                $dateStrings = array_filter(array_map(
                    fn($row) => $row[$dateColumn] ?? '',
                    $data
                ));
                $this->normalizer->detectDateFormat($dateStrings);
            }
        }

        // Cache for resolved categories and tags during this import
        $categoryCache = [];
        $tagCache = [];
        $tagsCreated = 0;
        // Track per-account results for multi-account imports
        $perAccountResults = [];

        foreach ($data as $index => $row) {
            try {
                $transaction = $this->normalizer->mapRowToTransaction($row, $mapping);

                // Apply preset post-processing
                if ($preset) {
                    $transaction = $preset->postProcessRow($transaction, $row);
                    if ($transaction === null) {
                        $skipped++;
                        continue;
                    }
                }

                // Determine which account this transaction goes to. A blank
                // account cell falls back to the account chosen in the wizard
                // rather than dropping the row: in #333 half a statement was
                // discarded because those cells were empty in the source.
                $txAccountId = $accountId;
                if ($hasAccountColumn) {
                    $txAccountName = $transaction['_accountName'] ?? '';
                    $rowAccountId = $txAccountName !== ''
                        ? ($resolvedAccounts[$txAccountName] ?? null)
                        : $accountId;
                    if ($rowAccountId === null) {
                        $this->logger->warning('Budget import: could not resolve account for row', [
                            'app' => 'budget',
                            'row' => $index + 1,
                            'accountName' => $txAccountName,
                            'fileId' => $fileId,
                        ]);
                        $errors[] = [
                            'row' => $index + 1,
                            'error' => $txAccountName === ''
                                ? $this->l->t('This row has no account, and no account was chosen for the import')
                                : $this->l->t('Could not resolve account: %1$s', [$txAccountName]),
                            // A code, not the message: the UI points at the
                            // fallback account select off the back of this and
                            // must not depend on the user's language (#388).
                            'reason' => $txAccountName === '' ? 'no-account' : 'unresolved-account',
                        ];
                        continue;
                    }
                    $txAccountId = $rowAccountId;
                }

                $importId = $this->occurrenceAwareImportId(
                    $this->normalizer->generateImportId($fileId, $index, $transaction),
                    $txAccountId,
                    $hashCounts
                );

                if ($skipDuplicates && $this->duplicateDetector->isDuplicateByImportId($txAccountId, $importId)) {
                    $skipped++;
                    continue;
                }
                if (!$skipDuplicates) {
                    // Import everything: free up the ID if it's already taken (#275)
                    $importId = $this->ensureUniqueImportId($txAccountId, $importId);
                }

                if ($applyRules) {
                    $transaction = $this->ruleApplicator->applyRules($userId, $this->withAccountContext($transaction, $txAccountId, $userId));
                }

                // Resolve category from preset or mapping metadata if no category already assigned
                if (empty($transaction['categoryId']) && !empty($transaction['_categoryName'])) {
                    $categoryId = $this->resolvePresetCategory(
                        $userId,
                        $transaction,
                        $categoryCache,
                        $categoriesCreated
                    );
                    if ($categoryId !== null) {
                        $transaction['categoryId'] = $categoryId;
                    }
                }

                // Re-clamp: an "append to notes" rule action runs after the
                // mapping clamp and can push a field back over its limit.
                $transaction = $this->normalizer->clampTransactionText($transaction);

                $createdTx = $this->transactionService->create(
                    $userId,
                    $txAccountId,
                    $transaction['date'],
                    $transaction['description'],
                    $transaction['amount'],
                    $transaction['type'],
                    $transaction['categoryId'] ?? null,
                    $transaction['vendor'] ?? null,
                    $transaction['reference'] ?? null,
                    $transaction['notes'] ?? null,
                    $importId,
                    excludedFromForecast: !empty($transaction['excludedFromForecast']),
                    deferBalanceUpdate: true
                );
                $touchedAccounts[$txAccountId] = true;
                $createdForBillMatch[] = $createdTx;

                // Apply tags from preset (e.g., Toshl Tags)
                if ($preset && !empty($transaction['_tagNames']) && !empty($transaction['categoryId'])) {
                    $tagIds = $this->resolvePresetTags(
                        $userId,
                        (int) $transaction['categoryId'],
                        $transaction['_tagNames'],
                        $tagCache,
                        $tagsCreated
                    );
                    if (!empty($tagIds)) {
                        $this->transactionTagService->setTransactionTags($createdTx->getId(), $userId, $tagIds);
                    }
                }

                // Apply deferred tag actions from import rules
                if (!empty($transaction['_deferred_tags'])) {
                    $existingTagIds = array_column(
                        $this->transactionTagService->getTransactionTags($createdTx->getId(), $userId),
                        'tagId'
                    );
                    $finalTagIds = $existingTagIds;
                    foreach ($transaction['_deferred_tags'] as $tagAction) {
                        $newTagIds = $tagAction['tagIds'] ?? [];
                        if (($tagAction['behavior'] ?? 'merge') === 'merge') {
                            $finalTagIds = array_values(array_unique(array_merge($finalTagIds, $newTagIds)));
                        } else {
                            $finalTagIds = $newTagIds;
                        }
                    }
                    $this->transactionTagService->setTransactionTags($createdTx->getId(), $userId, $finalTagIds);
                }

                // Collect transactions flagged for transfer linking
                if (!empty($transaction['_deferred_link_transfer'])) {
                    $transferLinkIds[] = $createdTx->getId();
                }

                // One side of a transfer an app-export preset recognised. The
                // other side is in the same file, so the pair is linked once
                // every row is saved.
                if ($preset && !empty($transaction['_transfer'])) {
                    $presetTransferLegs[] = [
                        'id' => $createdTx->getId(),
                        'accountId' => $txAccountId,
                        'amount' => (float) $transaction['amount'],
                        'type' => (string) $transaction['type'],
                        'date' => (string) $transaction['date'],
                        'peer' => (string) ($transaction['_transferPeer'] ?? ''),
                    ];
                }

                $imported++;

                // Track per-account stats
                if ($hasAccountColumn) {
                    $txAccountName = $transaction['_accountName'] ?? '';
                    if ($txAccountName === '') {
                        // Fell back to the chosen account above - tally it there
                        $txAccountName = $account ? $account->getName() : 'Unknown';
                    }
                    if (!isset($perAccountResults[$txAccountName])) {
                        $perAccountResults[$txAccountName] = [
                            'destinationAccountId' => $txAccountId,
                            'destinationAccountName' => $txAccountName,
                            'imported' => 0,
                            'skipped' => 0,
                        ];
                    }
                    $perAccountResults[$txAccountName]['imported']++;
                }
            } catch (\Exception $e) {
                $this->logRowFailure($e, ['row' => $index + 1, 'fileId' => $fileId, 'format' => $format]);
                $errors[] = ['row' => $index + 1, 'error' => $e->getMessage()];
            }
        }

        $this->normalizer->resetDateFormat();

        // Balance updates were deferred per-row; recompute once per account
        foreach (array_keys($touchedAccounts) as $touchedAccountId) {
            $this->transactionService->recalculateAccountBalance($touchedAccountId, $userId);
        }

        // Process deferred transfer linking after all transactions are persisted
        $transfersLinked = 0;
        if (!empty($transferLinkIds)) {
            foreach ($transferLinkIds as $txId) {
                try {
                    $matches = $this->transactionService->findPotentialMatches($txId, $userId, 3);
                    if (!empty($matches)) {
                        // Auto-link the best match (first one — highest confidence)
                        $this->transactionService->linkTransactions($txId, $matches[0]->getId(), $userId);
                        $transfersLinked++;
                    }
                } catch (\Throwable $e) {
                    // Silently skip — match may not exist or already linked.
                    // Throwable, not Exception: a PHP Error here surfaced to the
                    // import screen after the rows were already saved (#314)
                }
            }
        }

        if (!empty($presetTransferLegs)) {
            $transfersLinked += $this->linkPresetTransferLegs($userId, $presetTransferLegs, $resolvedAccounts);
        }

        // Auto-mark bills paid from matching imported transactions (#274).
        // Best-effort: a matching failure must never fail the import.
        $billsMarkedPaid = 0;
        try {
            $billsMarkedPaid = $this->billService->autoMatchPaidFromImport($userId, $createdForBillMatch);
        } catch (\Exception $e) {
            // ignore
        }

        if ($hasAccountColumn) {
            $result = [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'totalProcessed' => count($data),
                'accountResults' => array_values($perAccountResults),
                'accountsCreated' => $accountsCreated,
                'billsMarkedPaid' => $billsMarkedPaid,
            ];
        } else {
            $result = [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'totalProcessed' => count($data),
                'billsMarkedPaid' => $billsMarkedPaid,
                'accountResults' => [[
                    'destinationAccountId' => $accountId,
                    'destinationAccountName' => $account->getName(),
                    'imported' => $imported,
                    'skipped' => $skipped,
                ]],
            ];
        }

        if ($categoriesCreated > 0) {
            $result['categoriesCreated'] = $categoriesCreated;
        }
        if ($tagsCreated > 0) {
            $result['tagsCreated'] = $tagsCreated;
        }
        if ($transfersLinked > 0) {
            $result['transfersLinked'] = $transfersLinked;
        }

        return $result;
    }

    /**
     * Whether a parsed row would import as a transaction under this mapping.
     *
     * Deliberately asks the normalizer rather than testing the columns
     * itself - "valid" has to mean exactly what the import loop means by it,
     * or the two drift.
     *
     * @param array<int|string, mixed> $row
     * @param array<string, mixed> $mapping
     */
    private function rowIsTransaction(array $row, array $mapping): bool {
        try {
            $this->normalizer->mapRowToTransaction($row, $mapping);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Currency for an account the importer creates when the file names none.
     *
     * This was hardcoded 'USD', so a CHF user importing a file with an
     * account column silently got dollar accounts (#333).
     */
    private function defaultCurrency(string $userId): string {
        $currency = strtoupper(trim((string) ($this->settingService->get($userId, 'default_currency') ?? '')));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : 'USD';
    }

    /**
     * Resolve accounts from preset metadata or manual account column mapping.
     * Creates missing accounts on execute (dryRun=false).
     *
     * @param string $userId
     * @param array $data Parsed CSV data rows
     * @param ImportPresetInterface|null $preset The active preset (null for manual mapping)
     * @param bool $dryRun If true, don't create accounts (preview mode)
     * @param array $mapping Column mapping (used when preset is null)
     * @return array{resolved: array<string, int>, created: array}
     */
    private function resolvePresetAccounts(
        string $userId,
        array $data,
        ?ImportPresetInterface $preset,
        bool $dryRun = false,
        array $mapping = []
    ): array {
        $accountColumn = $preset
            ? ($preset->getOptions()['accountColumn'] ?? null)
            : ($mapping['account'] ?? null);
        if ($accountColumn === null || $accountColumn === '') {
            return ['resolved' => [], 'created' => []];
        }

        $currencyColumn = $mapping['currency'] ?? ($preset ? 'Currency' : null);
        $defaultCurrency = $this->defaultCurrency($userId);

        // Collect unique accounts with their currencies
        $accountInfo = [];
        foreach ($data as $row) {
            if ($preset instanceof HeaderMappedPresetInterface) {
                // The preset decides which account a row belongs to (a
                // Firefly III deposit lands in its destination column, a
                // withdrawal leaves its source) and may know its type and
                // currency from the file.
                $processed = $preset->postProcessRow([], $row);
                $name = trim((string) ($processed['_accountName'] ?? ''));
                if ($processed === null || $name === '' || isset($accountInfo[$name])) {
                    continue;
                }
                $currency = strtoupper(trim((string) ($processed['_currency'] ?? '')));
                $accountInfo[$name] = [
                    'name' => $name,
                    'currency' => preg_match('/^[A-Z]{3}$/', $currency) === 1 ? $currency : $defaultCurrency,
                    'type' => (string) ($processed['_accountType'] ?? $preset->inferAccountType($name)),
                ];
                continue;
            }

            $name = trim($row[$accountColumn] ?? '');
            if ($name === '') {
                continue;
            }

            // Skip transfer rows (preset only)
            if ($preset) {
                $processed = $preset->postProcessRow([], $row);
                if ($processed === null) {
                    continue;
                }
            } elseif (!$this->rowIsTransaction($row, $mapping)) {
                // Only a row that would import as a transaction may name an
                // account. With a manual mapping the header row survives the
                // parse whenever "first row is a header" is off, and its own
                // text then became an account: #333 ended up with a USD
                // account literally called "Account:", the column's title.
                // Presets are exempt - they always drop the header row and
                // filter with postProcessRow above.
                continue;
            }

            if (!isset($accountInfo[$name])) {
                $currency = ($currencyColumn !== null && $currencyColumn !== '') && !empty($row[$currencyColumn])
                    ? strtoupper(trim($row[$currencyColumn]))
                    : $defaultCurrency;
                $accountInfo[$name] = [
                    'name' => $name,
                    'currency' => $currency,
                    'type' => $preset
                        ? $preset->inferAccountType($name)
                        : $this->inferAccountType($name),
                ];
            }
        }

        $resolved = [];
        $created = [];

        foreach ($accountInfo as $name => $info) {
            $name = (string) $name;
            $existing = $this->accountMapper->findByName($userId, $name);
            if ($existing) {
                $resolved[$name] = $existing->getId();
                $info['exists'] = true;
                $info['existingId'] = $existing->getId();
            } else {
                $info['exists'] = false;
                if (!$dryRun) {
                    $account = $this->accountService->create(
                        $userId,
                        $info['name'],
                        $info['type'],
                        0.0,
                        $info['currency']
                    );
                    $resolved[$name] = $account->getId();
                    $info['createdId'] = $account->getId();
                }
            }
            $created[] = $info;
        }

        return ['resolved' => $resolved, 'created' => $created];
    }

    /**
     * Infer account type from account name using keyword matching.
     * Used for manual CSV imports without a preset.
     */
    private function inferAccountType(string $accountName): string {
        $map = [
            'cash' => 'cash',
            'checking' => 'checking',
            'savings' => 'savings',
            'investment' => 'investment',
            'credit card' => 'credit_card',
            'credit' => 'credit_card',
            'loan' => 'loan',
            'mortgage' => 'mortgage',
            'crypto' => 'cryptocurrency',
            'bitcoin' => 'cryptocurrency',
            'line of credit' => 'line_of_credit',
        ];
        $lower = strtolower(trim($accountName));
        if (isset($map[$lower])) {
            return $map[$lower];
        }
        foreach ($map as $keyword => $type) {
            if (str_contains($lower, $keyword)) {
                return $type;
            }
        }
        return 'checking';
    }

    /**
     * Count how many of the parsed transactions resolved to a category, over the
     * WHOLE parsed set (not just the 50-row preview sample). The preview payload
     * only ships a sample, so the "Auto-categorized" tile must take its count
     * from here to be accurate for large imports (#285 audit).
     *
     * @param array $transactions Full set of parsed transactions
     */
    private function countCategorized(array $transactions): int {
        return count(array_filter(
            $transactions,
            static fn ($t) => !empty($t['categoryId']) || !empty($t['_categoryName'])
        ));
    }

    /**
     * Resolve category from preset metadata (_categoryName / _tagName).
     * Uses cache to avoid repeated DB lookups within the same import.
     *
     * @param string $userId
     * @param array $transaction Transaction with _categoryName and optional _tagName
     * @param array &$categoryCache Cache of resolved category IDs
     * @param int &$categoriesCreated Counter for newly created categories
     * @return int|null The resolved category ID
     */
    private function resolvePresetCategory(string $userId, array $transaction, array &$categoryCache, int &$categoriesCreated): ?int {
        $categoryName = $transaction['_categoryName'];
        $type = ($transaction['type'] === 'credit') ? 'income' : 'expense';

        $parentName = trim((string) ($transaction['_categoryParent'] ?? ''));
        if ($parentName !== '') {
            return $this->resolvePresetSubcategory($userId, $parentName, $categoryName, $type, $categoryCache, $categoriesCreated);
        }

        $cacheKey = $type . '::' . $categoryName;
        if (isset($categoryCache[$cacheKey])) {
            return $categoryCache[$cacheKey];
        }

        // Also check the opposite type — Toshl uses the same category name for both
        // income and expense, and we want to reuse existing categories rather than
        // creating duplicates with different types
        $oppositeType = ($type === 'income') ? 'expense' : 'income';
        $oppositeCacheKey = $oppositeType . '::' . $categoryName;
        if (isset($categoryCache[$oppositeCacheKey])) {
            $categoryCache[$cacheKey] = $categoryCache[$oppositeCacheKey];
            return $categoryCache[$oppositeCacheKey];
        }

        $category = $this->categoryService->findOrCreate($userId, $categoryName, $type);
        $categoryCache[$cacheKey] = $category->getId();

        // Only count as "created" if this category was created within the last few seconds
        // (findOrCreate sets timestamps on new categories)
        $createdAt = $category->getCreatedAt();
        if ($createdAt && (time() - strtotime($createdAt)) < 10) {
            $categoriesCreated++;
        }

        return $category->getId();
    }

    /**
     * Resolve a two-level preset category (YNAB's "Group: Category", Actual's
     * category group) to the child category under a parent of the group's
     * name, creating either as needed.
     *
     * Like the one-level case, a name already resolved for the other type in
     * this import is reused rather than duplicated: YNAB and Actual file
     * refunds under the same category as the spending they reverse.
     */
    private function resolvePresetSubcategory(
        string $userId,
        string $parentName,
        string $categoryName,
        string $type,
        array &$categoryCache,
        int &$categoriesCreated
    ): int {
        $oppositeType = ($type === 'income') ? 'expense' : 'income';
        $key = $parentName . '::' . $categoryName;
        foreach ([$type, $oppositeType] as $candidate) {
            if (isset($categoryCache['sub::' . $candidate . '::' . $key])) {
                return $categoryCache['sub::' . $type . '::' . $key] = $categoryCache['sub::' . $candidate . '::' . $key];
            }
        }

        $parentId = null;
        foreach ([$type, $oppositeType] as $candidate) {
            if (isset($categoryCache['parent::' . $candidate . '::' . $parentName])) {
                $parentId = $categoryCache['parent::' . $candidate . '::' . $parentName];
                break;
            }
        }
        if ($parentId === null) {
            $parent = $this->categoryService->findOrCreate($userId, $parentName, $type);
            $this->countIfJustCreated($parent, $categoriesCreated);
            $parentId = $parent->getId();
            $categoryCache['parent::' . $type . '::' . $parentName] = $parentId;
        }

        $category = $this->categoryService->findOrCreateSubcategory($userId, $categoryName, $type, $parentId);
        $this->countIfJustCreated($category, $categoriesCreated);
        $categoryCache['sub::' . $type . '::' . $key] = $category->getId();

        return $category->getId();
    }

    /**
     * Count a category from findOrCreate*() as created by this import when
     * its timestamp is only seconds old (the same test the one-level path uses).
     */
    private function countIfJustCreated(\OCA\Budget\Db\Category $category, int &$categoriesCreated): void {
        $createdAt = $category->getCreatedAt();
        if ($createdAt && (time() - strtotime($createdAt)) < 10) {
            $categoriesCreated++;
        }
    }

    /**
     * How a preset row's category is listed in the import preview.
     */
    private function presetCategoryLabel(array $transaction): string {
        $name = (string) $transaction['_categoryName'];
        $parent = trim((string) ($transaction['_categoryParent'] ?? ''));
        return $parent !== '' ? $parent . ' / ' . $name : $name;
    }

    /**
     * Link the two sides of each transfer an app-export preset recognised.
     *
     * Only rows created by this import take part, so nothing already in the
     * ledger can be paired by mistake. Two sides match when they are in
     * different accounts, go in opposite directions for the same amount,
     * are dated within three days of each other, and, where the file names
     * the other account, name each other's. The closest date wins. A side
     * with no partner stays an ordinary transaction.
     *
     * @param array<int, array{id: int, accountId: int, amount: float, type: string, date: string, peer: string}> $legs
     * @param array<string, int> $accountIdsByName Accounts this import resolved, by name
     * @return int Transfers linked
     */
    private function linkPresetTransferLegs(string $userId, array $legs, array $accountIdsByName): int {
        // Bucket by amount so a large file does not compare every pair
        $byAmount = [];
        foreach ($legs as $i => $leg) {
            $byAmount[number_format($leg['amount'], 2, '.', '')][] = $i;
        }

        // null: the file does not name the other account, so any will do.
        // false: it names one that is not an account in this import, so the
        // row is not a transfer between two of them after all.
        $peerId = static function (array $leg) use ($accountIdsByName): int|false|null {
            if ($leg['peer'] === '') {
                return null;
            }
            return $accountIdsByName[$leg['peer']] ?? false;
        };

        $linked = 0;
        $used = [];
        foreach ($legs as $i => $a) {
            if (isset($used[$i])) {
                continue;
            }
            $aPeer = $peerId($a);
            if ($aPeer === false) {
                continue;
            }

            $best = null;
            $bestGap = null;
            foreach ($byAmount[number_format($a['amount'], 2, '.', '')] as $j) {
                $b = $legs[$j];
                if ($j === $i || isset($used[$j]) || $b['accountId'] === $a['accountId'] || $b['type'] === $a['type']) {
                    continue;
                }
                $bPeer = $peerId($b);
                if ($bPeer === false
                    || ($aPeer !== null && $aPeer !== $b['accountId'])
                    || ($bPeer !== null && $bPeer !== $a['accountId'])) {
                    continue;
                }
                $gap = abs((int) strtotime($a['date']) - (int) strtotime($b['date']));
                if ($gap > 3 * 86400) {
                    continue;
                }
                if ($best === null || $gap < $bestGap) {
                    $best = $j;
                    $bestGap = $gap;
                }
            }

            if ($best === null) {
                continue;
            }
            try {
                $this->transactionService->linkTransactions($a['id'], $legs[$best]['id'], $userId);
                $used[$i] = true;
                $used[$best] = true;
                $linked++;
            } catch (\Throwable $e) {
                // Already linked by an import rule, or the two accounts are in
                // different currencies: both stay ordinary transactions.
            }
        }

        return $linked;
    }

    /**
     * Resolve tags from preset metadata (_tagNames) into tag IDs.
     * Creates a "Tags" tag set per category on first use, then creates tags within it.
     *
     * @param string $userId
     * @param int $categoryId The category to create the tag set under
     * @param string[] $tagNames Tag names to resolve
     * @param array &$tagCache Cache of tagSetId and tag name → tag ID
     * @param int &$tagsCreated Counter for newly created tags
     * @return int[] Resolved tag IDs
     */
    private function resolvePresetTags(string $userId, int $categoryId, array $tagNames, array &$tagCache, int &$tagsCreated): array {
        // Find or create the "Tags" tag set for this category
        $tagSetCacheKey = 'tagset::' . $categoryId;
        if (!isset($tagCache[$tagSetCacheKey])) {
            $existingTagSets = $this->tagSetService->findByCategory($categoryId, $userId);
            $tagSet = null;
            foreach ($existingTagSets as $ts) {
                if ($ts->getName() === 'Tags') {
                    $tagSet = $ts;
                    break;
                }
            }
            if ($tagSet === null) {
                $tagSet = $this->tagSetService->create($userId, $categoryId, 'Tags');
            }
            $tagCache[$tagSetCacheKey] = $tagSet->getId();

            // Pre-warm tag cache with all existing tags in this tag set
            // This avoids repeated DB queries when processing thousands of rows
            $existingTags = $this->tagSetService->getTagSetWithTags($tagSet->getId(), $userId)->getTags();
            foreach ($existingTags as $existingTag) {
                $tagCache[$tagSet->getId() . '::' . $existingTag->getName()] = $existingTag->getId();
            }
        }
        $tagSetId = $tagCache[$tagSetCacheKey];

        $tagIds = [];
        foreach ($tagNames as $tagName) {
            $tagCacheKey = $tagSetId . '::' . $tagName;
            if (isset($tagCache[$tagCacheKey])) {
                $tagIds[] = $tagCache[$tagCacheKey];
                continue;
            }

            // Tag not in cache — create it (it doesn't exist in DB either,
            // since we pre-warmed from all existing tags)
            $found = $this->tagSetService->createTag($tagSetId, $userId, $tagName);
            $tagsCreated++;

            $tagCache[$tagCacheKey] = $found->getId();
            $tagIds[] = $found->getId();
        }

        return $tagIds;
    }

    /**
     * Match source accounts from import file to existing user accounts.
     * Compares account numbers, routing numbers, and IBANs.
     *
     * @param string $userId The user ID
     * @param array $sourceAccounts Source accounts from the import file
     * @return array Map of sourceAccountId => destinationAccountId
     */
    /**
     * Fill each source account's suggestedMatch from previously remembered
     * routings, without overriding an existing (e.g. OFX account-number) match.
     * Remembered destinations that no longer exist for the user are ignored.
     *
     * @param array<int, array> $sourceAccounts
     * @return array<int, array>
     */
    private function applyRememberedAccountLinks(string $userId, string $format, array $sourceAccounts): array {
        if (!$this->isMultiAccountFormat($format) || empty($sourceAccounts)) {
            return $sourceAccounts;
        }

        $links = $this->accountLinkService->recall($userId, $format);
        if (empty($links)) {
            return $sourceAccounts;
        }

        // Only suggest accounts that still exist for the user — and are open:
        // an old statement must not preselect a closed account (#372).
        $validIds = [];
        foreach ($this->accountMapper->findOpen($userId) as $account) {
            $validIds[$account->getId()] = true;
        }

        foreach ($sourceAccounts as &$source) {
            if (!empty($source['suggestedMatch'])) {
                continue;
            }
            $key = $source['accountId'] ?? null;
            $remembered = $key !== null ? ($links[$key] ?? null) : null;
            if ($remembered !== null && isset($validIds[$remembered])) {
                $source['suggestedMatch'] = $remembered;
            }
        }
        unset($source);

        return $sourceAccounts;
    }

    private function matchSourceAccounts(string $userId, array $sourceAccounts): array {
        // Closed accounts are hidden from the mapping picker, so matching one
        // by account number would select something the user cannot see (#372).
        $userAccounts = $this->accountMapper->findOpen($userId);
        $matches = [];

        foreach ($sourceAccounts as $source) {
            $sourceAccountId = $source['accountId'] ?? null;
            $sourceBankId = $source['bankId'] ?? null;

            if (!$sourceAccountId) {
                continue;
            }

            foreach ($userAccounts as $account) {
                $matched = false;

                // Match by account number
                $accountNumber = $account->getAccountNumber();
                if ($accountNumber && $sourceAccountId === $accountNumber) {
                    $matched = true;
                }

                // Match by routing number (bankId in OFX)
                if (!$matched && $sourceBankId) {
                    $routingNumber = $account->getRoutingNumber();
                    if ($routingNumber && $sourceBankId === $routingNumber) {
                        // Routing number alone isn't enough - need account number too
                        // But if routing matches and we have partial account match, use it
                        if ($accountNumber && str_ends_with($accountNumber, substr($sourceAccountId, -4))) {
                            $matched = true;
                        }
                    }
                }

                // Match by IBAN (source accountId might be an IBAN)
                if (!$matched) {
                    $iban = $account->getIban();
                    if ($iban && $sourceAccountId === $iban) {
                        $matched = true;
                    }
                }

                if ($matched) {
                    $matches[$sourceAccountId] = $account->getId();
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * Parse the file into the rows a single-account CSV import walks.
     *
     * Header-mapped presets key rows by column name; every other import
     * keys them by position, remapped to Toshl's canonical headers when that
     * preset is active.
     *
     * @param int $dropped Rows a header-mapped preset dropped while reading
     */
    private function readImportRows(string $content, string $format, string $delimiter, array $mapping, ?ImportPresetInterface $preset, int &$dropped): array {
        if ($preset instanceof HeaderMappedPresetInterface) {
            return $this->readHeaderMappedRows($content, $format, $preset, $dropped);
        }

        $data = $this->parserFactory->parse($content, $format, null, $delimiter, $preset || ($mapping['skipFirstRow'] ?? true));

        // Remap CSV headers by position when preset provides canonical headers
        // (makes import language-independent — e.g., Toshl exports in German)
        if ($preset) {
            $data = $this->remapHeaders($data, $preset);
        }

        return $data;
    }

    /**
     * Read an app export whose columns the preset names rather than numbers.
     *
     * The header row is matched case-insensitively against the preset's
     * required columns; a file missing any of them is refused with the list,
     * rather than imported as blanks. When the header does not split on the
     * preset's delimiter the other usual ones are tried: YNAB exports a plan
     * with a comma decimal as tab-separated text.
     *
     * Rows the preset expands into several (a Firefly III transfer, one per
     * account) keep the first in place and append the rest after the file's
     * own rows, so every file row keeps its position for error messages.
     *
     * @param int $dropped Set to the number of rows the preset dropped
     * @return array<int, array<string, string>>
     */
    private function readHeaderMappedRows(string $content, string $format, HeaderMappedPresetInterface $preset, int &$dropped): array {
        if ($format !== 'csv') {
            throw new \Exception($this->l->t('The %1$s format reads CSV files only', [$preset->getName()]));
        }

        $required = [];
        foreach ($preset->getRequiredHeaders() as $header) {
            $required[mb_strtolower($header)] = $header;
        }

        $best = null;
        foreach (array_values(array_unique([$preset->getDelimiter(), "\t", ';', ','])) as $delimiter) {
            $records = $this->parserFactory->parseCsvRecords($content, $delimiter);
            $header = array_map(static fn($h) => trim((string) $h), $records[0] ?? []);
            $present = array_flip(array_map('mb_strtolower', $header));
            $missing = array_diff_key($required, $present);
            if ($best === null || count($missing) < count($best['missing'])) {
                $best = ['records' => $records, 'header' => $header, 'missing' => $missing];
            }
            if ($missing === []) {
                break;
            }
        }

        if ($best['missing'] !== []) {
            throw new \Exception($this->l->t(
                'This file does not look like a %1$s export. Columns missing: %2$s',
                [$preset->getName(), implode(', ', array_values($best['missing']))]
            ));
        }

        // Key columns by the preset's own spelling where it names them
        $keys = array_map(static fn($h) => $required[mb_strtolower($h)] ?? $h, $best['header']);

        $rows = [];
        $extra = [];
        $dropped = 0;
        foreach (array_slice($best['records'], 1) as $record) {
            $row = [];
            foreach ($keys as $i => $key) {
                $row[$key] = $record[$i] ?? '';
            }
            $expanded = array_values($preset->expandRow($row));
            if ($expanded === []) {
                $dropped++;
                continue;
            }
            $rows[] = array_shift($expanded);
            foreach ($expanded as $more) {
                $extra[] = $more;
            }
        }

        return array_merge($rows, $extra);
    }

    /**
     * Remap CSV row keys from file headers to canonical preset headers (by position).
     * This makes preset imports language-independent — e.g., a German Toshl export
     * with "Datum,Konto,Kategorie,..." is remapped to "Date,Account,Category,...".
     *
     * @param array $data Parsed CSV rows (header-keyed associative arrays)
     * @param ImportPresetInterface $preset The active preset
     * @return array Rows re-keyed with canonical headers
     */
    private function remapHeaders(array $data, ImportPresetInterface $preset): array {
        $expectedHeaders = $preset->getExpectedHeaders();
        if ($expectedHeaders === null || empty($data)) {
            return $data;
        }

        return array_map(function ($row) use ($expectedHeaders) {
            $values = array_values($row);
            $remapped = [];
            foreach ($expectedHeaders as $i => $header) {
                $remapped[$header] = $values[$i] ?? '';
            }
            return $remapped;
        }, $data);
    }

    /**
     * Decode stored bytes to UTF-8. Import files are stored exactly as
     * uploaded, so every read goes through here; `$encoding` carries the
     * user's override from the import screen when they have set one (#371).
     */
    private function ensureUtf8(string $content, ?string $encoding = null): string {
        return (new EncodingNormalizer())->toUtf8($content, $encoding);
    }
}
