<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\Import\DuplicateDetector;
use OCA\Budget\Service\Import\FileValidator;
use OCA\Budget\Service\Import\ImportRuleApplicator;
use OCA\Budget\Service\Import\ParserFactory;
use OCA\Budget\Service\Import\TransactionNormalizer;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;

/**
 * Orchestrates the import process for financial data files.
 */
class ImportService {
    private IAppData $appData;
    private TransactionService $transactionService;
    private AccountMapper $accountMapper;
    private FileValidator $fileValidator;
    private ParserFactory $parserFactory;
    private TransactionNormalizer $normalizer;
    private DuplicateDetector $duplicateDetector;
    private ImportRuleApplicator $ruleApplicator;

    public function __construct(
        IAppData $appData,
        TransactionService $transactionService,
        AccountMapper $accountMapper,
        FileValidator $fileValidator,
        ParserFactory $parserFactory,
        TransactionNormalizer $normalizer,
        DuplicateDetector $duplicateDetector,
        ImportRuleApplicator $ruleApplicator
    ) {
        $this->appData = $appData;
        $this->transactionService = $transactionService;
        $this->accountMapper = $accountMapper;
        $this->fileValidator = $fileValidator;
        $this->parserFactory = $parserFactory;
        $this->normalizer = $normalizer;
        $this->duplicateDetector = $duplicateDetector;
        $this->ruleApplicator = $ruleApplicator;
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
            $file->putContent($content);

            // Parse preview
            $preview = $this->parserFactory->parse($content, $format, 5);

            // Build response based on format
            return $this->buildUploadResponse($userId, $fileId, $fileName, $format, $content, $preview, $fileSize);

        } catch (\Exception $e) {
            throw new \Exception('Failed to process upload: ' . $e->getMessage());
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
        bool $skipDuplicates = true
    ): array {
        $file = $this->getImportFile($fileId);
        $format = $this->parserFactory->detectFormat($fileId);
        $content = $file->getContent();

        if (($format === 'ofx' || $format === 'qif') && !empty($accountMapping)) {
            return $this->previewMultiAccountImport($userId, $content, $format, $accountMapping, $skipDuplicates);
        }

        return $this->previewSingleAccountImport($userId, $content, $format, $mapping, $accountId, $skipDuplicates);
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
        bool $applyRules = true
    ): array {
        $file = $this->getImportFile($fileId);
        $format = $this->parserFactory->detectFormat($fileId);
        $content = $file->getContent();

        if (($format === 'ofx' || $format === 'qif') && !empty($accountMapping)) {
            $result = $this->executeMultiAccountImport($userId, $fileId, $content, $format, $accountMapping, $skipDuplicates, $applyRules);
        } else {
            $result = $this->executeSingleAccountImport($userId, $fileId, $content, $format, $mapping, $accountId, $skipDuplicates, $applyRules);
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
        return [
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
    }

    public function getImportHistory(string $userId, int $limit = 50): array {
        return [];
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
                throw new \Exception('Import file not found');
            }
        }
    }

    private function buildUploadResponse(string $userId, string $fileId, string $fileName, string $format, string $content, array $preview, int $fileSize): array {
        $columns = [];
        $rawPreview = [];
        $sourceAccounts = [];

        if ($format === 'csv') {
            $lines = explode("\n", $content);
            $headers = [];
            foreach ($lines as $line) {
                if (empty(trim($line))) continue;
                $row = str_getcsv($line);
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
            'recordCount' => $this->parserFactory->countRows($content, $format),
            'size' => $fileSize
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

                    if ($skipDuplicates && $this->duplicateDetector->isDuplicate((int)$destAccountId, $transaction)) {
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
                    ]);
                    $accountSummaries[$sourceId]['transactionCount']++;
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

    private function previewSingleAccountImport(string $userId, string $content, string $format, array $mapping, ?int $accountId, bool $skipDuplicates): array {
        if (!$accountId) {
            throw new \Exception('Account ID is required for single-account imports');
        }

        $account = $this->accountMapper->find($accountId, $userId);
        $data = $this->parserFactory->parse($content, $format);
        $transactions = [];
        $duplicates = 0;
        $errors = [];

        foreach ($data as $index => $row) {
            try {
                $transaction = $this->normalizer->mapRowToTransaction($row, $mapping);

                if ($skipDuplicates && $this->duplicateDetector->isDuplicate($accountId, $transaction)) {
                    $duplicates++;
                    continue;
                }

                $transaction = $this->ruleApplicator->applyRules($userId, $transaction);
                $transactions[] = array_merge($transaction, ['rowIndex' => $index]);
            } catch (\Exception $e) {
                $errors[] = ['row' => $index, 'error' => $e->getMessage(), 'data' => $row];
            }
        }

        return [
            'transactions' => array_slice($transactions, 0, 50),
            'totalRows' => count($data),
            'validTransactions' => count($transactions),
            'duplicates' => $duplicates,
            'errors' => $errors,
            'accountSummaries' => [[
                'destinationAccountId' => $accountId,
                'destinationAccountName' => $account->getName(),
                'transactionCount' => count($transactions),
                'duplicates' => $duplicates,
            ]],
        ];
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
                        null,
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

    private function executeSingleAccountImport(string $userId, string $fileId, string $content, string $format, array $mapping, ?int $accountId, bool $skipDuplicates, bool $applyRules): array {
        if (!$accountId) {
            throw new \Exception('Account ID is required for single-account imports');
        }

        $account = $this->accountMapper->find($accountId, $userId);
        $data = $this->parserFactory->parse($content, $format);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($data as $index => $row) {
            try {
                $transaction = $this->normalizer->mapRowToTransaction($row, $mapping);
                $importId = $this->normalizer->generateImportId($fileId, $index, $transaction);

                if ($skipDuplicates && $this->duplicateDetector->isDuplicateByImportId($accountId, $importId)) {
                    $skipped++;
                    continue;
                }

                if ($applyRules) {
                    $transaction = $this->ruleApplicator->applyRules($userId, $transaction);
                }

                $this->transactionService->create(
                    $userId,
                    $accountId,
                    $transaction['date'],
                    $transaction['description'],
                    $transaction['amount'],
                    $transaction['type'],
                    $transaction['categoryId'] ?? null,
                    $transaction['vendor'] ?? null,
                    $transaction['reference'] ?? null,
                    null,
                    $importId
                );

                $imported++;
            } catch (\Exception $e) {
                $errors[] = ['row' => $index + 1, 'error' => $e->getMessage()];
            }
        }

        return [
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
}
