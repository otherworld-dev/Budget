<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\ReportScope;
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
	 * The accounts in view grouped by currency when they hold more than one;
	 * null when they share one (or there are none) and nothing needs
	 * converting.
	 *
	 * @param int[]|null $visibleAccountIds null = the user's own accounts
	 * @return array<string, int[]>|null currency => account ids
	 */
	public function currencyGroups(string $userId, ?array $visibleAccountIds): ?array {
		$accounts = $this->accountsInView($userId, $visibleAccountIds);
		if (!$this->conversionService->needsConversion($accounts)) {
			return null;
		}
		$groups = [];
		foreach ($accounts as $account) {
			$groups[strtoupper($account->getCurrency() ?: 'USD')][] = (int)$account->getId();
		}
		return $groups;
	}

	/**
	 * An aggregate's amounts over the accounts in view, in the base currency
	 * when they hold more than one, the way the Cash Flow report converts
	 * its figures: $query runs once per currency on that currency's
	 * accounts, its amounts are converted at the user's rate and added up
	 * key by key. $query returns amounts keyed by anything (a category id,
	 * then a month for a nested map); every leaf is money. Accounts in one
	 * currency run $query once on the scope as given, unchanged.
	 *
	 * A currency with no rate is added as it is, as Cash Flow adds it.
	 *
	 * @param int[]|null $visibleAccountIds
	 * @param callable(int[]|null): array $query
	 */
	public function amountsInBase(string $userId, ?array $visibleAccountIds, callable $query): array {
		$groups = $this->currencyGroups($userId, $visibleAccountIds);
		if ($groups === null) {
			return $query($visibleAccountIds);
		}

		$sum = [];
		foreach ($groups as $currency => $accountIds) {
			$this->addConverted($sum, $query($accountIds), $this->rateToBase($currency, $userId));
		}
		return self::toFloats($sum);
	}

	/**
	 * A report query's rows over the accounts in view, with $moneyColumns in
	 * the base currency when the accounts hold more than one: as
	 * amountsInBase(), the rows of each currency's run converted and those
	 * sharing $keyColumns added together, counts included. With
	 * $keyColumns null no two rows are the same (each belongs to one
	 * account), so rows are only converted. Row order is first seen; the
	 * caller sorts.
	 *
	 * @param int[]|null $visibleAccountIds
	 * @param callable(int[]|null): array[] $query
	 * @param string[]|null $keyColumns
	 * @param string[] $moneyColumns
	 * @param string[] $countColumns
	 * @return array[]
	 */
	public function rowsInBase(string $userId, ?array $visibleAccountIds, callable $query, ?array $keyColumns, array $moneyColumns, array $countColumns = []): array {
		$groups = $this->currencyGroups($userId, $visibleAccountIds);
		if ($groups === null) {
			return $query($visibleAccountIds);
		}

		$merged = [];
		foreach ($groups as $currency => $accountIds) {
			$rate = $this->rateToBase($currency, $userId);
			foreach ($query($accountIds) as $row) {
				$key = $keyColumns === null
					? count($merged)
					: implode("\x1f", array_map(static fn (string $column) => (string)($row[$column] ?? ''), $keyColumns));
				if (!isset($merged[$key])) {
					$merged[$key] = $row;
					foreach ($moneyColumns as $column) {
						$merged[$key][$column] = '0';
					}
					foreach ($countColumns as $column) {
						$merged[$key][$column] = 0;
					}
				}
				foreach ($moneyColumns as $column) {
					$merged[$key][$column] = MoneyCalculator::add(
						$merged[$key][$column],
						MoneyCalculator::multiply(ReportScope::sqlMoney($row[$column] ?? null), $rate, 10),
						ReportScope::MERGE_SCALE
					);
				}
				foreach ($countColumns as $column) {
					$merged[$key][$column] += (int)($row[$column] ?? 0);
				}
			}
		}

		foreach ($merged as &$row) {
			foreach ($moneyColumns as $column) {
				$row[$column] = MoneyCalculator::toFloat($row[$column]);
			}
		}
		unset($row);
		return array_values($merged);
	}

	/**
	 * What one unit of $currency is worth in the user's base currency, at
	 * their rate; '1' when there is no rate, which leaves the money as it
	 * is, as Cash Flow leaves it.
	 */
	private function rateToBase(string $currency, string $userId): string {
		return $this->conversionService->convertToBase('1', $currency, $userId);
	}

	/**
	 * Add $amounts times $rate into $sum, leaf by leaf, as decimal strings.
	 *
	 * @param array<array-key, mixed> $sum
	 * @param array<array-key, mixed> $amounts
	 */
	private function addConverted(array &$sum, array $amounts, string $rate): void {
		foreach ($amounts as $key => $amount) {
			if (is_array($amount)) {
				if (!isset($sum[$key]) || !is_array($sum[$key])) {
					$sum[$key] = [];
				}
				$this->addConverted($sum[$key], $amount, $rate);
				continue;
			}
			$sum[$key] = MoneyCalculator::add(
				(string)($sum[$key] ?? '0'),
				MoneyCalculator::multiply(ReportScope::sqlMoney($amount), $rate, 10),
				ReportScope::MERGE_SCALE
			);
		}
	}

	/**
	 * @param array<array-key, mixed> $sum
	 * @return array<array-key, mixed>
	 */
	private static function toFloats(array $sum): array {
		foreach ($sum as $key => $value) {
			$sum[$key] = is_array($value) ? self::toFloats($value) : MoneyCalculator::toFloat((string)$value);
		}
		return $sum;
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
