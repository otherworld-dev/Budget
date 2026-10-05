<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Report;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionReportQueries;
use OCA\Budget\Service\CurrencyTotals;
use OCA\Budget\Service\MoneyCalculator;

/**
 * Handles calculation of spending and income metrics.
 *
 * Across accounts in more than one currency every grouping is in the base
 * currency, converted the way the Cash Flow report converts its figures:
 * summed as stored, euros were added to pounds, and the Income & Expenses
 * report's net drifted from Cash Flow's for the same period. A selected
 * account is in one currency and is left as it is.
 */
class ReportCalculator {
	/** How many vendors or payers a grouping lists */
	private const TOP_ROWS = 15;

	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private TransactionReportQueries $reportQueries;

	public function __construct(
		AccountMapper $accountMapper,
		TransactionMapper $transactionMapper,
		TransactionReportQueries $reportQueries,
		private ?CurrencyTotals $currencyTotals = null,
	) {
		$this->accountMapper = $accountMapper;
		$this->transactionMapper = $transactionMapper;
		$this->reportQueries = $reportQueries;
	}

	/**
	 * A grouping's rows, in the base currency across accounts in more than
	 * one (see the class comment), sorted with $sort.
	 *
	 * @param int[]|null $visibleAccountIds
	 * @param callable(int[]|null): array[] $query the grouping over the given accounts
	 * @param string[]|null $keyColumns what makes two currencies' rows the same row; null when no two are
	 * @param string[] $moneyColumns
	 * @param callable(array, array): int $sort
	 * @return array[]
	 */
	private function inBase(string $userId, ?int $accountId, ?array $visibleAccountIds, callable $query, ?array $keyColumns, callable $sort, array $moneyColumns = ['total']): array {
		if ($accountId !== null || $this->currencyTotals === null) {
			return $query($visibleAccountIds);
		}
		$rows = $this->currencyTotals->rowsInBase($userId, $visibleAccountIds, $query, $keyColumns, $moneyColumns, ['count']);
		usort($rows, $sort);
		return $rows;
	}

	private static function byTotalDescending(array $a, array $b): int {
		return (float)$b['total'] <=> (float)$a['total'];
	}

	private static function byMonth(array $a, array $b): int {
		return strcmp((string)$a['month'], (string)$b['month']);
	}

	/**
	 * The largest vendors or payers of a grouping. Across currencies every
	 * row of each currency is converted before the largest are picked, so a
	 * vendor paid in two currencies is ranked on its whole.
	 *
	 * @param int[]|null $visibleAccountIds
	 * @param callable(int, int[]|null): array[] $query the grouping cut to the given number of rows
	 * @return array[]
	 */
	private function topRowsInBase(string $userId, ?int $accountId, ?array $visibleAccountIds, callable $query): array {
		if ($accountId !== null || $this->currencyTotals === null) {
			return $query(self::TOP_ROWS, $visibleAccountIds);
		}
		$rows = $this->currencyTotals->rowsInBase(
			$userId,
			$visibleAccountIds,
			static fn (?array $accountIds): array => $query(PHP_INT_MAX, $accountIds),
			['name', 'unknown'],
			['total'],
			['count']
		);
		usort($rows, self::byTotalDescending(...));
		return array_slice($rows, 0, self::TOP_ROWS);
	}

	/**
	 * Get spending grouped by category.
	 *
	 * The all-accounts view leaves linked transfers out, as the report's
	 * month, vendor, account and tag groupings do (#349): a transfer filed
	 * under a category is still money that never left the household, and
	 * counting it here made the category view of a period larger than every
	 * other view of the same period. Money with no category is one more row
	 * flagged 'uncategorized', so the rows add up to the period's total.
	 */
	public function getSpendingByCategory(
		string $userId,
		?int $accountId,
		string $startDate,
		string $endDate,
		?array $visibleAccountIds = null,
	): array {
		return $this->inBase(
			$userId, $accountId, $visibleAccountIds,
			fn (?array $accountIds): array => $this->transactionMapper->getSpendingSummary(
				$userId, $startDate, $endDate, $accountId,
				excludeTransfers: $accountId === null,
				visibleAccountIds: $accountIds,
				includeUncategorized: true
			),
			['id'],
			self::byTotalDescending(...)
		);
	}

	/**
	 * Get spending grouped by month.
	 */
	public function getSpendingByMonth(
		string $userId,
		?int $accountId,
		string $startDate,
		string $endDate,
		?array $visibleAccountIds = null,
	): array {
		return $this->inBase(
			$userId, $accountId, $visibleAccountIds,
			fn (?array $accountIds): array => array_map(fn ($row) => [
				'name' => $this->formatMonthLabel($row['month']),
				'month' => $row['month'],
				'total' => (float)$row['total'],
				'count' => (int)$row['count']
			], $this->reportQueries->getSpendingByMonth($userId, $accountId, $startDate, $endDate, $accountIds)),
			['month'],
			self::byMonth(...)
		);
	}

	/**
	 * Get spending grouped by vendor.
	 */
	public function getSpendingByVendor(
		string $userId,
		?int $accountId,
		string $startDate,
		string $endDate,
		?array $visibleAccountIds = null,
	): array {
		return $this->topRowsInBase(
			$userId, $accountId, $visibleAccountIds,
			fn (int $limit, ?array $accountIds): array => $this->reportQueries->getSpendingByVendor($userId, $accountId, $startDate, $endDate, $limit, $accountIds)
		);
	}

