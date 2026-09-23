<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

/**
 * Actual Budget's transaction export (the Export button above a register,
 * or above "All accounts" for everything at once).
 *
 * One signed Amount column. A split transaction is written as its total
 * (Amount 0, the total in Split_Amount, notes "(SPLIT INTO n)") followed by
 * one row per part; the parts are imported as ordinary transactions and the
 * total row is dropped, or the money would be counted twice. Older exports
 * have no Split_Amount or Category_Group column and no split rows.
 *
 * A transfer has no category in Actual. Its payee is the other account's
 * name, or blank when the export leaves it out, so a row with no category is
 * treated as a possible transfer and linked only if a matching opposite row
 * turns up in another account of the same file.
 */
class ActualBudgetPreset extends AbstractAppExportPreset {
	public function getId(): string {
		return 'actual-budget';
	}

	public function getName(): string {
		return 'Actual Budget';
	}

	public function getDescription(): string {
		return 'Import transactions, accounts and categories from an Actual Budget transaction export';
	}

	public function getMapping(): array {
		return [
			'date' => 'Date',
			'amount' => 'Amount',
			'description' => 'Payee',
			'notes' => 'Notes',
		];
	}

	public function getDateFormatHint(): ?string {
		return 'Y-m-d';
	}

	public function getRequiredHeaders(): array {
		return ['Account', 'Date', 'Payee', 'Notes', 'Category', 'Amount'];
	}

	protected function getAccountColumn(): string {
		return 'Account';
	}

	/**
	 * Drop a split's total row: its parts follow it as rows of their own.
	 */
	public function expandRow(array $row): array {
		if ($this->isSplitTotal($row)) {
			return [];
		}
		return [$row];
	}

	public function postProcessRow(array $normalizedRow, array $rawCsvRow): ?array {
		if ($this->isSplitTotal($rawCsvRow)) {
			return null;
		}

		$row = $this->withAccount($normalizedRow, $this->cell($rawCsvRow, 'Account'));
		$payee = $this->cell($rawCsvRow, 'Payee');
		if ($payee !== '') {
			$row['description'] = $payee;
			$row['vendor'] = $payee;
		}

		$notes = $this->cell($rawCsvRow, 'Notes');
		if ($notes !== '' || array_key_exists('notes', $row)) {
			$row['notes'] = $notes === '' ? null : $notes;
		}

		$category = $this->cell($rawCsvRow, 'Category');
		if ($category !== '') {
			$row['_categoryName'] = $category;
			$group = $this->cell($rawCsvRow, 'Category_Group');
			if ($group !== '') {
				$row['_categoryParent'] = $group;
			}
		} else {
			$row = $this->markTransfer($row, $this->transferPeer($payee));
		}

		$row = $this->freezeIdentity($row, $this->cell($rawCsvRow, 'Date'), $payee);
		$row['source'] = 'Actual Budget';

		return $row;
	}

	private function isSplitTotal(array $row): bool {
		$split = $this->cell($row, 'Split_Amount');
		$amount = $this->cell($row, 'Amount');
		return $split !== '' && (float)$split != 0.0 && ($amount === '' || (float)$amount == 0.0);
	}

	/**
	 * The other account, from a transfer's payee ("Transfer: Savings" in
	 * some versions, just "Savings" or nothing in others).
	 */
	private function transferPeer(string $payee): string {
		if (preg_match('/^transfer\s*:\s*(.+)$/i', $payee, $m) === 1) {
			return trim($m[1]);
		}
		return $payee;
	}
}
