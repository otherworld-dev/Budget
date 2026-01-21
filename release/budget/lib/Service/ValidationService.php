<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Enum\AccountType;
use OCA\Budget\Enum\Currency;
use OCA\Budget\Enum\Frequency;
use OCA\Budget\Enum\TransactionType;

class ValidationService {
    /**
     * Maximum length constants for string fields.
     * These should match database column limits.
     */
    public const MAX_NAME_LENGTH = 255;
    public const MAX_DESCRIPTION_LENGTH = 1000;
    public const MAX_NOTES_LENGTH = 2000;
    public const MAX_VENDOR_LENGTH = 255;
    public const MAX_REFERENCE_LENGTH = 255;
    public const MAX_PATTERN_LENGTH = 500;
    public const MAX_ICON_LENGTH = 50;
    public const MAX_COLOR_LENGTH = 20;

    /**
     * Validate string length with custom limits.
     *
     * @param string|null $value The value to validate
     * @param string $fieldName Field name for error messages
     * @param int $maxLength Maximum allowed length
     * @param int $minLength Minimum required length (default 0)
     * @param bool $required Whether the field is required
     * @return array ['valid' => bool, 'error' => string|null, 'sanitized' => string|null]
     */
    public function validateStringLength(
        ?string $value,
        string $fieldName,
        int $maxLength,
        int $minLength = 0,
        bool $required = false
    ): array {
        // Handle null/empty
        if ($value === null || $value === '') {
            if ($required) {
                return ['valid' => false, 'error' => "{$fieldName} is required", 'sanitized' => null];
            }
            return ['valid' => true, 'error' => null, 'sanitized' => null];
        }

        $trimmed = trim($value);
        $length = mb_strlen($trimmed);

        if ($length < $minLength) {
            return [
                'valid' => false,
                'error' => "{$fieldName} must be at least {$minLength} characters",
                'sanitized' => null
            ];
        }

        if ($length > $maxLength) {
            return [
                'valid' => false,
                'error' => "{$fieldName} must not exceed {$maxLength} characters",
                'sanitized' => null
            ];
        }

        return ['valid' => true, 'error' => null, 'sanitized' => $trimmed];
    }

    /**
     * Validate a name field (account name, category name, etc.)
     */
    public function validateName(?string $name, bool $required = true): array {
        return $this->validateStringLength($name, 'Name', self::MAX_NAME_LENGTH, $required ? 1 : 0, $required);
    }

    /**
     * Validate a description field.
     */
    public function validateDescription(?string $description, bool $required = false): array {
        return $this->validateStringLength($description, 'Description', self::MAX_DESCRIPTION_LENGTH, 0, $required);
    }

    /**
     * Validate a notes field.
     */
    public function validateNotes(?string $notes): array {
        return $this->validateStringLength($notes, 'Notes', self::MAX_NOTES_LENGTH, 0, false);
    }

    /**
     * Validate a vendor name.
     */
    public function validateVendor(?string $vendor): array {
        return $this->validateStringLength($vendor, 'Vendor', self::MAX_VENDOR_LENGTH, 0, false);
    }

    /**
     * Validate a reference field.
     */
    public function validateReference(?string $reference): array {
        return $this->validateStringLength($reference, 'Reference', self::MAX_REFERENCE_LENGTH, 0, false);
    }

    /**
     * Validate a pattern field (for import rules).
     */
    public function validatePattern(?string $pattern, bool $required = true): array {
        $result = $this->validateStringLength($pattern, 'Pattern', self::MAX_PATTERN_LENGTH, $required ? 1 : 0, $required);

        if (!$result['valid']) {
            return $result;
        }

        // Validate regex if it looks like one
        if ($result['sanitized'] !== null && str_starts_with($result['sanitized'], '/')) {
            if (@preg_match($result['sanitized'], '') === false) {
                return ['valid' => false, 'error' => 'Invalid regular expression pattern', 'sanitized' => null];
            }
        }

        return $result;
    }

    /**
     * Validate an icon field.
     */
    public function validateIcon(?string $icon): array {
        return $this->validateStringLength($icon, 'Icon', self::MAX_ICON_LENGTH, 0, false);
    }

