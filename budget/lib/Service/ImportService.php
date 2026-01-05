<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\Parser\OfxParser;
use OCA\Budget\Service\Parser\QifParser;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;

class ImportService {
    private OfxParser $ofxParser;
    private QifParser $qifParser;
    private IAppData $appData;
    private TransactionService $transactionService;
    private ImportRuleMapper $importRuleMapper;
    private AccountMapper $accountMapper;

    public function __construct(
        IAppData $appData,
        TransactionService $transactionService,
        ImportRuleMapper $importRuleMapper,
        AccountMapper $accountMapper
    ) {
        $this->appData = $appData;
        $this->transactionService = $transactionService;
        $this->importRuleMapper = $importRuleMapper;
        $this->accountMapper = $accountMapper;
        $this->ofxParser = new OfxParser();
        $this->qifParser = new QifParser();
    }

    public function processUpload(string $userId, array $uploadedFile): array {
        $fileName = $uploadedFile['name'];
        $tmpPath = $uploadedFile['tmp_name'];
        $fileSize = $uploadedFile['size'];

        // Validate file (extension, size, MIME type, and content)
        $this->validateUploadedFile($fileName, $fileSize, $tmpPath);

        // Parse file to detect format and columns
        $format = $this->detectFileFormat($fileName);

        // Generate unique file ID - include extension to preserve format
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) ?: 'dat';
        $fileId = uniqid('import_' . $userId . '_') . '.' . $extension;

