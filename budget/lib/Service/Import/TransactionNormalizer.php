<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

/**
 * Normalizes transaction data from various import formats.
 */
class TransactionNormalizer {
    /**
     * Date formats to try when parsing dates.
     */
    private const DATE_FORMATS = [
        'Y-m-d',
        'm/d/Y',
        'd/m/Y',
        'm-d-Y',
        'd-m-Y',
        'Y/m/d',
        'd.m.Y',
        'm.d.Y',
    ];

    /**
     * Map a CSV row to a transaction using the provided column mapping.
     *
     * @param array $row The CSV row data
     * @param array $mapping Field to column mapping
     * @return array Normalized transaction data
     */
    public function mapRowToTransaction(array $row, array $mapping): array {
        $transaction = [];

        foreach ($mapping as $field => $column) {
            // Skip non-column mapping fields (boolean config flags)
            if (is_bool($column) || $column === null || $column === '') {
                continue;
            }

            if (isset($row[$column])) {
                $transaction[$field] = $row[$column];
            }
        }

        // Ensure required fields
        if (empty($transaction['date'])) {
            throw new \Exception('Date is required');
        }

        if (empty($transaction['amount'])) {
            throw new \Exception('Amount is required');
        }

        // Normalize date
        $transaction['date'] = $this->normalizeDate($transaction['date']);

        // Format amount and determine type
        $amount = $this->parseAmount($transaction['amount']);
        $transaction['amount'] = abs($amount);
        $transaction['type'] = $amount >= 0 ? 'credit' : 'debit';

        // Clean description
        $transaction['description'] = trim($transaction['description'] ?? '');

        return $transaction;
    }

    /**
     * Map an OFX transaction to standard format.
     *
     * @param array $txn OFX transaction data
     * @return array Normalized transaction data
     */
    public function mapOfxTransaction(array $txn): array {
        $amount = (float) ($txn['rawAmount'] ?? $txn['amount'] ?? 0);

        return [
            'date' => $txn['date'] ?? '',
            'amount' => abs($amount),
            'type' => $amount >= 0 ? 'credit' : 'debit',
            'description' => $txn['description'] ?? $txn['name'] ?? '',
            'memo' => $txn['memo'] ?? null,
            'reference' => $txn['reference'] ?? $txn['id'] ?? null,
            'vendor' => $txn['description'] ?? $txn['name'] ?? '',
            'id' => $txn['id'] ?? null, // Preserve FITID for duplicate detection
        ];
    }

    /**
     * Map a QIF transaction to standard format.
     *
     * @param array $txn QIF transaction data
     * @return array Normalized transaction data
     */
    public function mapQifTransaction(array $txn): array {
        $amount = (float) ($txn['amount'] ?? 0);

        return [
            'date' => $this->normalizeDate($txn['date'] ?? ''),
            'amount' => abs($amount),
            'type' => $amount >= 0 ? 'credit' : 'debit',
            'description' => $txn['payee'] ?? $txn['memo'] ?? '',
            'memo' => $txn['memo'] ?? null,
            'reference' => $txn['number'] ?? $txn['reference'] ?? null,
            'vendor' => $txn['payee'] ?? '',
            'category' => $txn['category'] ?? null,
        ];
    }