    /**
     * Validate a color field.
     */
    public function validateColor(?string $color): array {
        $result = $this->validateStringLength($color, 'Color', self::MAX_COLOR_LENGTH, 0, false);

        if (!$result['valid'] || $result['sanitized'] === null) {
            return $result;
        }

        // Validate hex color format
        if (!preg_match('/^#([A-Fa-f0-9]{3}|[A-Fa-f0-9]{6})$/', $result['sanitized'])) {
            return ['valid' => false, 'error' => 'Color must be a valid hex code (e.g., #FFF or #FFFFFF)', 'sanitized' => null];
        }

        return $result;
    }

    /**
     * Validate a date string.
     */
    public function validateDate(?string $date, string $fieldName = 'Date', bool $required = false): array {
        if ($date === null || $date === '') {
            if ($required) {
                return ['valid' => false, 'error' => "{$fieldName} is required", 'formatted' => null];
            }
            return ['valid' => true, 'error' => null, 'formatted' => null];
        }

        $parsed = \DateTime::createFromFormat('Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            return ['valid' => false, 'error' => "{$fieldName} must be in YYYY-MM-DD format", 'formatted' => null];
        }

        return ['valid' => true, 'error' => null, 'formatted' => $date];
    }

    /**
     * Validate a monetary amount.
     */
    public function validateAmount(mixed $amount, string $fieldName = 'Amount', bool $required = true): array {
        if ($amount === null || $amount === '') {
            if ($required) {
                return ['valid' => false, 'error' => "{$fieldName} is required", 'value' => null];
            }
            return ['valid' => true, 'error' => null, 'value' => null];
        }

        if (!is_numeric($amount)) {
            return ['valid' => false, 'error' => "{$fieldName} must be a number", 'value' => null];
        }

        $floatVal = (float) $amount;

        // Check for reasonable limits (prevent overflow)
        if (abs($floatVal) > 999999999999.99) {
            return ['valid' => false, 'error' => "{$fieldName} exceeds maximum allowed value", 'value' => null];
        }

        return ['valid' => true, 'error' => null, 'value' => $floatVal];
    }

    /**
     * Validate IBAN format
     */
    public function validateIban(string $iban): array {
        $iban = strtoupper(str_replace(' ', '', $iban));

        if (strlen($iban) < 15 || strlen($iban) > 34) {
            return ['valid' => false, 'error' => 'IBAN must be 15-34 characters long'];
        }

        if (!preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $iban)) {
            return ['valid' => false, 'error' => 'Invalid IBAN format'];
        }

        // IBAN mod-97 checksum validation
        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numericString = '';

        for ($i = 0; $i < strlen($rearranged); $i++) {
            $char = $rearranged[$i];
            if (is_numeric($char)) {
                $numericString .= $char;
            } else {
                $numericString .= (ord($char) - ord('A') + 10);
            }
        }

        $remainder = bcmod($numericString, '97');

        if ($remainder !== '1') {
            return ['valid' => false, 'error' => 'Invalid IBAN checksum'];
        }

