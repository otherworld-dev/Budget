<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;

/**
 * Totals over accounts in more than one currency, in the user's base
 * currency, worked out the way the dashboard summary and the Cash Flow
 * report work out theirs: each account's money converted at the user's
 * rate for its currency, and accounts all in one currency left as they are.
 */
class CurrencyTotals {
	public function __construct(
		private CurrencyConversionService $conversionService,
	) {
	}

	/**
	 * What one unit of each account's money is worth in the total's
	 * currency, as account id => multiplier.
	 *
	 * Accounts in more than one currency are converted to the user's base
	 * currency, an account already in it at '1'. An account whose currency
	 * has no rate gets no entry, so the caller leaves its money out of the
	 * total, as the dashboard summary does, rather than add it as if it were
	 * the base currency; its currency is listed in 'unconverted'. Accounts
	 * all in one currency need no conversion and each get '1'.
	 *
	 * 'currency' is the base currency the rates convert into, or null when
	 * the accounts share one currency and nothing is converted.
	 *
	 * @param Account[] $accounts
	 * @return array{rates: array<int, string>, currency: ?string, unconverted: string[]}
	 */
	public function accountRates(array $accounts, string $userId): array {
		if (!$this->conversionService->needsConversion($accounts)) {
			$rates = [];
			foreach ($accounts as $account) {
				$rates[(int)$account->getId()] = '1';
			}
			return ['rates' => $rates, 'currency' => null, 'unconverted' => []];
		}

		$baseCurrency = strtoupper($this->conversionService->getBaseCurrency($userId));
		$byCurrency = [];
		$rates = [];
		$unconverted = [];
		foreach ($accounts as $account) {
			$currency = strtoupper($account->getCurrency() ?: 'USD');
			if (!array_key_exists($currency, $byCurrency)) {
				$byCurrency[$currency] = match (true) {
					$currency === $baseCurrency => '1',
					!$this->conversionService->canConvert($currency, $userId) => null,
					default => $this->conversionService->convertToBase('1', $currency, $userId),
				};
			}
			if ($byCurrency[$currency] === null) {
				$unconverted[$currency] = true;
				continue;
			}
			$rates[(int)$account->getId()] = $byCurrency[$currency];
		}

		return ['rates' => $rates, 'currency' => $baseCurrency, 'unconverted' => array_keys($unconverted)];
	}
}