        try {
            // Get or create imports folder
            try {
                $importsFolder = $this->appData->getFolder('imports');
            } catch (NotFoundException $e) {
                $importsFolder = $this->appData->newFolder('imports');
            }

            // Store file with original extension
            $file = $importsFolder->newFile($fileId);
            $file->putContent(file_get_contents($tmpPath));
            $content = file_get_contents($tmpPath);
            $preview = $this->parseFile($content, $format, 5); // Preview first 5 rows

            // For CSV, return columns as array for mapping UI
            // For OFX/QIF, data is pre-parsed with standard fields
            $columns = [];
            $rawPreview = [];
            $sourceAccounts = [];

            if ($format === 'csv') {
                // Get raw CSV headers and data for mapping
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
                        if (count($rawPreview) > 6) break; // Header + 5 data rows
                    }
                }
            } elseif ($format === 'ofx') {
                // OFX - extract source accounts for mapping
                $parsedOfx = $this->ofxParser->parse($content);
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

                // Use standard OFX field names for column mapping
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
                // QIF - extract source accounts for mapping
                $parsedQif = $this->qifParser->parse($content);
                foreach ($parsedQif['accounts'] as $account) {
                    $sourceAccounts[] = [
                        'accountId' => $account['name'] ?? $account['accountId'] ?? 'Unknown',
                        'type' => $account['type'] ?? 'unknown',
                        'transactionCount' => count($account['transactions'] ?? []),
                    ];
                }

                // Use standard QIF field names
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
                // Generic fallback
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
                'recordCount' => $this->countRows($content, $format),
                'size' => $fileSize
            ];
        } catch (\Exception $e) {
            throw new \Exception('Failed to process upload: ' . $e->getMessage());
        }
    }

    /**
     * Preview import with support for multi-account mapping.
     *
     * @param string $userId
     * @param string $fileId
     * @param array $mapping Column mapping (field => column)
     * @param int|null $accountId Single destination account (for CSV imports)
     * @param array|null $accountMapping Multi-account mapping (sourceAccountId => destinationAccountId)
     * @param bool $skipDuplicates
     * @return array
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
        $format = $this->detectFileFormat($fileId);
        $content = $file->getContent();

        $transactions = [];
        $duplicates = 0;
        $errors = [];
        $accountSummaries = [];

        // For OFX/QIF with multi-account support
        if (($format === 'ofx' || $format === 'qif') && !empty($accountMapping)) {
            $parsedData = $format === 'ofx'
                ? $this->ofxParser->parse($content)
                : $this->qifParser->parse($content);

            foreach ($parsedData['accounts'] as $sourceAccount) {
                $sourceId = $sourceAccount['accountId'];
                $destAccountId = $accountMapping[$sourceId] ?? null;

                if (!$destAccountId) {
                    // Skip accounts not mapped
                    continue;
                }

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
                        $transaction = $this->mapOfxTransaction($txn);

                        if ($skipDuplicates && $this->isDuplicate((int)$destAccountId, $transaction)) {
                            $duplicates++;
                            $accountSummaries[$sourceId]['duplicates']++;
                            continue;
                        }

                        $rule = $this->importRuleMapper->findMatchingRule($userId, $transaction);
                        if ($rule) {
                            $transaction['categoryId'] = $rule->getCategoryId();
                            $transaction['vendor'] = $rule->getVendorName() ?: $transaction['vendor'];
                        }

                        $transactions[] = array_merge($transaction, [
                            'rowIndex' => $index,
                            'sourceAccountId' => $sourceId,
                            'destinationAccountId' => $destAccountId,
                            'ruleName' => $rule ? $rule->getName() : null
                        ]);
                        $accountSummaries[$sourceId]['transactionCount']++;
                    } catch (\Exception $e) {
                        $errors[] = [
                            'row' => $index,
                            'sourceAccountId' => $sourceId,
                            'error' => $e->getMessage(),
                        ];
                    }
                }
            }

            $totalRows = array_sum(array_map(fn($a) => count($a['transactions']), $parsedData['accounts']));
        } else {
            // Single account import (CSV or legacy)
            if (!$accountId) {
                throw new \Exception('Account ID is required for single-account imports');
            }

            $account = $this->accountMapper->find($accountId, $userId);
            $data = $this->parseFile($content, $format);

            foreach ($data as $index => $row) {
                try {
                    $transaction = $this->mapRowToTransaction($row, $mapping);

                    if ($skipDuplicates && $this->isDuplicate($accountId, $transaction)) {
                        $duplicates++;
                        continue;
                    }

                    $rule = $this->importRuleMapper->findMatchingRule($userId, $transaction);
                    if ($rule) {
                        $transaction['categoryId'] = $rule->getCategoryId();
                        $transaction['vendor'] = $rule->getVendorName() ?: $transaction['vendor'];
                    }

                    $transactions[] = array_merge($transaction, [
                        'rowIndex' => $index,
                        'ruleName' => $rule ? $rule->getName() : null
                    ]);
                } catch (\Exception $e) {
                    $errors[] = [
                        'row' => $index,
                        'error' => $e->getMessage(),
                        'data' => $row
                    ];
                }
            }

            $totalRows = count($data);
            $accountSummaries['default'] = [
                'destinationAccountId' => $accountId,
                'destinationAccountName' => $account->getName(),
                'transactionCount' => count($transactions),
                'duplicates' => $duplicates,
            ];
        }

        return [
            'transactions' => array_slice($transactions, 0, 50), // Preview first 50
            'totalRows' => $totalRows,
            'validTransactions' => count($transactions),
            'duplicates' => $duplicates,
            'errors' => $errors,
            'accountSummaries' => array_values($accountSummaries),
        ];
    }

    /**
     * Map OFX transaction to standard format.
     */
    private function mapOfxTransaction(array $txn): array {
        $amount = (float) ($txn['rawAmount'] ?? $txn['amount'] ?? 0);

        return [
            'date' => $txn['date'] ?? '',
            'amount' => abs($amount),
            'type' => $amount >= 0 ? 'credit' : 'debit',
            'description' => $txn['description'] ?? $txn['name'] ?? '',
            'memo' => $txn['memo'] ?? null,
            'reference' => $txn['reference'] ?? $txn['id'] ?? null,
            'vendor' => $txn['description'] ?? $txn['name'] ?? '',
        ];
    }

    /**
     * Process import with support for multi-account mapping.
     *
     * @param string $userId
     * @param string $fileId
     * @param array $mapping Column mapping (field => column)
     * @param int|null $accountId Single destination account (for CSV imports)
     * @param array|null $accountMapping Multi-account mapping (sourceAccountId => destinationAccountId)
     * @param bool $skipDuplicates
     * @param bool $applyRules
     * @return array
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
        $format = $this->detectFileFormat($fileId);
        $content = $file->getContent();

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $accountResults = [];

        // For OFX/QIF with multi-account support
        if (($format === 'ofx' || $format === 'qif') && !empty($accountMapping)) {
            $parsedData = $format === 'ofx'
                ? $this->ofxParser->parse($content)
                : $this->qifParser->parse($content);

            foreach ($parsedData['accounts'] as $sourceAccount) {
                $sourceId = $sourceAccount['accountId'];
                $destAccountId = $accountMapping[$sourceId] ?? null;

                if (!$destAccountId) {
                    continue;
                }

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
                        $transaction = $this->mapOfxTransaction($txn);
                        $importId = $this->generateImportId($fileId, $sourceId . '_' . $index, $transaction);

                        if ($skipDuplicates && $this->transactionService->existsByImportId((int)$destAccountId, $importId)) {
                            $skipped++;
                            $accountResults[$sourceId]['skipped']++;
                            continue;
                        }

                        if ($applyRules) {
                            $rule = $this->importRuleMapper->findMatchingRule($userId, $transaction);
                            if ($rule) {
                                $transaction['categoryId'] = $rule->getCategoryId();
                                $transaction['vendor'] = $rule->getVendorName() ?: $transaction['vendor'];
                            }
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
                        $errors[] = [
                            'row' => $index + 1,
                            'sourceAccountId' => $sourceId,
                            'error' => $e->getMessage()
                        ];
                    }
                }
            }

            $totalProcessed = array_sum(array_map(fn($a) => count($a['transactions']), $parsedData['accounts']));
        } else {
            // Single account import (CSV or legacy)
            if (!$accountId) {
                throw new \Exception('Account ID is required for single-account imports');
            }

            $account = $this->accountMapper->find($accountId, $userId);
            $data = $this->parseFile($content, $format);

            foreach ($data as $index => $row) {
                try {
                    $transaction = $this->mapRowToTransaction($row, $mapping);
                    $importId = $this->generateImportId($fileId, $index, $transaction);

                    if ($skipDuplicates && $this->transactionService->existsByImportId($accountId, $importId)) {
                        $skipped++;
                        continue;
                    }

                    if ($applyRules) {
                        $rule = $this->importRuleMapper->findMatchingRule($userId, $transaction);
                        if ($rule) {
                            $transaction['categoryId'] = $rule->getCategoryId();
                            $transaction['vendor'] = $rule->getVendorName() ?: $transaction['vendor'];
                        }
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
                    $errors[] = [
                        'row' => $index + 1,
                        'error' => $e->getMessage()
                    ];
                }
            }

            $totalProcessed = count($data);
            $accountResults['default'] = [
                'destinationAccountId' => $accountId,
                'destinationAccountName' => $account->getName(),
                'imported' => $imported,
                'skipped' => $skipped,
            ];
        }

        // Clean up import file
        try {
            $file->delete();
        } catch (\Exception $e) {
            // Log but don't fail on cleanup error
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'totalProcessed' => $totalProcessed,
            'accountResults' => array_values($accountResults),
        ];
    }

    private function validateUploadedFile(string $fileName, int $fileSize, ?string $tmpPath = null): void {
        $maxSize = 10 * 1024 * 1024; // 10MB
        if ($fileSize > $maxSize) {
            throw new \Exception('File too large. Maximum size is 10MB.');
        }

        $allowedExtensions = ['csv', 'ofx', 'qif', 'txt'];
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($extension, $allowedExtensions)) {
            throw new \Exception('Unsupported file format. Supported formats: ' . implode(', ', $allowedExtensions));
        }

        // Validate MIME type and content if file path is provided
        if ($tmpPath !== null && file_exists($tmpPath)) {
            $this->validateMimeType($tmpPath, $extension);
            $this->validateFileContent($tmpPath, $extension);
        }
    }

    /**
     * Validate MIME type matches expected type for extension.
     */
    private function validateMimeType(string $filePath, string $extension): void {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($filePath);

        $allowedMimes = [
            'csv' => ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'],
            'txt' => ['text/plain'],
            'ofx' => ['text/plain', 'application/x-ofx', 'application/xml', 'text/xml', 'application/sgml'],
            'qif' => ['text/plain', 'application/qif', 'application/x-qif'],
        ];

        $allowed = $allowedMimes[$extension] ?? ['text/plain'];

        if (!in_array($mimeType, $allowed)) {
            throw new \Exception(
                "Invalid file type. Expected " . implode(' or ', $allowed) .
                " for .$extension file, got: $mimeType"
            );
        }
    }

    /**
     * Validate file content matches expected format.
     * This prevents disguised malicious files.
     */
    private function validateFileContent(string $filePath, string $extension): void {
        $content = file_get_contents($filePath, false, null, 0, 4096); // Read first 4KB

        if ($content === false || strlen($content) === 0) {
            throw new \Exception('File is empty or unreadable.');
        }

        // Check for binary content (non-text files)
        if ($this->containsBinaryData($content)) {
            throw new \Exception('File appears to be binary. Only text-based financial files are supported.');
        }

        switch ($extension) {
            case 'csv':
            case 'txt':
                $this->validateCsvContent($content);
                break;
            case 'ofx':
                $this->validateOfxContent($content);
                break;
            case 'qif':
                $this->validateQifContent($content);
                break;
        }
    }

    /**
     * Check if content contains binary (non-printable) data.
     */
    private function containsBinaryData(string $content): bool {
        // Reject null bytes (common in binary files)
        if (strpos($content, "\x00") !== false) {
            return true;
        }

        // Check for high ratio of non-printable characters
        $nonPrintable = preg_match_all('/[^\x20-\x7E\x09\x0A\x0D\xC0-\xFF]/', $content);
        $ratio = $nonPrintable / max(1, strlen($content));

        return $ratio > 0.1; // More than 10% non-printable = likely binary
    }

    /**
     * Validate CSV content structure.
     */
    private function validateCsvContent(string $content): void {
        $lines = explode("\n", $content);
        $nonEmptyLines = array_filter($lines, fn($line) => trim($line) !== '');

        if (count($nonEmptyLines) < 2) {
            throw new \Exception('CSV file must contain at least a header row and one data row.');
        }

        // Check that it looks like CSV (has delimiters)
        $firstLine = array_values($nonEmptyLines)[0] ?? '';
        $hasComma = strpos($firstLine, ',') !== false;
        $hasSemicolon = strpos($firstLine, ';') !== false;
        $hasTab = strpos($firstLine, "\t") !== false;

        if (!$hasComma && !$hasSemicolon && !$hasTab) {
            throw new \Exception('CSV file does not appear to have valid delimiters (comma, semicolon, or tab).');
        }
    }

    /**
     * Validate OFX content structure.
     */
    private function validateOfxContent(string $content): void {
        // OFX files can be SGML or XML format
        $hasOfxHeader = stripos($content, 'OFXHEADER:') !== false;
        $hasOfxTag = stripos($content, '<OFX>') !== false || stripos($content, '<ofx>') !== false;
        $hasXmlOfx = stripos($content, '<?OFX') !== false;

        if (!$hasOfxHeader && !$hasOfxTag && !$hasXmlOfx) {
            throw new \Exception('File does not appear to be a valid OFX file. Missing OFX header or tags.');
        }
    }

    /**
     * Validate QIF content structure.
     */
    private function validateQifContent(string $content): void {
        // QIF files should start with !Type: or have transaction markers
        $hasTypeHeader = stripos($content, '!Type:') !== false;
        $hasAccountHeader = stripos($content, '!Account') !== false;
        $hasTransactionMarker = strpos($content, '^') !== false;

        if (!$hasTypeHeader && !$hasAccountHeader) {
            throw new \Exception('File does not appear to be a valid QIF file. Missing !Type: or !Account header.');
        }

        if (!$hasTransactionMarker) {
            throw new \Exception('File does not appear to be a valid QIF file. Missing transaction end markers (^).');
        }
    }

    private function detectFileFormat(string $fileName): string {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        
        switch ($extension) {
            case 'csv':
            case 'txt':
                return 'csv';
            case 'ofx':
                return 'ofx';
            case 'qif':
                return 'qif';
            default:
                return 'csv'; // Default fallback
        }
    }

    private function parseFile(string $content, string $format, int $limit = null): array {
        switch ($format) {
            case 'csv':
                return $this->parseCsv($content, $limit);
            case 'ofx':
                return $this->parseOfx($content, $limit);
            case 'qif':
                return $this->parseQif($content, $limit);
            default:
                throw new \Exception('Unsupported format: ' . $format);
        }
    }

    private function parseCsv(string $content, int $limit = null): array {
        $lines = explode("\n", $content);
        $data = [];
        $headers = null;
        $count = 0;
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            $row = str_getcsv($line);
            
            if ($headers === null) {
                $headers = array_map('trim', $row);
                continue;
            }
            
            if ($limit && $count >= $limit) {
                break;
            }
            
            $data[] = array_combine($headers, array_pad($row, count($headers), ''));
            $count++;
        }
        
        return $data;
    }

    private function parseOfx(string $content, int $limit = null): array {
        return $this->ofxParser->parseToTransactionList($content, $limit);
    }

    /**
     * Parse OFX file and return full structured data with accounts.
     * Useful for preview and account selection UI.
     */
    public function parseOfxFull(string $content): array {
        return $this->ofxParser->parse($content);
    }

    private function parseQif(string $content, int $limit = null): array {
        return $this->qifParser->parseToTransactionList($content, $limit);
    }

    /**
     * Parse QIF file and return full structured data with accounts.
     * Useful for preview and account selection UI.
     */
    public function parseQifFull(string $content): array {
        return $this->qifParser->parse($content);
    }

    private function mapRowToTransaction(array $row, array $mapping): array {
        $transaction = [];
        
        foreach ($mapping as $field => $column) {
            if (isset($row[$column])) {
                $transaction[$field] = $row[$column];
            }
        }
        
        // Ensure required fields and format
        if (empty($transaction['date'])) {
            throw new \Exception('Date is required');
        }
        
        if (empty($transaction['amount'])) {
            throw new \Exception('Amount is required');
        }
        
        // Format date
        $transaction['date'] = $this->normalizeDate($transaction['date']);
        
        // Format amount and determine type
        $amount = (float) str_replace(',', '', $transaction['amount']);
        $transaction['amount'] = abs($amount);
        $transaction['type'] = $amount >= 0 ? 'credit' : 'debit';
        
        // Clean description
        $transaction['description'] = trim($transaction['description'] ?? '');
        
        return $transaction;
    }

    private function normalizeDate(string $date): string {
        // Already normalized (Y-m-d format from OFX parser)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // OFX date format: YYYYMMDD or YYYYMMDDHHMMSS
        if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $date, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        // Try various date formats
        $formats = [
            'Y-m-d', 'm/d/Y', 'd/m/Y', 'm-d-Y', 'd-m-Y',
            'Y/m/d', 'd.m.Y', 'm.d.Y'
        ];

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $date);
            if ($parsed !== false) {
                return $parsed->format('Y-m-d');
            }
        }

        // Try strtotime as fallback
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        throw new \Exception('Invalid date format: ' . $date);
    }

    private function isDuplicate(int $accountId, array $transaction, string $importId = null): bool {
        if ($importId) {
            return $this->transactionService->existsByImportId($accountId, $importId);
        }
        
        // Fallback duplicate detection based on date, amount, and description
        // This is a simplified approach - production apps might use more sophisticated matching
        return false;
    }

    private function generateImportId(string $fileId, int|string $index, array $transaction): string {
        // Use FITID from OFX if available (bank's unique transaction ID)
        if (!empty($transaction['id'])) {
            // Include account context if available for uniqueness across accounts
            $accountContext = '';
            if (!empty($transaction['_account']['accountId'])) {
                $accountContext = $transaction['_account']['accountId'] . '_';
            }
            return 'ofx_' . $accountContext . $transaction['id'];
        }

        // Fallback to hash-based ID for CSV/QIF imports
        return $fileId . '_' . $index . '_' . md5(
            $transaction['date'] .
            $transaction['amount'] .
            $transaction['description']
        );
    }

    private function getImportFile(string $fileId) {
        try {
            $importsFolder = $this->appData->getFolder('imports');
            // fileId now includes the extension (e.g., import_user_abc123.ofx)
            return $importsFolder->getFile($fileId);
        } catch (NotFoundException $e) {
            // Fallback for legacy files with .dat extension
            try {
                return $importsFolder->getFile($fileId . '.dat');
            } catch (NotFoundException $e) {
                throw new \Exception('Import file not found');
            }
        }
    }

    private function countRows(string $content, string $format): int {
        if ($format === 'csv') {
            $lines = explode("\n", $content);
            $count = 0;
            foreach ($lines as $line) {
                if (!empty(trim($line))) {
                    $count++;
                }
            }
            return max(0, $count - 1); // Subtract header row
        }

        // For other formats, use parsed array count
        return count($this->parseFile($content, $format));
    }

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
        // This would typically query a separate import_history table
        // For now, return empty array
        return [];
    }

    public function validateFile(string $userId, string $fileId): array {
        $file = $this->getImportFile($fileId);
        $format = $this->detectFileFormat($fileId);
        
        try {
            $preview = $this->parseFile($file->getContent(), $format, 10);
            
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

    public function executeImport(
        string $userId,
        string $importId,
        int $accountId,
        array $transactionIds
    ): array {
        // Mock implementation - replace with actual execution logic
        return [
            'importId' => $importId,
            'accountId' => $accountId,
            'imported' => count($transactionIds),
            'success' => true,
            'message' => 'Import completed successfully'
        ];
    }

    public function rollbackImport(string $userId, int $importId): array {
        // Mock implementation - replace with actual rollback logic
        return [
            'importId' => $importId,
            'rolledBack' => true,
            'transactionsRemoved' => 25,
            'message' => 'Import rolled back successfully'
        ];
    }
}