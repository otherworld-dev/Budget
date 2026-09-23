<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

/**
 * YNAB's register export: the "... - Register.csv" file in the zip that
 * "Export Plan Data" (formerly "Export budget data") downloads, and the
 * register CSV of YNAB 4 / Classic, which shares the columns read here.
 *
 * Amounts are split into unsigned Outflow and Inflow columns written in the
 * plan's own currency format ("$1,234.56", "1.234,56 €"); a plan with a comma
 * decimal is exported tab-separated, which ImportService handles by trying
 * the other delimiters when the header does not split on commas. Dates follow
 * the plan's date setting, so the format is detected from the whole file.
 *
 * A transfer is written in both accounts with the payee
 * "Transfer : <other account>"; the two sides are linked after import.
 * "Ready to Assign" (YNAB 4: "To be Budgeted" / "Available this month")
 * is where YNAB files income rather than a category, so those rows are left
 * uncategorized.
 */
class YnabPreset extends AbstractAppExportPreset {
    /** YNAB's holding "categories" for income, which are not real categories. */
    private const INCOME_HOLDING = [
        'ready to assign',
        'to be budgeted',
        'available this month',
        'available next month',
        'inflow',
    ];

    public function getId(): string {
        return 'ynab';
    }

    public function getName(): string {
        return 'YNAB';
    }

    public function getDescription(): string {
        return 'Import transactions, accounts and categories from a YNAB register export (current YNAB or YNAB 4)';
    }

    public function getMapping(): array {
        return [
            'date' => 'Date',
            'description' => 'Payee',
            'notes' => 'Memo',
            'expenseColumn' => 'Outflow',
            'incomeColumn' => 'Inflow',
        ];
    }

    public function getRequiredHeaders(): array {
        return ['Account', 'Date', 'Payee', 'Memo', 'Outflow', 'Inflow'];
    }

    protected function getAccountColumn(): string {
        return 'Account';
    }

    public function postProcessRow(array $normalizedRow, array $rawCsvRow): ?array {
        $row = $this->withAccount($normalizedRow, $this->cell($rawCsvRow, 'Account'));
        $payee = $this->cell($rawCsvRow, 'Payee');

        $checkNumber = $this->cell($rawCsvRow, 'Check Number');
        if ($checkNumber !== '') {
            $row['reference'] = $checkNumber;
        }

        [$group, $category] = $this->category($rawCsvRow);

        $peer = $this->transferPeer($payee);
        if ($peer !== null) {
            $row = $this->markTransfer($row, $peer);
        } elseif ($payee !== '') {
            $row['vendor'] = $payee;
        }

        // A transfer to a tracking account (a loan, say) carries a category on
        // its budget side. Keep it: it is the only record of what the money
        // was for if the other side is not in the file.
        if ($category !== '') {
            $row['_categoryName'] = $category;
            if ($group !== '') {
                $row['_categoryParent'] = $group;
            }
        }

        $row = $this->freezeIdentity($row, $this->cell($rawCsvRow, 'Date'), $payee);
        $row['source'] = 'YNAB';

        return $row;
    }

    /**
     * The row's category group and category, from whichever columns this
     * version of the export has. Income's holding category comes back empty.
     *
     * @return array{0: string, 1: string}
     */
    private function category(array $raw): array {
        // Current YNAB: Category Group + Category, plus a combined
        // "Category Group/Category". YNAB 4: Master Category + Sub Category,
        // and its "Category" column is the combined one.
        $ynab4 = array_key_exists('Sub Category', $raw);
        if ($ynab4) {
            $group = $this->cell($raw, 'Master Category');
            $category = $this->cell($raw, 'Sub Category');
            $combined = $this->cell($raw, 'Category');
        } else {
            $group = $this->cell($raw, 'Category Group');
            $category = $this->cell($raw, 'Category');
            $combined = $this->cell($raw, 'Category Group/Category');
        }

        if ($category === '' && $combined !== '') {
            $parts = array_map('trim', explode(':', $combined, 2));
            if (count($parts) === 2) {
                $group = $group !== '' ? $group : $parts[0];
                $category = $parts[1];
            } else {
                $category = $parts[0];
            }
        }

        if (in_array(strtolower($category), self::INCOME_HOLDING, true)
            || in_array(strtolower($group), ['inflow', 'internal master category'], true)) {
            return ['', ''];
        }

        return [$group, $category];
    }

    /**
     * The other account when the payee names a transfer, else null.
     * YNAB writes "Transfer : Savings"; older builds drop the spaces.
     */
    private function transferPeer(string $payee): ?string {
        if (preg_match('/^transfer\s*:\s*(.+)$/i', $payee, $m) === 1) {
            return trim($m[1]);
        }
        return null;
    }
}
