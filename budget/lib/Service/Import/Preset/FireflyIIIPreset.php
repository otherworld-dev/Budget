<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import\Preset;

/**
 * Firefly III's own export (Options > Export data > transactions).
 *
 * Firefly III writes one row per journal with a source and a destination
 * account, so the direction comes from which side is one of the user's own
 * accounts rather than from the amount's sign: a withdrawal leaves its source
 * asset account, a deposit lands in its destination. A transfer has an own
 * account on both sides and becomes two rows here, one per account, linked
 * as a transfer. Opening balances and reconciliations are ordinary rows on
 * the one own account involved.
 *
 * Columns are read by name: 6.x moved and added currency columns, so their
 * position is not stable between versions.
 *
 * A journal's amount and currency_code are in its source account's
 * currency. A transfer between accounts in two currencies carries the
 * destination's own figures in foreign_amount and foreign_currency_code, so
 * that side is imported from those.
 */
class FireflyIIIPreset extends AbstractAppExportPreset {
	/** Firefly III account types that are the user's own accounts. */
	private const OWN_ACCOUNT_TYPES = [
		'asset account' => null,
		'default account' => null,
		'loan' => 'loan',
		'debt' => 'loan',
		'mortgage' => 'mortgage',
		'credit card' => 'credit_card',
	];

	public function getId(): string {
		return 'firefly-iii';
	}

	public function getName(): string {
		return 'Firefly III';
	}

	public function getDescription(): string {
		return 'Import transactions, accounts, categories and tags from a Firefly III data export';
	}

	public function getMapping(): array {
		return [
			'date' => 'date',
			'amount' => 'amount',
			'description' => 'description',
			'notes' => 'notes',
		];
	}

	public function getRequiredHeaders(): array {
		return [
			'journal_id',
			'type',
			'amount',
			'description',
			'date',
			'source_name',
			'source_type',
			'destination_name',
			'destination_type',
		];
	}

	protected function getAccountColumn(): string {
		return 'source_name';
	}

	public function expandRow(array $row): array {
		$sourceOwn = $this->isOwnAccount($this->cell($row, 'source_type'));
		$destinationOwn = $this->isOwnAccount($this->cell($row, 'destination_type'));

		if ($sourceOwn && $destinationOwn) {
			return [
				$row + ['__side' => 'source'],
				$row + ['__side' => 'destination'],
			];
		}
		if ($destinationOwn) {
			return [$row + ['__side' => 'destination']];
		}
		if ($sourceOwn) {
			return [$row + ['__side' => 'source']];
		}

		// Neither side is recognisably the user's (an account type this
		// preset does not know): fall back on the journal type.
		$side = strtolower($this->cell($row, 'type')) === 'deposit' ? 'destination' : 'source';
		return [$row + ['__side' => $side]];
	}

