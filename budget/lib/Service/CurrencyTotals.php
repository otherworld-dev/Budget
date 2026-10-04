<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * Totals over accounts in more than one currency, in the user's base
 * currency, worked out the way the dashboard summary and the Cash Flow
 * report work out theirs: each account's money converted at the user's
 * rate for its currency, and accounts all in one currency left as they are.
 */
class CurrencyTotals {
	public function __construct(
		private CurrencyConversionService $conversionService,
		private ?AccountMapper $accountMapper = null,
	) {
	}

	/**
	 * The currency a report's money comes out in: the selected account's
	 * own; across the accounts in view, the base currency when they hold
	 * more than one (each is converted, as the Cash Flow report converts
	 * them) and otherwise the one they share; the base currency when there
	 * are none. Null when the accounts can't be looked up.
	 *
	 * @param int[]|null $visibleAccountIds null = the user's own accounts
	 */
	public function reportCurrency(string $userId, ?int $accountId, ?array $visibleAccountIds): ?string {
		if ($this->accountMapper === null) {
			return null;
		}
		$baseCurrency = $this->conversionService->getBaseCurrency($userId);
		if ($accountId !== null) {
			try {
				return $this->accountMapper->findById($accountId)->getCurrency() ?: 'USD';
			} catch (DoesNotExistException $e) {
				return $baseCurrency;
			}
		}

		$accounts = $this->accountsInView($userId, $visibleAccountIds);
		if ($accounts === [] || $this->conversionService->needsConversion($accounts)) {
			return $baseCurrency;
		}
		return $accounts[0]->getCurrency() ?: 'USD';
	}

	/**
	 * The accounts an all-accounts aggregate counts: the ones in view, or
	 * the user's own, less those flagged out of reports (#286).
	 *
	 * @param int[]|null $visibleAccountIds
	 * @return Account[]
	 */
	private function accountsInView(string $userId, ?array $visibleAccountIds): array {
		if ($this->accountMapper === null) {
			return [];
		}
		$accounts = !empty($visibleAccountIds)
			? $this->accountMapper->findByIds($visibleAccountIds)
			: $this->accountMapper->findAll($userId);
		return array_values(array_filter($accounts, static fn (Account $a) => !$a->getExcludedFromReports()));
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
