<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync\EnableBanking;

/**
 * Maps Enable Banking API JSON to the provider-neutral format documented in
 * BankSyncProviderInterface. Pure functions, no I/O, so the mapping rules can
 * be tested without mocking HTTP.
 *
 * Amounts stay decimal strings throughout: they are only re-signed with string
 * operations, never parsed into floats.
 */
class EnableBankingNormalizer {
	/**
	 * Booked balances first: Budget's own balance only counts posted
	 * transactions unless pending ones are imported too, so the bank balance we
	 * compare against must include exactly the same set.
	 *
	 * The interim booked balance (ITBD) comes before the closing one (CLBD):
	 * CLBD is usually the previous day's close, while Budget compares against
	 * its balance as of today, so today's bookings would show as a difference.
	 */
	private const BALANCE_TYPES_BOOKED = ['ITBD', 'CLBD', 'XPCD'];
	private const BALANCE_TYPES_WITH_PENDING = ['XPCD', 'ITBD', 'CLBD'];

	/**
	 * Pick the bank balance that matches what Budget imports. Available
	 * balances (`*AV`) include credit lines, so they are only used when the bank
	 * reports nothing else.
	 */
	public function balance(array $balances, bool $includePending): ?string {
		if ($balances === []) {
			return null;
		}

		$byType = [];
		foreach ($balances as $entry) {
			$type = (string)($entry['balance_type'] ?? '');
			$byType[$type] ??= $entry;
		}

		foreach ($includePending ? self::BALANCE_TYPES_WITH_PENDING : self::BALANCE_TYPES_BOOKED as $type) {
			if (isset($byType[$type])) {
				return $this->amountOf($byType[$type]['balance_amount'] ?? []);
			}
		}

		foreach ($balances as $entry) {
			if (!str_ends_with((string)($entry['balance_type'] ?? ''), 'AV')) {
				return $this->amountOf($entry['balance_amount'] ?? []);
			}
		}

		return $this->amountOf($balances[array_key_first($balances)]['balance_amount'] ?? []);
	}

	/**
	 * @return array{id: string, date: string, amount: string, description: string, vendor: ?string, pending: bool}
	 */
	public function transaction(array $tx): array {
		$indicator = $tx['credit_debit_indicator'] ?? null;
		$amount = $this->signedAmount($this->amountOf($tx['transaction_amount'] ?? []), $indicator);
		$date = $this->firstNonEmpty($tx['booking_date'] ?? null, $tx['value_date'] ?? null, $tx['transaction_date'] ?? null) ?? '';

		$vendor = match ($indicator) {
			'DBIT' => $this->firstNonEmpty($tx['creditor']['name'] ?? null),
			'CRDT' => $this->firstNonEmpty($tx['debtor']['name'] ?? null),
			default => null,
		};

		$remittance = $tx['remittance_information'] ?? [];
		$description = is_array($remittance) ? trim(implode(' ', array_map('strval', $remittance))) : '';
		if ($description === '') {
			$description = $vendor
				?? $this->firstNonEmpty($tx['creditor']['name'] ?? null, $tx['debtor']['name'] ?? null)
				?? '';
		}

		// Some banks send neither ID. The hash keeps re-fetched overlap days
		// deduplicated as long as the bank reports the same fields again.
		$id = $this->firstNonEmpty($tx['transaction_id'] ?? null, $tx['entry_reference'] ?? null)
			?? 'h:' . substr(sha1($date . '|' . $amount . '|' . ($indicator ?? '') . '|' . $description), 0, 32);

		return [
			'id' => $id,
			'date' => $date,
			'amount' => $amount,
			'description' => $description,
			'vendor' => $vendor,
			'pending' => ($tx['status'] ?? null) === 'PDNG',
		];
	}

	/**
	 * Display name for an account from `/accounts/{uid}/details` or the
	 * account objects in a session. The IBAN is masked because the name ends
	 * up in the UI and in mapping lists.
	 */
	public function accountName(array $details): string {
		$name = $this->firstNonEmpty($details['name'] ?? null, $details['product'] ?? null);
		if ($name !== null) {
			return $name;
		}

		$iban = $this->firstNonEmpty($details['account_id']['iban'] ?? null);
		if ($iban !== null) {
			$iban = str_replace(' ', '', $iban);
			return substr($iban, 0, 2) . '…' . substr($iban, -4);
		}

		return 'Account';
	}

	private function amountOf(array $money): string {
		return trim((string)($money['amount'] ?? '0'));
	}

	/**
	 * Enable Banking reports unsigned amounts plus a direction indicator.
	 */
	private function signedAmount(string $amount, ?string $indicator): string {
		if ($indicator !== 'DBIT' && $indicator !== 'CRDT') {
			return $amount;
		}

		$absolute = ltrim($amount, '+-');
		$isZero = trim($absolute, '0.') === '';
		return ($indicator === 'DBIT' && !$isZero) ? '-' . $absolute : $absolute;
	}

	private function firstNonEmpty(mixed ...$values): ?string {
		foreach ($values as $value) {
			if (is_scalar($value) && (string)$value !== '') {
				return (string)$value;
			}
		}
		return null;
	}
}