	public function postProcessRow(array $normalizedRow, array $rawCsvRow): ?array {
		$side = $rawCsvRow['__side'] ?? ($this->expandRow($rawCsvRow)[0]['__side']);
		$own = $side === 'destination' ? 'destination' : 'source';
		$other = $own === 'source' ? 'destination' : 'source';

		$row = $normalizedRow;
		$accountName = $this->cell($rawCsvRow, $own . '_name');
		$otherName = $this->cell($rawCsvRow, $other . '_name');
		$row = $this->withAccount($row, $accountName);

		$accountType = self::OWN_ACCOUNT_TYPES[strtolower($this->cell($rawCsvRow, $own . '_type'))] ?? null;
		if ($accountName !== '') {
			$row['_accountType'] = $accountType ?? $this->inferAccountType($accountName);
		}

		$otherOwn = $this->isOwnAccount($this->cell($rawCsvRow, $other . '_type'));
		$foreign = $this->foreignFigures($rawCsvRow);
		// The receiving side of a transfer across currencies
		$inForeign = $own === 'destination' && $otherOwn && $foreign !== null;

		$currency = $inForeign ? $foreign['currency'] : strtoupper($this->cell($rawCsvRow, 'currency_code'));
		if ($currency !== '') {
			$row['_currency'] = $currency;
		}
		if ($inForeign) {
			$row['amount'] = $foreign['amount'];
		}

		// Money leaves the source account and arrives in the destination,
		// whatever sign the export gave the amount.
		$row['type'] = $own === 'source' ? 'debit' : 'credit';
		unset($row['_typeUnresolved']);

		// "2024-03-05T00:00:00+01:00": take the calendar date as written. The
		// offset is the user's own Firefly timezone; converting it to the
		// server's could move the transaction to the day before.
		$rawDate = $this->cell($rawCsvRow, 'date');
		if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $rawDate, $m) === 1) {
			$row['date'] = $m[1];
		}

		$description = $this->cell($rawCsvRow, 'description');
		if ($description === '') {
			$description = $this->cell($rawCsvRow, 'group_title');
		}
		if (array_key_exists('description', $row) || $description !== '') {
			$row['description'] = $description;
		}

		// The figures in the other currency, kept as a note: the transaction
		// is imported in the account's own currency
		$otherFigures = $inForeign
			? $this->amountNote($this->cell($rawCsvRow, 'amount'), $this->cell($rawCsvRow, 'currency_code'))
			: $this->foreignAmountNote($rawCsvRow);
		$notes = $this->joinNotes($this->cell($rawCsvRow, 'notes'), $otherFigures ?? '');
		if ($notes !== null || array_key_exists('notes', $row)) {
			$row['notes'] = $notes;
		}

		if ($otherOwn) {
			$row = $this->markTransfer($row, $otherName);
			// Both sides come from this one journal. Across currencies their
			// amounts differ, so this is how they find each other.
			$row['_transferPair'] = 'firefly:' . $this->cell($rawCsvRow, 'journal_id');
		} else {
			// The expense or revenue account is who was paid, or who paid
			if ($otherName !== '' && !$this->isPseudoAccount($this->cell($rawCsvRow, $other . '_type'))) {
				$row['vendor'] = $otherName;
			}
			$category = $this->cell($rawCsvRow, 'category');
			if ($category !== '') {
				$row['_categoryName'] = $category;
			}
			$tags = $this->splitTags($this->cell($rawCsvRow, 'tags'));
			if ($tags !== []) {
				$row['_tagNames'] = $tags;
			}
		}

		// journal_id is Firefly's own permanent id for the row, so it keys
		// the import on its own: editing a description in Firefly and
		// exporting again still matches what was imported before.
		$row = $this->freezeIdentity($row, $rawDate, '', 'firefly:' . $this->cell($rawCsvRow, 'journal_id'));
		$row['source'] = 'Firefly III';

		return $row;
	}

	private function isOwnAccount(string $firefly): bool {
		return array_key_exists(strtolower($firefly), self::OWN_ACCOUNT_TYPES);
	}

	/**
	 * Firefly III's bookkeeping counter-accounts, which are nobody's name.
	 */
	private function isPseudoAccount(string $firefly): bool {
		return in_array(strtolower($firefly), [
			'initial balance account',
			'reconciliation account',
			'liability credit account',
			'cash account',
		], true);
	}

	/**
	 * The amount in a second currency, kept as a note: the transaction is
	 * imported in the account's own currency.
	 */
	private function foreignAmountNote(array $raw): ?string {
		return $this->amountNote($this->cell($raw, 'foreign_amount'), $this->cell($raw, 'foreign_currency_code'));
	}

	private function amountNote(string $amount, string $currency): ?string {
		$amount = ltrim($amount, '-');
		if ($amount === '' || $currency === '' || (float)$amount == 0.0) {
			return null;
		}
		return 'Foreign amount: ' . $amount . ' ' . strtoupper($currency);
	}

	/**
	 * The journal's figures in a second currency, when it has them: the
	 * destination's own amount and currency for a transfer across
	 * currencies. Raw file text only, so the import ID derived from the
	 * amount stays the same on every import of the file.
	 *
	 * @return array{amount: float, currency: string}|null
	 */
	private function foreignFigures(array $raw): ?array {
		$amount = ltrim($this->cell($raw, 'foreign_amount'), '-');
		$currency = strtoupper($this->cell($raw, 'foreign_currency_code'));
		if ($amount === '' || !is_numeric($amount) || (float)$amount == 0.0
			|| preg_match('/^[A-Z]{3}$/', $currency) !== 1
			|| $currency === strtoupper($this->cell($raw, 'currency_code'))) {
			return null;
		}
		return ['amount' => abs((float)$amount), 'currency' => $currency];
	}
}
