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
        // 2-digit year variants (must come AFTER 4-digit to avoid
        // misinterpreting e.g. "25.03.2026" by consuming only "20")
        'm/d/y',
        'd/m/y',
        'd.m.y',
    ];

    /**
     * Values a mapped "type" column may hold, and the internal type each means.
     * Anything not listed falls back to the amount's sign.
     */
    private const TYPE_WORDS = [
        'credit' => 'credit',
        'cr' => 'credit',
        'c' => 'credit',
        'income' => 'credit',
        'deposit' => 'credit',
        'deposits' => 'credit',
        'refund' => 'credit',
        'in' => 'credit',
        'debit' => 'debit',
        'dr' => 'debit',
        'd' => 'debit',
        'expense' => 'debit',
        'expenses' => 'debit',
        'withdrawal' => 'debit',
        'withdrawals' => 'debit',
        'payment' => 'debit',
        'purchase' => 'debit',
        'out' => 'debit',
    ];

    /**
     * Destination column widths, so an imported value can never be rejected
     * by the insert or by a later edit through the UI/API.
     * notes is TEXT in the DB but ValidationService caps edits at 2000.
     */
    private const MAX_NOTES_LENGTH = 2000;
    private const MAX_VENDOR_LENGTH = 255;
    private const MAX_REFERENCE_LENGTH = 100;

    /** @var string|null Cached date format detected from batch analysis */
    private ?string $detectedDateFormat = null;

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

        // Normalize date
        $transaction['date'] = $this->normalizeDate($transaction['date']);

        // Handle amount: either single column OR dual income/expense columns
        $amount = null;
        $type = null;

        // Check for dual-column approach (income + expense)
        // Parse amount first, then check numeric zero to handle all locale formats (0, 0.00, 0,00, etc.)
        if (!empty($mapping['incomeColumn']) && isset($row[$mapping['incomeColumn']])) {
            $incomeValue = trim($row[$mapping['incomeColumn']]);
            if ($incomeValue !== '') {
                $parsed = $this->parseAmount($incomeValue);
                if ($parsed != 0) {
                    $amount = $parsed;
                    $type = 'credit';
                }
            }
        }

        if (!empty($mapping['expenseColumn']) && isset($row[$mapping['expenseColumn']])) {
            $expenseValue = trim($row[$mapping['expenseColumn']]);
            if ($expenseValue !== '') {
                $parsed = $this->parseAmount($expenseValue);
                if ($parsed != 0) {
                    $amount = $parsed;
                    $type = 'debit';
                }
            }
        }

        // Fall back to single amount column if dual columns weren't used
        if ($amount === null && !empty($transaction['amount'])) {
            $amount = $this->parseAmount($transaction['amount']);
            $type = $amount >= 0 ? 'credit' : 'debit';
            $amount = abs($amount);

            // A mapped type column wins over the amount's sign. Exports that
            // carry an explicit "Expense"/"Income" column usually write every
            // amount unsigned, so going by the sign alone books the whole file
            // as income (#333).
            $mappedType = $this->parseTypeValue($transaction['type'] ?? null);
            if ($mappedType !== null) {
                $type = $mappedType;
            } elseif ($this->mapsColumn($mapping, 'type')) {
                // A type column was mapped but this row's value was blank or
                // unrecognized, so the row falls back to the sign. Mark it so
                // the preview can say how many rows are guessing.
                $transaction['_typeUnresolved'] = true;
            }
        }

        // Ensure we have an amount
        if ($amount === null) {
            throw new \Exception('Amount is required (either single amount column or income/expense columns)');
        }

        $transaction['amount'] = abs($amount);
        $transaction['type'] = $type;

        // Attach category name metadata if mapped
        if (!empty($mapping['category']) && !empty($row[$mapping['category']])) {
            $transaction['_categoryName'] = trim($row[$mapping['category']]);
        }

        // Attach account name metadata if mapped
        if (!empty($mapping['account']) && !empty($row[$mapping['account']])) {
            $transaction['_accountName'] = trim($row[$mapping['account']]);
        }

        // Attach currency metadata if mapped
        if (!empty($mapping['currency']) && !empty($row[$mapping['currency']])) {
            $transaction['_currency'] = strtoupper(trim($row[$mapping['currency']]));
        }

        // Clean description
        $transaction['description'] = trim($transaction['description'] ?? '');

        // Set import source for rule matching
        $transaction['source'] = 'CSV Import';

        return $transaction;
    }

    /**
     * Map an OFX transaction to standard format.
     *
     * QIF rows are mapped through here too (ImportService routes both formats
     * to this method), so every source key is resolved defensively.
     *
     * Date, amount and type are structural in these formats and are not
     * remappable; only the text fields below follow the user's mapping.
     *
     * @param array $txn OFX transaction data
     * @param array $mapping Field to source-column mapping (empty = defaults)
     * @return array Normalized transaction data
     */
    public function mapOfxTransaction(array $txn, array $mapping = []): array {
        $amount = (float) ($txn['rawAmount'] ?? $txn['amount'] ?? 0);

        // <NAME> first, then <MEMO>: OfxParser writes '' rather than null for a
        // missing NAME, so the old `??` chain could never fall through and
        // memo-only banks imported blank descriptions (#338).
        $description = $this->pickSource($txn, $mapping, 'description', ['description', 'name', 'memo']) ?? '';
        $vendor = $this->pickSource($txn, $mapping, 'vendor', ['description', 'name', 'memo']) ?? '';
        $reference = $this->pickSource($txn, $mapping, 'reference', ['reference', 'id']);
        $notes = $this->pickSource($txn, $mapping, 'notes', ['memo']);

        // Repeating the description verbatim in notes helps nobody.
        if ($notes !== null && $notes === $description) {
            $notes = null;
        }

        return [
            'date' => $txn['date'] ?? '',
            'amount' => abs($amount),
            'type' => $amount >= 0 ? 'credit' : 'debit',
            'description' => $description,
            'memo' => $txn['memo'] ?? null,
            'reference' => $this->clampLength($reference, self::MAX_REFERENCE_LENGTH),
            'vendor' => $this->clampLength($vendor, self::MAX_VENDOR_LENGTH),
            'notes' => $this->clampLength($notes, self::MAX_NOTES_LENGTH),
            'id' => $txn['id'] ?? null, // Preserve FITID for duplicate detection
        ];
    }

    /**
     * The subset of an OFX/QIF row the import ID has always been derived from.
     *
     * Deliberately frozen and independent of the user's column mapping: the
     * content-hash branch of generateImportId() hashes description and
     * reference, so letting a mapping reach it would re-key every row and
     * re-import a whole statement as new transactions the first time someone
     * changed their mapping (#338).
     *
     * @param array $txn OFX/QIF transaction data, straight from the parser
     * @return array Identity fields for generateImportId()
     */
    public function ofxImportIdentity(array $txn): array {
        $amount = (float) ($txn['rawAmount'] ?? $txn['amount'] ?? 0);

        return [
            'date' => $txn['date'] ?? '',
            'amount' => abs($amount),
            'description' => $txn['description'] ?? $txn['name'] ?? '',
            'reference' => $txn['reference'] ?? $txn['id'] ?? null,
            'id' => $txn['id'] ?? null,
        ];
    }

    /**
     * Resolve one target field to a value from a parsed OFX/QIF row.
     *
     * An unset, blank or unresolvable choice falls back through $defaults
     * rather than blanking the field. That matters because the offered column
     * lists advertise names the parsers do not all emit, and because any one
     * row may be missing the tag the user picked.
     *
     * @param array $txn Parsed transaction row
     * @param array $mapping Field to source-column mapping
     * @param string $target Field being resolved
     * @param string[] $defaults Source keys to try when the mapping misses
     */
    private function pickSource(array $txn, array $mapping, string $target, array $defaults): ?string {
        $chosen = $mapping[$target] ?? null;
        $candidates = is_string($chosen) && $chosen !== '' ? [$chosen] : [];
        $candidates = array_merge($candidates, $defaults);

        foreach ($candidates as $key) {
            // QIF's 'category' is an array, so a scalar check is required.
            if (!isset($txn[$key]) || !is_scalar($txn[$key])) {
                continue;
            }
            $value = trim((string) $txn[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * Trim a value to its destination column width.
     *
     * Without this, routing a long MEMO into vendor or reference throws on
     * insert; ImportService catches that per row, so the user silently loses
     * transactions to an opaque error.
     */
    private function clampLength(?string $value, int $max): ?string {
        if ($value === null) {
            return null;
        }

        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
    }

    /**
     * Map a QIF transaction to standard format.
     *
     * Unused: ImportService routes QIF through mapOfxTransaction(), which is
     * what preserves the parser's synthesised 'id'. Wiring this method up
     * would drop that id and re-import every previously imported QIF row.
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
     * Detect the date format used across a batch of date strings.
     *
     * Scans all dates to find a format that parses every date without
     * overflow warnings. This disambiguates DD/MM vs MM/DD when any
     * date in the batch has a day value > 12.
     *
     * @param string[] $dateStrings Raw date strings from the import file
     */
    public function detectDateFormat(array $dateStrings): void {
        $this->detectedDateFormat = null;

        // Filter out empty strings and already-normalized dates
        $candidates = [];
        foreach ($dateStrings as $d) {
            $d = trim($d);
            if ($d === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || preg_match('/^\d{8}/', $d)) {
                continue;
            }
            $candidates[] = $d;
        }

        if (empty($candidates)) {
            return;
        }

        foreach (self::DATE_FORMATS as $format) {
            $allValid = true;
            foreach ($candidates as $d) {
                if (!$this->isValidDateParse($format, $d)) {
                    $allValid = false;
                    break;
                }
            }
            if ($allValid) {
                $this->detectedDateFormat = $format;
                return;
            }
        }
    }

    /**
     * Manually set the date format hint (e.g., from a preset).
     */
    public function setDateFormatHint(?string $format): void {
        $this->detectedDateFormat = $format;
    }

    /**
     * Reset the cached date format between import batches.
     */
    public function resetDateFormat(): void {
        $this->detectedDateFormat = null;
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

        // Use batch-detected format if available
        if ($this->detectedDateFormat !== null) {
            $parsed = $this->tryParseDate($this->detectedDateFormat, $date);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        // Fall back to trying each format with overflow rejection
        foreach (self::DATE_FORMATS as $format) {
            $parsed = $this->tryParseDate($format, $date);
            if ($parsed !== null) {
                return $parsed;
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
            // FITID is globally unique per bank, so we can use it directly.
            // The OFX spec allows FITIDs up to 255 chars but import_id is
            // VARCHAR(255) and may gain _dupN suffixes — hash overly long
            // ones. Threshold 245: 'ofx_fitid_' + 245 = 255, so every FITID
            // that previously imported successfully keeps its exact legacy ID
            // (re-import dedup continuity); only previously-failing ones change.
            $fitid = (string) $transaction['id'];
            return strlen($fitid) > 245
                ? 'ofx_fitid_h_' . md5($fitid)
                : 'ofx_fitid_' . $fitid;
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
     * Check if a date string validly parses with the given format.
     *
     * Rejects parses that produce no errors but are implausible, e.g.
     * a 4-digit year format (Y) consuming a 2-digit input like "26" → year 0026.
     */
    private function isValidDateParse(string $format, string $date): bool {
        $parsed = \DateTime::createFromFormat($format, $date);
        if ($parsed === false) {
            return false;
        }
        $errors = \DateTime::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return false;
        }
        // Reject if a 4-digit year format produced a year < 100 (mismatched input)
        if (str_contains($format, 'Y') && (int) $parsed->format('Y') < 100) {
            return false;
        }
        return true;
    }

    /**
     * Try to parse a date string with the given format.
     *
     * @return string|null Normalized Y-m-d string, or null on failure
     */
    private function tryParseDate(string $format, string $date): ?string {
        if (!$this->isValidDateParse($format, $date)) {
            return null;
        }
        $parsed = \DateTime::createFromFormat($format, $date);
        return $parsed->format('Y-m-d');
    }

    /**
     * Whether the mapping points a field at a real column, using the same rule
     * as mapRowToTransaction's mapping loop (column 0 is valid; false/null/''
     * are config flags or "not mapped").
     */
    private function mapsColumn(array $mapping, string $field): bool {
        $column = $mapping[$field] ?? null;
        return !is_bool($column) && $column !== null && $column !== '';
    }

    /**
     * Resolve a mapped type column's value to an internal type.
     *
     * @param mixed $value Raw value from the row's type column
     * @return string|null 'credit', 'debit', or null when nothing was mapped
     *                     or the value isn't one we recognize, in which case
     *                     the caller falls back to the amount's sign.
     */
    private function parseTypeValue(mixed $value): ?string {
        if (!is_scalar($value)) {
            return null;
        }

        // Exports pad these with case and punctuation: "Expense", "DR.", " debit "
        $normalized = strtolower(trim((string) $value, " \t\n\r\0\x0B.:;-_\"'"));
        if ($normalized === '') {
            return null;
        }

        return self::TYPE_WORDS[$normalized] ?? null;
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
     * The sign is resolved first and the magnitude parsed unsigned, because
     * every way of writing a negative other than a leading ASCII hyphen used
     * to be thrown away — see extractSign() (#339).
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

        [$negative, $amount] = $this->extractSign($amount);

        // If empty after cleanup, return 0
        if ($amount === '') {
            return 0.0;
        }

        $value = $this->parseUnsignedAmount($amount);

        return $negative ? -$value : $value;
    }

    /**
     * Split an amount string into its sign and its unsigned digits.
     *
     * Only a leading ASCII hyphen ever survived: the cleanup regex dropped a
     * typographic minus with the currency symbols, brackets are not a
     * character it keeps, and a trailing minus was both ignored by the cast
     * and — worse — left in place, pushing the decimal separator out of the
     * last three characters so "91,29-" parsed as 9129 (#339).
     *
     * @param string $amount Trimmed raw value from the file
     * @return array{0: bool, 1: string} Whether it is negative, and the
     *                                   value stripped of signs and currency
     */
    private function extractSign(string $amount): array {
        // Typographic minus signs, none of which are an ASCII hyphen.
        $amount = strtr($amount, [
            "\u{2212}" => '-', // MINUS SIGN
            "\u{2013}" => '-', // EN DASH
            "\u{2014}" => '-', // EM DASH
            "\u{2010}" => '-', // HYPHEN
            "\u{2011}" => '-', // NON-BREAKING HYPHEN
            "\u{FF0D}" => '-', // FULLWIDTH HYPHEN-MINUS
        ]);

        // (1,234.56): the accounting convention, and what a number of US and
        // UK exports write instead of a minus. Both brackets are required, so
        // a stray one stays a typo rather than silently flipping a row.
        $negative = false;
        if (strlen($amount) > 2 && str_starts_with($amount, '(') && str_ends_with($amount, ')')) {
            $negative = true;
            $amount = substr($amount, 1, -1);
        }

        // Remove currency symbols and whitespace
        $amount = preg_replace('/[^\d,.\-+]/', '', $amount);

        // A minus at either end means the same thing, and "(-42.50)" is one
        // negative written twice rather than a positive.
        if (str_contains($amount, '-')) {
            $negative = true;
        }

        return [$negative, str_replace(['-', '+'], '', $amount)];
    }

    /**
     * Parse the magnitude of an amount, deciding which separator is decimal.
     *
     * @param string $amount Digits and separators only, no sign
     */
    private function parseUnsignedAmount(string $amount): float {
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