        return ['valid' => true, 'formatted' => $iban];
    }

    /**
     * Validate US routing number
     */
    public function validateRoutingNumber(string $routingNumber): array {
        $routingNumber = preg_replace('/\D/', '', $routingNumber);

        if (strlen($routingNumber) !== 9) {
            return ['valid' => false, 'error' => 'Routing number must be 9 digits'];
        }

        // ABA routing number checksum validation
        $checksum = 0;
        for ($i = 0; $i < 9; $i++) {
            $digit = (int) $routingNumber[$i];
            $multiplier = [3, 7, 1, 3, 7, 1, 3, 7, 1][$i];
            $checksum += $digit * $multiplier;
        }

        if ($checksum % 10 !== 0) {
            return ['valid' => false, 'error' => 'Invalid routing number checksum'];
        }

        return ['valid' => true, 'formatted' => $routingNumber];
    }

    /**
     * Validate UK sort code
     */
    public function validateSortCode(string $sortCode): array {
        $sortCode = preg_replace('/\D/', '', $sortCode);

        if (strlen($sortCode) !== 6) {
            return ['valid' => false, 'error' => 'Sort code must be 6 digits'];
        }

        $formatted = substr($sortCode, 0, 2) . '-' . substr($sortCode, 2, 2) . '-' . substr($sortCode, 4, 2);

        return ['valid' => true, 'formatted' => $formatted];
    }

    /**
     * Validate SWIFT/BIC code
     */
    public function validateSwiftBic(string $swiftBic): array {
        $swiftBic = strtoupper(str_replace(' ', '', $swiftBic));

        if (!preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $swiftBic)) {
            return ['valid' => false, 'error' => 'Invalid SWIFT/BIC format'];
        }

        if (strlen($swiftBic) !== 8 && strlen($swiftBic) !== 11) {
            return ['valid' => false, 'error' => 'SWIFT/BIC must be 8 or 11 characters'];
        }

        return ['valid' => true, 'formatted' => $swiftBic];
    }

    /**
     * Validate account number format
     */
    public function validateAccountNumber(string $accountNumber, string $accountType = null): array {
        $accountNumber = trim($accountNumber);

        if (empty($accountNumber)) {
            return ['valid' => false, 'error' => 'Account number cannot be empty'];
        }

        if (strlen($accountNumber) < 4) {
            return ['valid' => false, 'error' => 'Account number too short'];
        }

        if (strlen($accountNumber) > 20) {
            return ['valid' => false, 'error' => 'Account number too long'];
        }

        return ['valid' => true, 'formatted' => $accountNumber];
    }

    /**
     * Validate currency code
     */
    public function validateCurrency(string $currency): array {
        $currencyEnum = Currency::tryFromString($currency);

        if ($currencyEnum === null) {
            return ['valid' => false, 'error' => 'Invalid currency code'];
        }

        return ['valid' => true, 'formatted' => $currencyEnum->value];
    }

    /**
     * Validate account type
     */
    public function validateAccountType(string $type): array {
        if (!AccountType::isValid($type)) {
            return ['valid' => false, 'error' => 'Invalid account type'];
        }

        return ['valid' => true, 'formatted' => $type];
    }

    /**
     * Validate bill frequency
     */
    public function validateFrequency(string $frequency): array {
        if (!Frequency::isValid($frequency)) {
            return [
                'valid' => false,
                'error' => 'Invalid frequency. Must be one of: ' . implode(', ', Frequency::values())
            ];
        }

        return ['valid' => true, 'formatted' => $frequency];
    }

    /**
     * Validate transaction type
     */
    public function validateTransactionType(string $type): array {
        if (!TransactionType::isValid($type)) {
            return ['valid' => false, 'error' => 'Invalid transaction type'];
        }

        return ['valid' => true, 'formatted' => $type];
    }

    /**
     * Get banking field requirements based on currency/country
     */
    public function getBankingFieldRequirements(string $currency): array {
        $requirements = [
            'USD' => ['routing_number' => true, 'sort_code' => false, 'iban' => false],
            'GBP' => ['routing_number' => false, 'sort_code' => true, 'iban' => true],
            'EUR' => ['routing_number' => false, 'sort_code' => false, 'iban' => true],
            'CAD' => ['routing_number' => true, 'sort_code' => false, 'iban' => false],
            'AUD' => ['routing_number' => false, 'sort_code' => false, 'iban' => false],
        ];

        return $requirements[$currency] ?? ['routing_number' => false, 'sort_code' => false, 'iban' => false];
    }

    /**
     * Get list of popular banking institutions
     */
    public function getBankingInstitutions(): array {
        return [
            'US' => [
                'Chase Bank', 'Bank of America', 'Wells Fargo', 'Citibank', 'U.S. Bank',
                'PNC Bank', 'Capital One', 'TD Bank', 'BB&T', 'SunTrust Bank'
            ],
            'UK' => [
                'Barclays', 'HSBC', 'Lloyds Bank', 'NatWest', 'Santander UK',
                'Royal Bank of Scotland', 'TSB', 'Nationwide', 'Halifax', 'Metro Bank'
            ],
            'EU' => [
                'Deutsche Bank', 'BNP Paribas', 'Credit Agricole', 'ING Group', 'Santander',
                'UniCredit', 'BBVA', 'Societe Generale', 'Commerzbank', 'Rabobank'
            ],
            'CA' => [
                'Royal Bank of Canada', 'Toronto-Dominion Bank', 'Bank of Nova Scotia',
                'Bank of Montreal', 'Canadian Imperial Bank', 'National Bank of Canada'
            ]
        ];
    }
}