<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Import\DuplicateDetector;
use OCA\Budget\Service\Import\FileValidator;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use OCA\Budget\Service\Import\ParserFactory;
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
    private FileValidator $fileValidator;
    private ParserFactory $parserFactory;
    private TransactionNormalizer $normalizer;
    private DuplicateDetector $duplicateDetector;
    private ImportRuleApplicator $ruleApplicator;
    private PresetRegistry $presetRegistry;
    private CategoryService $categoryService;
    private TagSetService $tagSetService;
    private TransactionTagService $transactionTagService;
    private IL10N $l;

    public function __construct(
        IAppData $appData,
        TransactionService $transactionService,
        TransactionMapper $transactionMapper,
        AccountMapper $accountMapper,
        FileValidator $fileValidator,
        ParserFactory $parserFactory,
        TransactionNormalizer $normalizer,
        DuplicateDetector $duplicateDetector,
        ImportRuleApplicator $ruleApplicator,
        PresetRegistry $presetRegistry,
        CategoryService $categoryService,
        TagSetService $tagSetService,
        TransactionTagService $transactionTagService,
        IL10N $l
    ) {
        $this->appData = $appData;
        $this->transactionService = $transactionService;
        $this->transactionMapper = $transactionMapper;
        $this->accountMapper = $accountMapper;
        $this->fileValidator = $fileValidator;
        $this->parserFactory = $parserFactory;
        $this->normalizer = $normalizer;
        $this->duplicateDetector = $duplicateDetector;
        $this->ruleApplicator = $ruleApplicator;
        $this->presetRegistry = $presetRegistry;
        $this->categoryService = $categoryService;
        $this->tagSetService = $tagSetService;
        $this->transactionTagService = $transactionTagService;
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
        $fileId = uniqid('import_' . $userId . '_') . '.' . $extension;

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
                $row = str_getcsv($line, $delimiter);
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

    private function previewMultiAccountImport(string $userId, string $content, string $format, array $accountMapping, bool $skipDuplicates): array {
        $parsedData = $this->parserFactory->parseFull($content, $format);
        $transactions = [];
        $duplicates = 0;
        $errors = [];
        $accountSummaries = [];

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
                    $importId = $this->normalizer->generateImportId('preview', $sourceId . '_' . $index, $transaction);
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
        if (!$accountId) {
            throw new \Exception($this->l->t('Account ID is required for single-account imports'));
        }

        // Load preset if specified
        $preset = $presetId ? $this->presetRegistry->get($presetId) : null;
        if ($preset) {
            $mapping = $preset->getMapping();
            $delimiter = $preset->getDelimiter();
            if ($preset->getDateFormatHint()) {
                $this->normalizer->setDateFormatHint($preset->getDateFormatHint());
            }
        }

        $account = $this->accountMapper->find($accountId, $userId);
        $data = $this->parserFactory->parse($content, $format, null, $delimiter);
        $transactions = [];
        $duplicates = 0;
        $errors = [];
        $categoriesToCreate = [];
        $skippedByPreset = 0;

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

                $importId = $this->normalizer->generateImportId('preview', $index, $transaction);
                $isDuplicate = $this->duplicateDetector->isDuplicate($accountId, $transaction, $importId);

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
            } catch (\Exception $e) {
                $errors[] = ['row' => $index, 'error' => $e->getMessage(), 'data' => $row];
            }
        }

        $this->normalizer->resetDateFormat();

        // Build categories preview for preset imports
        $categoriesPreview = [];
        if ($preset && !empty($categoriesToCreate)) {
            foreach ($categoriesToCreate as $catData) {
                $entry = ['name' => $catData['name'], 'tags' => array_keys($catData['tags'])];
                $categoriesPreview[] = $entry;
            }
        }

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
        $accountResults = [];

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
                    $importId = $this->normalizer->generateImportId($fileId, $sourceId . '_' . $index, $transaction);

                    if ($skipDuplicates && $this->duplicateDetector->isDuplicateByImportId((int)$destAccountId, $importId)) {
                        $skipped++;
                        $accountResults[$sourceId]['skipped']++;
                        continue;
                    }

                    if ($applyRules) {
                        $transaction = $this->ruleApplicator->applyRules($userId, $transaction);
                    }

                    $this->transactionService->create(
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
                        $importId
                    );

                    $imported++;
                    $accountResults[$sourceId]['imported']++;
                } catch (\Exception $e) {
                    $errors[] = ['row' => $index + 1, 'sourceAccountId' => $sourceId, 'error' => $e->getMessage()];
                }
            }
        }

        $totalProcessed = array_sum(array_map(fn($a) => count($a['transactions']), $parsedData['accounts']));

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'totalProcessed' => $totalProcessed,
            'accountResults' => array_values($accountResults),
        ];
    }

    private function executeSingleAccountImport(string $userId, string $fileId, string $content, string $format, array $mapping, ?int $accountId, bool $skipDuplicates, bool $applyRules, string $delimiter = ',', ?string $presetId = null): array {
        if (!$accountId) {
            throw new \Exception($this->l->t('Account ID is required for single-account imports'));
        }

        // Load preset if specified
        $preset = $presetId ? $this->presetRegistry->get($presetId) : null;
        if ($preset) {
            $mapping = $preset->getMapping();
            $delimiter = $preset->getDelimiter();
            if ($preset->getDateFormatHint()) {
                $this->normalizer->setDateFormatHint($preset->getDateFormatHint());
            }
        }

        $account = $this->accountMapper->find($accountId, $userId);
        $data = $this->parserFactory->parse($content, $format, null, $delimiter);
        $imported = 0;
        $skipped = 0;
        $errors = [];
        $categoriesCreated = 0;

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

                $importId = $this->normalizer->generateImportId($fileId, $index, $transaction);

                if ($skipDuplicates && $this->duplicateDetector->isDuplicateByImportId($accountId, $importId)) {
                    $skipped++;
                    continue;
                }

                if ($applyRules) {
                    $transaction = $this->ruleApplicator->applyRules($userId, $transaction);
                }

                // Resolve category from preset metadata if no category already assigned
                if ($preset && empty($transaction['categoryId']) && !empty($transaction['_categoryName'])) {
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
                    $accountId,
                    $transaction['date'],
                    $transaction['description'],
                    $transaction['amount'],
                    $transaction['type'],
                    $transaction['categoryId'] ?? null,
                    $transaction['vendor'] ?? null,
                    $transaction['reference'] ?? null,
                    $transaction['notes'] ?? null,
                    $importId
                );

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

                $imported++;
            } catch (\Exception $e) {
                $errors[] = ['row' => $index + 1, 'error' => $e->getMessage()];
            }
        }

        $this->normalizer->resetDateFormat();

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

        if ($categoriesCreated > 0) {
            $result['categoriesCreated'] = $categoriesCreated;
        }
        if ($tagsCreated > 0) {
            $result['tagsCreated'] = $tagsCreated;
        }

        return $result;
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

        $category = $this->categoryService->findOrCreate($userId, $categoryName, $type);
        $categoryCache[$cacheKey] = $category->getId();
        $categoriesCreated++;

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
        }
        $tagSetId = $tagCache[$tagSetCacheKey];

        $tagIds = [];
        foreach ($tagNames as $tagName) {
            $tagCacheKey = $tagSetId . '::' . $tagName;
            if (isset($tagCache[$tagCacheKey])) {
                $tagIds[] = $tagCache[$tagCacheKey];
                continue;
            }

            // Check if tag exists in this tag set
            $existingTags = $this->tagSetService->getTagSetWithTags($tagSetId, $userId)->getTags();
            $found = null;
            foreach ($existingTags as $existingTag) {
                if ($existingTag->getName() === $tagName) {
                    $found = $existingTag;
                    break;
                }
            }

            if ($found === null) {
                $found = $this->tagSetService->createTag($tagSetId, $userId, $tagName);
                $tagsCreated++;
            }

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
