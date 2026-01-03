<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

class ValidationService {

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
        $validCurrencies = [
            'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'JPY', 'CHF', 'CNY', 'SEK', 'NOK',
            'MXN', 'NZD', 'SGD', 'HKD', 'ZAR', 'INR', 'BRL', 'RUB', 'KRW', 'TRY'
        ];

        $currency = strtoupper(trim($currency));

        if (!in_array($currency, $validCurrencies)) {
            return ['valid' => false, 'error' => 'Invalid currency code'];
        }

        return ['valid' => true, 'formatted' => $currency];
    }

    /**
     * Validate account type
     */
    public function validateAccountType(string $type): array {
        $validTypes = [
            'checking', 'savings', 'credit_card', 'investment', 'loan', 'cash', 'money_market'
        ];

        if (!in_array($type, $validTypes)) {
            return ['valid' => false, 'error' => 'Invalid account type'];
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