<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

class ToshlPreset implements ImportPresetInterface {
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

        return $normalizedRow;
    }
}