	/**
	 * Get spending grouped by account.
	 * OPTIMIZED: Uses single aggregated SQL query instead of N+1 pattern.
	 */
	public function getSpendingByAccount(
		string $userId,
		string $startDate,
		string $endDate,
		?array $visibleAccountIds = null,
		?int $accountId = null,
	): array {
		// Each account is in one currency, so no two rows are the same row
		return $this->inBase(
			$userId, $accountId, $visibleAccountIds,
			fn (?array $accountIds): array => $this->reportQueries->getSpendingByAccountAggregated($userId, $startDate, $endDate, $accountIds, $accountId),
			null,
			self::byTotalDescending(...),
			['total', 'average']
		);
	}

	/**
	 * Get income grouped by category: credits per income category, split
	 * parts included, report-scoped like spending by category.
	 *
	 * This used to answer with income by source (the 15 largest payers) as a
	 * stand-in, so the Income & Expenses report listed payers under a
	 * "category" heading and its income total left out every payer below the
	 * top fifteen. Income with no category (salary not filed yet) is one
	 * more row flagged 'uncategorized', so the total still counts it.
	 */
	public function getIncomeByCategory(
		string $userId,
		?int $accountId,
		string $startDate,
		string $endDate,
		?array $visibleAccountIds = null,
	): array {
		return $this->inBase(
			$userId, $accountId, $visibleAccountIds,
			fn (?array $accountIds): array => $this->transactionMapper->getSpendingSummary(
				$userId, $startDate, $endDate, $accountId,
				excludeTransfers: $accountId === null,
				visibleAccountIds: $accountIds,
				transactionType: 'credit',
				includeUncategorized: true
			),
			['id'],
			self::byTotalDescending(...)
		);
	}

	/**
	 * Get income grouped by month.
	 */
	public function getIncomeByMonth(
		string $userId,
		?int $accountId,
		string $startDate,
		string $endDate,
		?array $visibleAccountIds = null,
	): array {
		return $this->inBase(
			$userId, $accountId, $visibleAccountIds,
			fn (?array $accountIds): array => array_map(fn ($row) => [
				'name' => $this->formatMonthLabel($row['month']),
				'month' => $row['month'],
				'total' => (float)$row['total'],
				'count' => (int)$row['count']
			], $this->reportQueries->getIncomeByMonth($userId, $accountId, $startDate, $endDate, $accountIds)),
			['month'],
			self::byMonth(...)
		);
	}

	/**
	 * Get income grouped by source/vendor.
	 */
	public function getIncomeBySource(
		string $userId,
		?int $accountId,
		string $startDate,
		string $endDate,
		?array $visibleAccountIds = null,
	): array {
		return $this->topRowsInBase(
			$userId, $accountId, $visibleAccountIds,
			fn (int $limit, ?array $accountIds): array => $this->reportQueries->getIncomeBySource($userId, $accountId, $startDate, $endDate, $limit, $accountIds)
		);
	}

	/**
	 * Calculate percentage change between two values.
	 */
	public function calculatePercentChange(float $previous, float $current): array {
		if ($previous == 0) {
			return [
				'percentage' => $current > 0 ? 100 : 0,
				'direction' => $current > 0 ? 'up' : ($current < 0 ? 'down' : 'none'),
				'absolute' => $current - $previous
			];
		}

		$change = (($current - $previous) / abs($previous)) * 100;
		return [
			'percentage' => round(abs($change), 1),
			'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'none'),
			'absolute' => $current - $previous
		];
	}

	/**
	 * Format a YYYY-MM string as a human-readable month label.
	 */
	public function formatMonthLabel(string $yearMonth): string {
		$date = \DateTime::createFromFormat('Y-m-d', $yearMonth . '-01');
		return $date ? $date->format('M Y') : $yearMonth;
	}

	/**
	 * Get budget status based on spending percentage.
	 */
	public function getBudgetStatus(float $percentage): string {
		if ($percentage <= 50) {
			return 'good';
		} elseif ($percentage <= 80) {
			return 'warning';
		} elseif ($percentage <= 100) {
			return 'danger';
		} else {
			return 'over';
		}
	}

	/**
	 * Calculate totals from report data items.
	 */
	public function calculateTotals(array $data): array {
		$transactions = 0;
		foreach ($data as $item) {
			$transactions += (int)$item['count'];
		}

		return [
			// Through MoneyCalculator, never float += (#274); scale 8 keeps a
			// crypto amount whole
			'amount' => MoneyCalculator::toFloat(MoneyCalculator::sum(
				array_map(static fn (array $item) => (float)$item['total'], $data),
				8
			)),
			'transactions' => $transactions
		];
	}

	/**
	 * Get spending grouped by tags within a specific tag set.
	 */
	public function getSpendingByTag(
		string $userId,
		int $tagSetId,
		string $startDate,
		string $endDate,
		?int $accountId = null,
		?int $categoryId = null,
		?array $visibleAccountIds = null,
	): array {
		return $this->inBase(
			$userId, $accountId, $visibleAccountIds,
			fn (?array $accountIds): array => $this->reportQueries->getSpendingByTag(
				$userId,
				$tagSetId,
				$startDate,
				$endDate,
				$accountId,
				$categoryId,
				$accountIds
			),
			['tagId'],
			self::byTotalDescending(...)
		);
	}

	/**
	 * Get income grouped by tags within a specific tag set.
	 */
	public function getIncomeByTag(
		string $userId,
		int $tagSetId,
		string $startDate,
		string $endDate,
		?int $accountId = null,
		?int $categoryId = null,
		?array $visibleAccountIds = null,
	): array {
		return $this->inBase(
			$userId, $accountId, $visibleAccountIds,
			fn (?array $accountIds): array => $this->reportQueries->getIncomeByTag(
				$userId,
				$tagSetId,
				$startDate,
				$endDate,
				$accountId,
				$categoryId,
				$accountIds
			),
			['tagId'],
			self::byTotalDescending(...)
		);
	}
}
