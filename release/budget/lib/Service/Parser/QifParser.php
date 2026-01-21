<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Parser;

/**
 * Parser for QIF (Quicken Interchange Format) files.
 * Supports bank, cash, credit card, and investment account types.
 */
class QifParser {

    /** Account type constants */
    private const TYPE_BANK = 'bank';
    private const TYPE_CASH = 'cash';
    private const TYPE_CREDIT_CARD = 'credit_card';
    private const TYPE_INVESTMENT = 'investment';
    private const TYPE_ASSET = 'asset';
    private const TYPE_LIABILITY = 'liability';

    /**
     * Parse QIF content and return structured data with accounts and transactions.
     *
     * @param string $content Raw QIF file content
     * @return array{
     *     accounts: array<array{
     *         name: string|null,
     *         type: string,
     *         description: string|null,
     *         transactions: array
     *     }>
     * }
     */
    public function parse(string $content): array {
        // Normalize line endings
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $lines = explode("\n", $content);

        $accounts = [];
        $currentAccount = null;
        $currentAccountType = self::TYPE_BANK;
        $currentTransaction = [];
        $currentSplit = [];
        $inSplit = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            // Check for header lines
            if ($line[0] === '!') {
                $header = strtolower($line);

                if (str_starts_with($header, '!type:')) {
                    // Save previous account if exists
                    if ($currentAccount !== null) {
                        $accounts[] = $currentAccount;
                    }

                    // Start new account section
                    $currentAccountType = $this->parseAccountType(substr($line, 6));
                    $currentAccount = [
                        'name' => null,
                        'type' => $currentAccountType,
                        'description' => null,
                        'transactions' => [],
                    ];
                    $currentTransaction = [];
                } elseif ($header === '!account') {
                    // Account definition follows - will be parsed in subsequent lines
                    continue;
                } elseif ($header === '!option:autoswitch') {
                    // AutoSwitch option - accounts will follow
                    continue;
                } elseif ($header === '!clear:autoswitch') {
                    // End of AutoSwitch
                    continue;
                }
                continue;
            }

            // If no account context yet, create default bank account
            if ($currentAccount === null) {
                $currentAccount = [
                    'name' => null,
                    'type' => self::TYPE_BANK,
                    'description' => null,
                    'transactions' => [],
                ];
            }

            $code = $line[0];
            $value = strlen($line) > 1 ? substr($line, 1) : '';

            // Handle end of transaction
            if ($code === '^') {
                if (!empty($currentTransaction)) {
                    // Finalize splits if any
                    if (!empty($currentSplit)) {
                        $currentTransaction['splits'][] = $currentSplit;
                        $currentSplit = [];
                    }

                    // Process and add transaction
                    $processed = $this->processTransaction($currentTransaction, $currentAccountType);
                    if ($processed !== null) {
                        $currentAccount['transactions'][] = $processed;
                    }
                }
                $currentTransaction = [];
                $inSplit = false;
                continue;
            }

            // Parse transaction fields
            switch ($code) {
                // Standard transaction fields
                case 'D': // Date
                    $currentTransaction['date'] = $value;
                    break;
                case 'T': // Total amount
                    $currentTransaction['amount'] = $value;
                    break;
                case 'U': // Total amount (duplicate, used by some versions)
                    if (!isset($currentTransaction['amount'])) {
                        $currentTransaction['amount'] = $value;
                    }
                    break;
                case 'C': // Cleared status
                    $currentTransaction['cleared'] = $this->parseClearedStatus($value);
                    break;
                case 'N': // Check number or reference
                    $currentTransaction['reference'] = $value;
                    break;
                case 'P': // Payee
                    $currentTransaction['payee'] = $value;
                    break;
                case 'M': // Memo
                    $currentTransaction['memo'] = $value;
                    break;
                case 'A': // Address line (can repeat up to 5 times)
                    $currentTransaction['address'][] = $value;
                    break;
                case 'L': // Category or transfer account
                    $currentTransaction['category'] = $this->parseCategory($value);
                    break;

                // Split transaction fields
                case 'S': // Split category
                    // Start new split if previous one has data
                    if (!empty($currentSplit)) {
                        $currentTransaction['splits'][] = $currentSplit;
                    }
                    $currentSplit = ['category' => $this->parseCategory($value)];
                    $inSplit = true;
                    break;
                case 'E': // Split memo
                    if ($inSplit) {
                        $currentSplit['memo'] = $value;
                    }
                    break;
                case '$': // Split amount
                    if ($inSplit) {
                        $currentSplit['amount'] = $this->parseAmount($value);
                    }
                    break;
                case '%': // Split percentage
                    if ($inSplit) {
                        $currentSplit['percentage'] = (float) $value;
                    }
                    break;

                // Investment transaction fields
                case 'Y': // Security name
                    $currentTransaction['security'] = $value;
                    break;
                case 'I': // Price
                    $currentTransaction['price'] = $this->parseAmount($value);
                    break;
                case 'Q': // Quantity
                    $currentTransaction['quantity'] = $this->parseAmount($value);
                    break;
                case 'O': // Commission
                    $currentTransaction['commission'] = $this->parseAmount($value);
                    break;

                // Account definition fields (after !Account header)
                case 'N': // Account name (in account context)
                    if (empty($currentAccount['transactions'])) {
                        $currentAccount['name'] = $value;
                    } else {
                        $currentTransaction['reference'] = $value;
                    }
                    break;
            }
        }

