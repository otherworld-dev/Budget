<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

/**
 * Mint's "Export all transactions" file (transactions.csv).
 *
 * Every Amount is unsigned; Transaction Type says debit or credit. Dates are
 * US month/day/year. Mint had no splits and no currency column. A transfer
 * between two of the user's accounts is two ordinary rows categorised
 * "Transfer" (or "Credit Card Payment"), with nothing tying the two together,
 * so the two sides are linked by matching amount and date across accounts.
 */
class MintPreset extends AbstractAppExportPreset {
	/** Mint categories that mean money moving between the user's own accounts. */
	private const TRANSFER_CATEGORIES = ['transfer', 'credit card payment', 'transfer for cash spending'];

	public function getId(): string {
		return 'mint';
	}

	public function getName(): string {
		return 'Mint';
	}

	public function getDescription(): string {
		return 'Import transactions, accounts, categories and labels from a Mint transactions.csv export';
	}

	public function getMapping(): array {
		return [
			'date' => 'Date',
			'amount' => 'Amount',
			'type' => 'Transaction Type',
			'description' => 'Description',
			'notes' => 'Notes',
		];
	}

	public function getDateFormatHint(): ?string {
		return 'm/d/Y';
	}

	public function getRequiredHeaders(): array {
		return ['Date', 'Description', 'Original Description', 'Amount', 'Transaction Type', 'Category', 'Account Name'];
	}

	protected function getAccountColumn(): string {
		return 'Account Name';
	}

	public function postProcessRow(array $normalizedRow, array $rawCsvRow): ?array {
		$row = $this->withAccount($normalizedRow, $this->cell($rawCsvRow, 'Account Name'));

		$description = $this->cell($rawCsvRow, 'Description');
		$original = $this->cell($rawCsvRow, 'Original Description');
		if ($description === '' && $original !== '') {
			$row['description'] = $original;
		}
		if ($description !== '') {
			$row['vendor'] = $description;
		}

		$category = $this->cell($rawCsvRow, 'Category');
		if (in_array(strtolower($category), self::TRANSFER_CATEGORIES, true)) {
			$row = $this->markTransfer($row, '');
		} else {
			if ($category !== '' && strtolower($category) !== 'uncategorized') {
				$row['_categoryName'] = $category;
			}
			$labels = $this->splitTags($this->cell($rawCsvRow, 'Labels'));
			if ($labels !== []) {
				$row['_tagNames'] = $labels;
			}
		}

		// The bank's own text: Mint let the user rename Description, never
		// this, so a re-export after tidying names still matches.
		$row = $this->freezeIdentity($row, $this->cell($rawCsvRow, 'Date'), $original !== '' ? $original : $description);
		$row['source'] = 'Mint';

		return $row;
	}
}
