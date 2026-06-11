<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Import\DuplicateDetector;
use OCA\Budget\Service\Import\FileValidator;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use OCA\Budget\Service\Import\ParserFactory;
use OCA\Budget\Service\Import\Preset\ImportPresetInterface;
use OCA\Budget\Service\Import\Preset\PresetRegistry;
use OCA\Budget\Service\Import\TransactionNormalizer;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\IL10N;

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
    private IL10N $l;

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
        IL10N $l
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
        $this->l = $l;
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
            // Store file
            $importsFolder = $this->getOrCreateImportsFolder();
            $file = $importsFolder->newFile($fileId);
            $content = file_get_contents($tmpPath);
            $content = $this->ensureUtf8($content);
            $file->putContent($content);

            // Detect CSV delimiter if applicable
            $delimiter = ',';
            if ($format === 'csv') {
                $delimiter = $this->fileValidator->detectDelimiter($content);
            }

            // Parse preview
            $preview = $this->parserFactory->parse($content, $format, 5, $delimiter);

            // Build response based on format
            return $this->buildUploadResponse($userId, $fileId, $fileName, $format, $content, $preview, $fileSize, $delimiter);

        } catch (\Exception $e) {
            throw new \Exception($this->l->t('Failed to process upload: %1$s', [$e->getMessage()]));
        }
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
        ?string $presetId = null
    ): array {
        $file = $this->getImportFile($fileId);
        $format = $this->parserFactory->detectFormat($fileId);
        $content = $file->getContent();

        if (($format === 'ofx' || $format === 'qif') && !empty($accountMapping)) {
            return $this->previewMultiAccountImport($userId, $content, $format, $accountMapping, $skipDuplicates);
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
        ?string $presetId = null
    ): array {
        $file = $this->getImportFile($fileId);
        $format = $this->parserFactory->detectFormat($fileId);
        $content = $file->getContent();

        if (($format === 'ofx' || $format === 'qif') && !empty($accountMapping)) {
            $result = $this->executeMultiAccountImport($userId, $fileId, $content, $format, $accountMapping, $skipDuplicates, $applyRules);
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

    public function validateFile(string $userId, string $fileId): array {
        $file = $this->getImportFile($fileId);
        $format = $this->parserFactory->detectFormat($fileId);

        try {
            $preview = $this->parserFactory->parse($file->getContent(), $format, 10);

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

    private function getImportFile(string $fileId) {
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

    private function buildUploadResponse(string $userId, string $fileId, string $fileName, string $format, string $content, array $preview, int $fileSize, string $delimiter = ','): array {
        $columns = [];
        $rawPreview = [];
        $sourceAccounts = [];

        if ($format === 'csv') {
            $content = $this->parserFactory->stripBom($content);
            $lines = explode("\n", $content);
            $dataWidth = $this->parserFactory->detectDataWidth($lines, $delimiter);
            $headers = [];
            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                $row = str_getcsv($line, $delimiter, '"', '');
                // Skip rows that don't match the expected data width (metadata/preamble)
                if ($dataWidth > 0 && count($row) !== $dataWidth) {
                    continue;
                }
                if (empty($headers)) {
                    $headers = array_map('trim', $row);
                    $columns = $headers;
                    $rawPreview[] = $headers;
                } else {
                    $rawPreview[] = $row;
                    if (count($rawPreview) > 6) break;
                }
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
        } elseif ($format === 'qif') {
            $parsedQif = $this->parserFactory->parseFull($content, 'qif');
            foreach ($parsedQif['accounts'] as $account) {
                $sourceAccounts[] = [
                    'accountId' => $account['name'] ?? $account['accountId'] ?? 'Unknown',
                    'type' => $account['type'] ?? 'unknown',
                    'transactionCount' => count($account['transactions'] ?? []),
                ];
            }
            $columns = ['date', 'amount', 'payee', 'memo', 'category', 'reference'];
            $rawPreview = [$columns];
            foreach ($preview as $row) {
                $rawPreview[] = [
                    $row['date'] ?? '',
                    $row['amount'] ?? '',
                    $row['payee'] ?? '',
                    $row['memo'] ?? '',
                    $row['category'] ?? '',
                    $row['reference'] ?? $row['number'] ?? '',
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
            'recordCount' => $this->parserFactory->countRows($content, $format, $delimiter),
            'size' => $fileSize,
            'delimiter' => $format === 'csv' ? $delimiter : null,
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

    private function previewMultiAccountImport(string $userId, string $content, string $format, array $accountMapping, bool $skipDuplicates): array {
        $parsedData = $this->parserFactory->parseFull($content, $format);
        $transactions = [];
        $duplicates = 0;
        $errors = [];
        $accountSummaries = [];
        $hashCounts = [];

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
                    $transaction = $this->normalizer->mapOfxTransaction($txn);
                    $importId = $this->occurrenceAwareImportId(
                        $this->normalizer->generateImportId('preview', $sourceId . '_' . $index, $transaction),
                        (int)$destAccountId,
                        $hashCounts
                    );
                    $isDuplicate = $this->duplicateDetector->isDuplicate((int)$destAccountId, $transaction, $importId);

                    if ($skipDuplicates && $isDuplicate) {
                        $duplicates++;
                        $accountSummaries[$sourceId]['duplicates']++;
                        continue;
                    }

                    if ($this->ruleApplicator) {
                        $transaction = $this->ruleApplicator->applyRules($userId, $transaction);
                    }

                    $transactions[] = array_merge($transaction, [
                        'rowIndex' => $index,
                        'sourceAccountId' => $sourceId,
                        'destinationAccountId' => $destAccountId,
                        'isDuplicate' => $isDuplicate,
                    ]);

                    if ($isDuplicate) {
                        $duplicates++;
                        $accountSummaries[$sourceId]['duplicates']++;
                    } else {
                        $accountSummaries[$sourceId]['transactionCount']++;
                    }
                } catch (\Exception $e) {
                    $errors[] = ['row' => $index, 'sourceAccountId' => $sourceId, 'error' => $e->getMessage()];
                }
            }
        }

        $totalRows = array_sum(array_map(fn($a) => count($a['transactions']), $parsedData['accounts']));

        return [
            'transactions' => array_slice($transactions, 0, 50),
            'totalRows' => $totalRows,
            'validTransactions' => count($transactions),
            'duplicates' => $duplicates,
            'errors' => $errors,
            'accountSummaries' => array_values($accountSummaries),
        ];
    }

    private function previewSingleAccountImport(string $userId, string $content, string $format, array $mapping, ?int $accountId, bool $skipDuplicates, string $delimiter = ',', ?string $presetId = null): array {
        // Load preset if specified
        $preset = $presetId ? $this->presetRegistry->get($presetId) : null;
        $hasAccountColumn = ($preset && !empty($preset->getOptions()['accountColumn'])) || !empty($mapping['account']);

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

        $data = $this->parserFactory->parse($content, $format, null, $delimiter);

        // Remap CSV headers by position when preset provides canonical headers
        // (makes import language-independent — e.g., Toshl exports in German)
        if ($preset) {
            $data = $this->remapHeaders($data, $preset);
        }

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
        $skippedByPreset = 0;
        $hashCounts = [];

        // Detect date format from all rows before processing individually (unless preset set it)
        if (!$preset) {
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
                        $catKey = $transaction['_categoryName'];
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
                        $errors[] = ['row' => $index, 'error' => $this->l->t('Missing account name'), 'data' => $row];
                        continue;
                    }
                    // Find existing account ID for duplicate detection in preview
                    $existingAccount = $this->accountMapper->findByName($userId, $txAccountName);
                    $txAccountId = $existingAccount ? $existingAccount->getId() : null;
                }

                if ($txAccountId) {
                    $importId = $this->occurrenceAwareImportId(
                        $this->normalizer->generateImportId('preview', $index, $transaction),
                        $txAccountId,
                        $hashCounts
                    );
                    $isDuplicate = $this->duplicateDetector->isDuplicate($txAccountId, $transaction, $importId);

                    if ($skipDuplicates && $isDuplicate) {
                        $duplicates++;
                        continue;
                    }

                    $transaction = $this->ruleApplicator->applyRules($userId, $transaction);
                    $transactions[] = array_merge($transaction, [
                        'rowIndex' => $index,
                        'isDuplicate' => $isDuplicate,
                    ]);

                    if ($isDuplicate) {
                        $duplicates++;
                    }
                } else {
                    // Account doesn't exist yet — treat as new (not duplicate)
                    $transaction = $this->ruleApplicator->applyRules($userId, $transaction);
                    $transactions[] = array_merge($transaction, [
                        'rowIndex' => $index,
                        'isDuplicate' => false,
                    ]);
                }
            } catch (\Exception $e) {
                $errors[] = ['row' => $index, 'error' => $e->getMessage(), 'data' => $row];
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

        return $result;
    }

    private function executeMultiAccountImport(string $userId, string $fileId, string $content, string $format, array $accountMapping, bool $skipDuplicates, bool $applyRules): array {
        $parsedData = $this->parserFactory->parseFull($content, $format);
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $transferLinkIds = [];
        $accountResults = [];
        $hashCounts = [];
        $touchedAccounts = [];

        foreach ($parsedData['accounts'] as $sourceAccount) {
            $sourceId = $sourceAccount['accountId'];
            $destAccountId = $accountMapping[$sourceId] ?? null;

            if (!$destAccountId) continue;

            $destAccount = $this->accountMapper->find((int)$destAccountId, $userId);
            $accountResults[$sourceId] = [
                'sourceAccountId' => $sourceId,
                'destinationAccountId' => $destAccountId,
                'destinationAccountName' => $destAccount->getName(),
                'imported' => 0,
                'skipped' => 0,
            ];

            foreach ($sourceAccount['transactions'] as $index => $txn) {
                try {
                    $transaction = $this->normalizer->mapOfxTransaction($txn);
                    $importId = $this->occurrenceAwareImportId(
                        $this->normalizer->generateImportId($fileId, $sourceId . '_' . $index, $transaction),
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
                        $transaction = $this->ruleApplicator->applyRules($userId, $transaction);
                    }

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
                    $errors[] = ['row' => $index + 1, 'sourceAccountId' => $sourceId, 'error' => $e->getMessage()];
                }
            }
        }

        // Balance updates were deferred per-row; recompute once per account
        foreach (array_keys($touchedAccounts) as $touchedAccountId) {
            $this->transactionService->recalculateAccountBalance($touchedAccountId, $userId);
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
        ];
    }

    private function executeSingleAccountImport(string $userId, string $fileId, string $content, string $format, array $mapping, ?int $accountId, bool $skipDuplicates, bool $applyRules, string $delimiter = ',', ?string $presetId = null): array {
        // Load preset if specified
        $preset = $presetId ? $this->presetRegistry->get($presetId) : null;
        $hasAccountColumn = ($preset && !empty($preset->getOptions()['accountColumn'])) || !empty($mapping['account']);

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

        $data = $this->parserFactory->parse($content, $format, null, $delimiter);

        // Remap CSV headers by position when preset provides canonical headers
        if ($preset) {
            $data = $this->remapHeaders($data, $preset);
        }

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
        $skipped = 0;
        $errors = [];
        $categoriesCreated = 0;
        $transferLinkIds = [];
        $hashCounts = [];
        $touchedAccounts = [];

        // Detect date format from all rows before processing individually (unless preset set it)
        if (!$preset) {
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

                // Determine which account this transaction goes to
                $txAccountId = $accountId;
                if ($hasAccountColumn) {
                    $txAccountName = $transaction['_accountName'] ?? '';
                    if ($txAccountName === '' || !isset($resolvedAccounts[$txAccountName])) {
                        $errors[] = ['row' => $index + 1, 'error' => $this->l->t('Could not resolve account: %1$s', [$txAccountName])];
                        continue;
                    }
                    $txAccountId = $resolvedAccounts[$txAccountName];
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
                    $transaction = $this->ruleApplicator->applyRules($userId, $transaction);
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

                $imported++;

                // Track per-account stats
                if ($hasAccountColumn) {
                    $txAccountName = $transaction['_accountName'] ?? 'Unknown';
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
                        $bestMatch = $matches[0];
                        $this->transactionService->linkTransactions($txId, $bestMatch['id'], $userId);
                        $transfersLinked++;
                    }
                } catch (\Exception $e) {
                    // Silently skip — match may not exist or already linked
                }
            }
        }

        if ($hasAccountColumn) {
            $result = [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'totalProcessed' => count($data),
                'accountResults' => array_values($perAccountResults),
                'accountsCreated' => $accountsCreated,
            ];
        } else {
            $result = [
                'imported' => $imported,
                'skipped' => $skipped,
                'errors' => $errors,
                'totalProcessed' => count($data),
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

        return $result;
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
        if (!$accountColumn) {
            return ['resolved' => [], 'created' => []];
        }

        $currencyColumn = $mapping['currency'] ?? ($preset ? 'Currency' : null);

        // Collect unique accounts with their currencies
        $accountInfo = [];
        foreach ($data as $row) {
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
            }

            if (!isset($accountInfo[$name])) {
                $currency = $currencyColumn && !empty($row[$currencyColumn])
                    ? strtoupper(trim($row[$currencyColumn]))
                    : 'USD';
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
        if (($format !== 'ofx' && $format !== 'qif') || empty($sourceAccounts)) {
            return $sourceAccounts;
        }

        $links = $this->accountLinkService->recall($userId, $format);
        if (empty($links)) {
            return $sourceAccounts;
        }

        // Only suggest accounts that still exist for the user.
        $validIds = [];
        foreach ($this->accountMapper->findAll($userId) as $account) {
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
        $userAccounts = $this->accountMapper->findAll($userId);
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
     * Detect file encoding and convert to UTF-8 if needed.
     * Handles common bank export encodings (ISO-8859-1, Windows-1252, etc.).
     */
    private function ensureUtf8(string $content): string {
        if (mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        // Try common bank export encodings
        $encodings = ['ISO-8859-1', 'Windows-1252', 'ISO-8859-15'];
        foreach ($encodings as $encoding) {
            if (mb_check_encoding($content, $encoding)) {
                return mb_convert_encoding($content, 'UTF-8', $encoding);
            }
        }

        // Last resort: force UTF-8 with substitution
        return mb_convert_encoding($content, 'UTF-8', 'UTF-8');
    }
}
