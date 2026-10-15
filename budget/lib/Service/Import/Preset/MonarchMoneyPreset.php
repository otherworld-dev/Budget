<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

/**
 * Monarch Money's transaction download (Settings > Data > Download
 * transactions, or Export on the Transactions page).
 *
 * One signed Amount column, negative for money going out. There is no
 * currency column. A transfer or card payment is two rows, one per account,
 * both categorised "Transfer" or "Credit Card Payment" and not otherwise tied
 * together, so the two sides are linked by matching amount and date across
 * accounts.
 */
class MonarchMoneyPreset extends AbstractAppExportPreset {
	/** Monarch's built-in categories for money moving between own accounts. */
	private const TRANSFER_CATEGORIES = ['transfer', 'credit card payment'];

	public function getId(): string {
		return 'monarch-money';
	}

	public function getName(): string {
		return 'Monarch Money';
	}

	public function getDescription(): string {
		return 'Import transactions, accounts, categories and tags from a Monarch Money transactions download';
	}

	public function getMapping(): array {
		return [
			'date' => 'Date',
			'amount' => 'Amount',
			'description' => 'Merchant',
			'notes' => 'Notes',
		];
	}

	public function getRequiredHeaders(): array {
		return ['Date', 'Merchant', 'Category', 'Account', 'Original Statement', 'Amount'];
	}

	protected function getAccountColumn(): string {
		return 'Account';
	}

	public function postProcessRow(array $normalizedRow, array $rawCsvRow): ?array {
		$row = $this->withAccount($normalizedRow, $this->cell($rawCsvRow, 'Account'));

		$merchant = $this->cell($rawCsvRow, 'Merchant');
		$statement = $this->cell($rawCsvRow, 'Original Statement');
		if ($merchant === '' && $statement !== '') {
			$row['description'] = $statement;
		}
		if ($merchant !== '') {
			$row['vendor'] = $merchant;
		}

		$category = $this->cell($rawCsvRow, 'Category');
		if (in_array(strtolower($category), self::TRANSFER_CATEGORIES, true)) {
			$row = $this->markTransfer($row, '');
		} else {
			if ($category !== '' && strtolower($category) !== 'uncategorized') {
				$row['_categoryName'] = $category;
			}
			$tags = $this->splitTags($this->cell($rawCsvRow, 'Tags'));
			if ($tags !== []) {
				$row['_tagNames'] = $tags;
			}
		}

		// The statement text is the bank's; Merchant is Monarch's cleaned-up
		// (and user-editable) name for it.
		$row = $this->freezeIdentity($row, $this->cell($rawCsvRow, 'Date'), $statement !== '' ? $statement : $merchant);
		$row['source'] = 'Monarch Money';

		return $row;
	}
}