        // Don't forget the last account
        if ($currentAccount !== null) {
            // Handle any pending transaction
            if (!empty($currentTransaction)) {
                if (!empty($currentSplit)) {
                    $currentTransaction['splits'][] = $currentSplit;
                }
                $processed = $this->processTransaction($currentTransaction, $currentAccountType);
                if ($processed !== null) {
                    $currentAccount['transactions'][] = $processed;
                }
            }
            $accounts[] = $currentAccount;
        }

        return ['accounts' => $accounts];
    }

    /**
     * Flatten parsed QIF data into a simple transaction list.
     * This is the format expected by ImportService.
     *
     * @param string $content Raw QIF content
     * @param int|null $limit Maximum transactions to return
     * @return array List of transactions with account metadata
     */
    public function parseToTransactionList(string $content, ?int $limit = null): array {
        $parsed = $this->parse($content);
        $transactions = [];

        foreach ($parsed['accounts'] as $account) {
            foreach ($account['transactions'] as $transaction) {
                $transactions[] = array_merge($transaction, [
                    '_account' => [
                        'name' => $account['name'],
                        'type' => $account['type'],
                    ]
                ]);

                if ($limit !== null && count($transactions) >= $limit) {
                    return $transactions;
                }
            }
        }

        return $transactions;
    }

    /**
     * Parse account type from QIF header.
     */
    private function parseAccountType(string $type): string {
        $type = strtolower(trim($type));

        return match ($type) {
            'bank' => self::TYPE_BANK,
            'cash' => self::TYPE_CASH,
            'ccard' => self::TYPE_CREDIT_CARD,
            'invst' => self::TYPE_INVESTMENT,
            'oth a' => self::TYPE_ASSET,
            'oth l' => self::TYPE_LIABILITY,
            default => self::TYPE_BANK,
        };
    }

    /**
     * Parse cleared status code.
     */
    private function parseClearedStatus(string $status): string {
        $status = strtolower(trim($status));

        return match ($status) {
            'x', 'c' => 'cleared',
            '*', 'r' => 'reconciled',
            default => 'uncleared',
        };
    }

    /**
     * Parse category string, handling transfers and subcategories.
     * Format: Category:Subcategory or [Transfer Account] or Category/Class
     */
    private function parseCategory(string $category): array {
        $result = [
            'name' => null,
            'subcategory' => null,
            'class' => null,
            'isTransfer' => false,
            'transferAccount' => null,
        ];

        $category = trim($category);

        if (empty($category)) {
            return $result;
        }

        // Check for transfer (enclosed in brackets)
        if (preg_match('/^\[(.*)\]$/', $category, $matches)) {
            $result['isTransfer'] = true;
            $result['transferAccount'] = $matches[1];
            return $result;
        }

        // Check for class (after /)
        if (str_contains($category, '/')) {
            [$category, $result['class']] = explode('/', $category, 2);
        }

        // Check for subcategory (after :)
        if (str_contains($category, ':')) {
            [$result['name'], $result['subcategory']] = explode(':', $category, 2);
        } else {
            $result['name'] = $category;
        }

        return $result;
    }

    /**
     * Parse amount string, handling various formats.
     */
    private function parseAmount(string $amount): float {
        // Remove currency symbols, spaces, and thousand separators
        $amount = preg_replace('/[^0-9.\-,]/', '', $amount);

        // Handle European format (1.234,56 -> 1234.56)
        if (preg_match('/^\-?\d{1,3}(\.\d{3})*(,\d{2})?$/', $amount)) {
            $amount = str_replace('.', '', $amount);
            $amount = str_replace(',', '.', $amount);
        } else {
            // Standard format - just remove commas
            $amount = str_replace(',', '', $amount);
        }

        return (float) $amount;
    }

    /**
     * Parse QIF date format.
     * Common formats: M/D/YY, M/D'YY, M/D/YYYY, D/M/YYYY, MM-DD-YYYY
     */
    private function parseQifDate(string $date): string {
        $date = trim($date);

        // Handle M/D'YY format (apostrophe separator for year)
        $date = str_replace("'", "/", $date);

        // Extract numeric parts to help with disambiguation
        if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})$/', $date, $parts)) {
            $first = (int) $parts[1];
            $second = (int) $parts[2];
            $year = (int) $parts[3];

            // Handle 2-digit year
            if ($year < 100) {
                $year = $year < 70 ? 2000 + $year : 1900 + $year;
            }

            // Disambiguate based on which values are valid for month (1-12)
            if ($first > 12 && $second <= 12) {
                // First number > 12, must be D/M/Y format
                return sprintf('%04d-%02d-%02d', $year, $second, $first);
            } elseif ($second > 12 && $first <= 12) {
                // Second number > 12, must be M/D/Y format
                return sprintf('%04d-%02d-%02d', $year, $first, $second);
            } else {
                // Both could be month - default to M/D/Y (US format, Quicken's native format)
                return sprintf('%04d-%02d-%02d', $year, $first, $second);
            }
        }

        // Try various date formats for other patterns
        $formats = [
            'Y-m-d',      // 2025-12-31 (ISO format)
            'm-d-Y',      // 12-31-2025
            'd-m-Y',      // 31-12-2025
        ];

        foreach ($formats as $format) {
            $parsed = \DateTime::createFromFormat($format, $date);
            if ($parsed !== false) {
                // Verify the parsed date matches input (catches invalid dates)
                $errors = \DateTime::getLastErrors();
                if ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) {
                    return $parsed->format('Y-m-d');
                }
            }
        }

        // Fallback - try strtotime
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        // Return as-is if we can't parse
        return $date;
    }

    /**
     * Process raw transaction data into standardized format.
     */
    private function processTransaction(array $raw, string $accountType): ?array {
        // Skip if missing required fields
        if (empty($raw['date']) || !isset($raw['amount'])) {
            return null;
        }

        $amount = $this->parseAmount($raw['amount']);

        $transaction = [
            'date' => $this->parseQifDate($raw['date']),
            'amount' => abs($amount),
            'rawAmount' => $amount,
            'type' => $amount >= 0 ? 'credit' : 'debit',
            'description' => $raw['payee'] ?? '',
            'memo' => $raw['memo'] ?? null,
            'reference' => $raw['reference'] ?? null,
            'cleared' => $raw['cleared'] ?? 'uncleared',
            'category' => $raw['category'] ?? null,
        ];

        // Add address if present
        if (!empty($raw['address'])) {
            $transaction['address'] = implode("\n", $raw['address']);
        }

        // Add splits if present
        if (!empty($raw['splits'])) {
            $transaction['splits'] = $raw['splits'];
        }

        // Add investment fields if present
        if ($accountType === self::TYPE_INVESTMENT) {
            if (isset($raw['security'])) {
                $transaction['security'] = $raw['security'];
            }
            if (isset($raw['price'])) {
                $transaction['price'] = $raw['price'];
            }
            if (isset($raw['quantity'])) {
                $transaction['quantity'] = $raw['quantity'];
            }
            if (isset($raw['commission'])) {
                $transaction['commission'] = $raw['commission'];
            }
        }

        // Generate a unique ID based on transaction data
        $transaction['id'] = $this->generateTransactionId($transaction);

        return $transaction;
    }

    /**
     * Generate a unique transaction ID for duplicate detection.
     */
    private function generateTransactionId(array $transaction): string {
        return 'qif_' . md5(
            $transaction['date'] .
            $transaction['rawAmount'] .
            $transaction['description'] .
            ($transaction['reference'] ?? '') .
            ($transaction['memo'] ?? '')
        );
    }
}
