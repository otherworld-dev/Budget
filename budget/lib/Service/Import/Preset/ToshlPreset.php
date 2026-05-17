<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

class ToshlPreset implements ImportPresetInterface {
    private const ACCOUNT_TYPE_MAP = [
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
    public function getId(): string {
        return 'toshl';
    }

    public function getName(): string {
        return 'Toshl Finance';
    }

    public function getDescription(): string {
        return 'Import expenses, income, and categories from Toshl Finance CSV export';
    }

    public function getMapping(): array {
        return [
            'date' => 'Date',
            'description' => 'Description',
            'expenseColumn' => 'Expense',
            'incomeColumn' => 'Income',
        ];
    }

    public function getDateFormatHint(): ?string {
        return 'd.m.y';
    }

    public function getDelimiter(): string {
        return ',';
    }

    public function getOptions(): array {
        return [
            'autoCreateCategories' => true,
            'categoryColumn' => 'Category',
            'tagColumn' => 'Tags',
            'accountColumn' => 'Account',
            'transferMarker' => 'transaction',
        ];
    }

    public function getExpectedHeaders(): ?array {
        return [
            'Date',
            'Account',
            'Category',
            'Tags',
            'Expense',
            'Income',
            'Currency',
            'In Main Currency',
            'Main Currency',
            'Description',
        ];
    }

    public function postProcessRow(array $normalizedRow, array $rawCsvRow): ?array {
        $category = trim($rawCsvRow['Category'] ?? '');

        // Skip transfer rows
        if (strtolower($category) === 'transaction') {
            return null;
        }

        // Attach category name for auto-creation
        if ($category !== '') {
            $normalizedRow['_categoryName'] = $category;
        }

        // Attach tag names for tag set creation (all tags, not just first)
        $tags = trim($rawCsvRow['Tags'] ?? '');
        if ($tags !== '') {
            $normalizedRow['_tagNames'] = array_filter(array_map('trim', explode(',', $tags)));
        }

        // Attach account name for multi-account resolution
        $account = trim($rawCsvRow['Account'] ?? '');
        if ($account !== '') {
            $normalizedRow['_accountName'] = $account;
        }

        // Currency conversion: use main currency amount when currencies differ
        $txCurrency = strtoupper(trim($rawCsvRow['Currency'] ?? ''));
        $mainCurrency = strtoupper(trim($rawCsvRow['Main Currency'] ?? ''));

        if ($txCurrency !== '' && $mainCurrency !== '' && $txCurrency !== $mainCurrency) {
            $mainAmount = trim($rawCsvRow['In Main Currency'] ?? '');
            if ($mainAmount !== '' && $mainAmount !== '0') {
                // Parse amount: remove thousands separators, normalize decimal separator
                $mainAmount = str_replace('.', '', $mainAmount);
                $mainAmount = str_replace(',', '.', $mainAmount);
                $normalizedRow['amount'] = abs((float) $mainAmount);
                $normalizedRow['_currency'] = $mainCurrency;
            } else {
                $normalizedRow['_currency'] = $txCurrency;
            }
        } else {
            // Same currency or single-currency export
            $currency = $mainCurrency ?: $txCurrency;
            if ($currency !== '') {
                $normalizedRow['_currency'] = $currency;
            }
        }

        return $normalizedRow;
    }

    public function inferAccountType(string $accountName): string {
        $lower = strtolower(trim($accountName));
        // Exact match first
        if (isset(self::ACCOUNT_TYPE_MAP[$lower])) {
            return self::ACCOUNT_TYPE_MAP[$lower];
        }
        // Partial match
        foreach (self::ACCOUNT_TYPE_MAP as $keyword => $type) {
            if (str_contains($lower, $keyword)) {
                return $type;
            }
        }
        return 'checking'; // Default fallback
    }
}