    /**
     * Normalize a date string to Y-m-d format.
     *
     * @param string $date The date string to normalize
     * @return string Normalized date in Y-m-d format
     * @throws \Exception If date cannot be parsed
     */
    public function normalizeDate(string $date): string {
        $date = trim($date);

        // Already normalized (Y-m-d format)
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // OFX date format: YYYYMMDD or YYYYMMDDHHMMSS
        if (preg_match('/^(\d{4})(\d{2})(\d{2})/', $date, $matches)) {
            return "{$matches[1]}-{$matches[2]}-{$matches[3]}";
        }

        // Try various date formats
        foreach (self::DATE_FORMATS as $format) {
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

    /**
     * Generate a unique import ID for a transaction.
     *
     * @param string $fileId The import file ID (unused, kept for compatibility)
     * @param int|string $index Row index or identifier
     * @param array $transaction Transaction data
     * @return string Unique import ID
     */
    public function generateImportId(string $fileId, int|string $index, array $transaction): string {
        // Use FITID from OFX if available (bank's unique transaction ID)
        if (!empty($transaction['id'])) {
            // FITID is globally unique per bank, so we can use it directly
            return 'ofx_fitid_' . $transaction['id'];
        }

        // Content-based hash for CSV/QIF imports (no fileId to ensure same transaction = same hash)
        // This allows duplicate detection across multiple imports of the same statement
        return 'hash_' . md5(
            ($transaction['date'] ?? '') .
            ($transaction['amount'] ?? '') .
            ($transaction['description'] ?? '') .
            ($transaction['reference'] ?? '')
        );
    }

    /**
     * Clean and normalize a vendor/payee name.
     */
    public function normalizeVendor(?string $vendor): ?string {
        if ($vendor === null || $vendor === '') {
            return null;
        }

        // Trim whitespace
        $vendor = trim($vendor);

        // Remove multiple spaces
        $vendor = preg_replace('/\s+/', ' ', $vendor);

        return $vendor;
    }

    /**
     * Clean and normalize a description.
     */
    public function normalizeDescription(?string $description): string {
        if ($description === null) {
            return '';
        }

        // Trim whitespace
        $description = trim($description);

        // Remove multiple spaces
        $description = preg_replace('/\s+/', ' ', $description);

        return $description;
    }

    /**
     * Parse amount string handling both comma and period as decimal separators.
     *
     * Handles formats like:
     * - 1234.56 (US/UK format)
     * - 1,234.56 (US/UK format with thousands separator)
     * - 1234,56 (European format)
     * - 1.234,56 (European format with thousands separator)
     *
     * @param string|float $amount The amount to parse
     * @return float Parsed amount
     */
    private function parseAmount(string|float $amount): float {
        // Already a float
        if (is_float($amount)) {
            return $amount;
        }

        // Convert to string and trim
        $amount = trim((string) $amount);

        // Remove currency symbols and whitespace
        $amount = preg_replace('/[^\d,.\-+]/', '', $amount);

        // If empty after cleanup, return 0
        if ($amount === '' || $amount === '-' || $amount === '+') {
            return 0.0;
        }

        // Count periods and commas to determine format
        $periodCount = substr_count($amount, '.');
        $commaCount = substr_count($amount, ',');

        // Find last occurrence of period and comma
        $lastPeriod = strrpos($amount, '.');
        $lastComma = strrpos($amount, ',');

        // Determine decimal separator based on position and count
        if ($periodCount === 0 && $commaCount === 0) {
            // No separators - just an integer
            return (float) $amount;
        } elseif ($periodCount > 0 && $commaCount === 0) {
            // Only periods - could be thousands or decimal
            if ($periodCount === 1 && $lastPeriod > strlen($amount) - 4) {
                // Single period in last 3 positions = decimal separator
                return (float) $amount;
            } else {
                // Multiple periods or not in decimal position = thousands separator
                return (float) str_replace('.', '', $amount);
            }
        } elseif ($commaCount > 0 && $periodCount === 0) {
            // Only commas - could be thousands or decimal
            if ($commaCount === 1 && $lastComma > strlen($amount) - 4) {
                // Single comma in last 3 positions = decimal separator (European)
                return (float) str_replace(',', '.', $amount);
            } else {
                // Multiple commas or not in decimal position = thousands separator
                return (float) str_replace(',', '', $amount);
            }
        } else {
            // Both periods and commas present
            if ($lastPeriod > $lastComma) {
                // Period comes after comma: 1,234.56 (US format)
                // Remove commas (thousands), keep period (decimal)
                return (float) str_replace(',', '', $amount);
            } else {
                // Comma comes after period: 1.234,56 (European format)
                // Remove periods (thousands), replace comma with period (decimal)
                $amount = str_replace('.', '', $amount);
                $amount = str_replace(',', '.', $amount);
                return (float) $amount;
            }
        }
    }
}
