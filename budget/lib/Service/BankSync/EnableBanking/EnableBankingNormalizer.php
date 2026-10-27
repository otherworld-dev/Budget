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
	 * The interim booked balance (ITBD) comes before the closing one (CLBD),
	 * as it is the more recent of the two. Budget compares either as of its
	 * reference date (CLBD is usually the previous day's close).
	 */
	private const BALANCE_TYPES_BOOKED = ['ITBD', 'CLBD', 'XPCD'];
	private const BALANCE_TYPES_WITH_PENDING = ['XPCD', 'ITBD', 'CLBD'];

	/**
	 * Pick the bank balance that matches what Budget imports. Available
	 * balances (`*AV`) include credit lines, so they are only used when the bank
	 * reports nothing else.
	 */
	public function balance(array $balances, bool $includePending): ?string {
		return $this->balanceWithDate($balances, $includePending)['amount'];
	}

	/**
	 * The balance balance() picks, with the day it is reported for
	 * (`reference_date`, `Y-m-d`), or null when the bank gives no usable date.
	 *
	 * @return array{amount: ?string, date: ?string}
	 */
	public function balanceWithDate(array $balances, bool $includePending): array {
		$entry = $this->chooseBalance($balances, $includePending);
		if ($entry === null) {
			return ['amount' => null, 'date' => null];
		}
		return [
			'amount' => $this->amountOf(is_array($entry['balance_amount'] ?? null) ? $entry['balance_amount'] : []),
			'date' => $this->dayOf($entry['reference_date'] ?? null),
		];
	}

	private function chooseBalance(array $balances, bool $includePending): ?array {
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
				return $byType[$type];
			}
		}

		foreach ($balances as $entry) {
			if (!str_ends_with((string)($entry['balance_type'] ?? ''), 'AV')) {
				return $entry;
			}
		}

		return $balances[array_key_first($balances)];
	}

	/** `Y-m-d` from a date or date-time string, null for anything else. */
	private function dayOf(mixed $value): ?string {
		if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $value, $m) !== 1
			|| !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
			return null;
		}
		return $m[0];
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
	 * account objects in a session. Product/details/name come first (the name is
	 * often only the holder), followed by the last four IBAN characters so
	 * accounts of one holder stay distinguishable without exposing the IBAN.
	 */
	public function accountName(array $details): string {
		$label = $this->firstNonEmpty($details['product'] ?? null, $details['details'] ?? null, $details['name'] ?? null) ?? 'Account';

		$iban = $this->firstNonEmpty($details['account_id']['iban'] ?? null);
		if ($iban !== null) {
			$label .= ' (…' . substr(str_replace(' ', '', $iban), -4) . ')';
		}

		return $label;
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
