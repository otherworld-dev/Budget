<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ReportScope;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionReportQueries;
use OCA\Budget\Enum\Currency;
use OCA\Budget\Service\Report\ReportAggregator;

/**
 * Service for year-over-year comparison calculations.
 *
 * Every figure comes from the same report-scoped SQL aggregates as the
 * Reports page (TransactionReportQueries::getCashFlowByMonth() and
 * TransactionMapper::getCategorySpendingBatch()), so a year here agrees with the cash-flow
 * report for the same dates: transfers between accounts are left out of the
 * all-accounts view, pension-funding legs and scheduled future rows never
 * count, and categories kept out of reports (or muted) stay out.
 */
class YearOverYearService {
	private TransactionMapper $transactionMapper;
	private CategoryMapper $categoryMapper;
	private TransactionReportQueries $reportQueries;

	public function __construct(
		TransactionMapper $transactionMapper,
		CategoryMapper $categoryMapper,
		TransactionReportQueries $reportQueries,
		private ?UserClock $userClock = null,
		private ?ReportAggregator $reportAggregator = null,
		private ?CurrencyTotals $currencyTotals = null,
	) {
		$this->transactionMapper = $transactionMapper;
		$this->categoryMapper = $categoryMapper;
		$this->reportQueries = $reportQueries;
	}

	/**
	 * Decimals of the currency this comparison's money is in (see
	 * CurrencyTotals::reportCurrency()): eight for a bitcoin account, two
	 * when the currency can't be told.
	 *
	 * @param int[]|null $visibleAccountIds
	 */
	private function decimals(string $userId, ?int $accountId, ?array $visibleAccountIds): int {
		return Currency::decimalsFor($this->currencyTotals?->reportCurrency($userId, $accountId, $visibleAccountIds));
	}

	/**
	 * Compare the same month across multiple years.
	 *
	 * @param string $userId User ID
	 * @param int $month Month number (1-12)
	 * @param int $years Number of years to compare (default 3)
	 * @param int|null $accountId Optional account filter
	 * @return array Year comparison data
	 */
	public function compareMonth(string $userId, int $month, int $years = 3, ?int $accountId = null, ?array $visibleAccountIds = null): array {
		$currentYear = (int)substr($this->today($userId), 0, 4);
		$decimals = $this->decimals($userId, $accountId, $visibleAccountIds);
		$results = [];

		for ($i = 0; $i < $years; $i++) {
			$year = $currentYear - $i;
			$startDate = sprintf('%04d-%02d-01', $year, $month);
			$endDate = date('Y-m-t', strtotime($startDate));

			$monthData = $this->getMonthSummary($userId, $startDate, $endDate, $accountId, $visibleAccountIds, $decimals);
			$monthData['year'] = $year;
			$monthData['month'] = $month;
			$monthData['monthName'] = date('F', strtotime($startDate));

			$results[] = $monthData;
		}

		// Calculate changes from previous year
		for ($i = 0; $i < count($results) - 1; $i++) {
			$current = &$results[$i];
			$previous = $results[$i + 1];

			$current['incomeChange'] = $this->calculatePercentChange($current['income'], $previous['income']);
			$current['expenseChange'] = $this->calculatePercentChange($current['expenses'], $previous['expenses']);
			$current['savingsChange'] = $current['savings'] - $previous['savings'];
		}

		return [
			'type' => 'month',
			'month' => $month,
			'monthName' => date('F', mktime(0, 0, 0, $month, 1)),
			'years' => $results,
		];
	}

	/**
	 * Compare full years.
	 *
	 * @param string $userId User ID
	 * @param int $years Number of years to compare
	 * @param int|null $accountId Optional account filter
	 * @return array Year comparison data
	 */
	public function compareYears(string $userId, int $years = 3, ?int $accountId = null, ?array $visibleAccountIds = null): array {
		$today = $this->today($userId);
		$currentYear = (int)substr($today, 0, 4);
		$decimals = $this->decimals($userId, $accountId, $visibleAccountIds);
		$results = [];

		for ($i = 0; $i < $years; $i++) {
			$year = $currentYear - $i;
			$startDate = sprintf('%04d-01-01', $year);
			$endDate = sprintf('%04d-12-31', $year);

			// For current year, only include up to current date
			if ($year === $currentYear) {
				$endDate = $today;
			}

			$yearData = $this->getYearSummary($userId, $year, $startDate, $endDate, $accountId, $visibleAccountIds, $decimals);
			$yearData['year'] = $year;
			$yearData['isCurrent'] = ($year === $currentYear);

			$results[] = $yearData;
		}

		// Calculate changes from previous year
		for ($i = 0; $i < count($results) - 1; $i++) {
			$current = &$results[$i];
			$previous = $results[$i + 1];

			$current['incomeChange'] = $this->calculatePercentChange($current['income'], $previous['income']);
			$current['expenseChange'] = $this->calculatePercentChange($current['expenses'], $previous['expenses']);
			$current['savingsChange'] = $current['savings'] - $previous['savings'];
		}

		return [
			'type' => 'year',
			'years' => $results,
		];
	}

	/**
	 * The date range for one comparison year, ending today for the year in
	 * progress so a part-year is not compared against a whole one.
	 *
	 * @return array{start: string, end: string}
	 */
	private function yearRange(int $year, int $currentYear, string $today): array {
		return [
			'start' => sprintf('%04d-01-01', $year),
			'end' => $year === $currentYear ? $today : sprintf('%04d-12-31', $year),
		];
	}

	/**
	 * Today (Y-m-d) on the user's calendar, not the server's: on New Year's
	 * morning in Sydney the server is still in last year.
	 */
	private function today(string $userId): string {
		return $this->userClock?->today($userId) ?? date('Y-m-d');
	}

	/**
	 * Compare spending by category across years.
	 *
	 * @param string $userId User ID
	 * @param int $years Number of years to compare
	 * @param int|null $accountId Optional account filter
	 * @return array Category comparison data
	 */
	public function compareCategorySpending(string $userId, int $years = 2, ?int $accountId = null, ?array $visibleAccountIds = null): array {
		$today = $this->today($userId);
		$currentYear = (int)substr($today, 0, 4);
		$expenseCategories = array_values(array_filter(
			$this->categoryMapper->findAll($userId),
			static fn ($category) => $category->getType() === 'expense'
		));
		$categoryIds = array_map(static fn ($category) => $category->getId(), $expenseCategories);
		$categoryData = [];

		// One batch per year for every category at once — direct spending and
		// split allocations together (#360), net of refunds like the budget
		// surfaces (#361). Replaces a query per category per year.
		$spendingByYear = [];
		for ($i = 0; $i < $years; $i++) {
			$year = $currentYear - $i;
			$range = $this->yearRange($year, $currentYear, $today);
			$query = fn (?array $accountIds): array => $this->transactionMapper->getCategorySpendingBatch(
				$categoryIds,
				$range['start'],
				$range['end'],
				'debit',
				$accountId,
				// All accounts: a transfer is money moved, not spent (#349)
				$accountId === null,
				$userId,
				$accountIds,
				// Categories kept out of reports stay out of this one (#219)
				true
			);
			// Across accounts in more than one currency, in the base currency,
			// as the yearly figures above are
			$spendingByYear[$year] = match (true) {
				$categoryIds === [] => [],
				$accountId === null && $this->currencyTotals !== null => $this->currencyTotals->amountsInBase($userId, $visibleAccountIds, $query),
				default => $query($visibleAccountIds),
			};
		}

		$decimals = $this->decimals($userId, $accountId, $visibleAccountIds);
		foreach ($expenseCategories as $category) {
			$categoryYears = [];
			$anySpending = false;
			for ($i = 0; $i < $years; $i++) {
				$year = $currentYear - $i;
				$spending = round((float)($spendingByYear[$year][$category->getId()] ?? 0), $decimals);
				$anySpending = $anySpending || $spending != 0.0;

				$categoryYears[] = [
					'year' => $year,
					'spending' => $spending,
				];
			}

			// Nothing to compare in any year — which is also how a category
			// kept out of reports comes back — so no row.
			if (!$anySpending) {
				continue;
			}

			// Calculate change
			$change = null;
			if (count($categoryYears) >= 2 && $categoryYears[1]['spending'] > 0) {
				$change = $this->calculatePercentChange(
					$categoryYears[0]['spending'],
					$categoryYears[1]['spending']
				);
			}

			$categoryData[] = [
				'id' => $category->getId(),
				'name' => $category->getName(),
				'years' => $categoryYears,
				'change' => $change,
			];
		}

		// Sort by current year spending (descending)
		usort($categoryData, function ($a, $b) {
			$aSpending = $a['years'][0]['spending'] ?? 0;
			$bSpending = $b['years'][0]['spending'] ?? 0;
			return $bSpending <=> $aSpending;
		});

		return [
			'type' => 'category',
			'categories' => $categoryData,
		];
	}

	/**
	 * Get monthly breakdown for year comparison.
	 *
	 * @param string $userId User ID
	 * @param int $years Number of years to compare
	 * @param int|null $accountId Optional account filter
	 * @return array Monthly data for each year
	 */
	public function getMonthlyTrends(string $userId, int $years = 2, ?int $accountId = null, ?array $visibleAccountIds = null): array {
		$today = $this->today($userId);
		$currentYear = (int)substr($today, 0, 4);
		$currentMonth = (int)substr($today, 5, 2);
		$decimals = $this->decimals($userId, $accountId, $visibleAccountIds);
		$result = [];

		for ($i = 0; $i < $years; $i++) {
			$year = $currentYear - $i;
			$yearData = [
				'year' => $year,
				'months' => [],
			];

			$maxMonth = ($year === $currentYear) ? $currentMonth : 12;

			// The whole year in one aggregate rather than one per month
			$lastDay = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $year, $maxMonth)));
			$byMonth = $this->cashFlowByMonth($userId, sprintf('%04d-01-01', $year), $lastDay, $accountId, $visibleAccountIds);

			for ($month = 1; $month <= $maxMonth; $month++) {
				$monthSummary = $this->summarise(
					isset($byMonth[sprintf('%04d-%02d', $year, $month)]) ? [$byMonth[sprintf('%04d-%02d', $year, $month)]] : [],
					$decimals
				);
				$monthSummary['month'] = $month;
				$monthSummary['monthName'] = date('M', mktime(0, 0, 0, $month, 1));

				$yearData['months'][] = $monthSummary;
			}

			// Totals from the months as the report gave them, not from the
			// rounded month figures, so a year matches Cash Flow for it
			$yearTotals = $this->summarise(array_values($byMonth), $decimals);
			$yearData['totalIncome'] = $yearTotals['income'];
			$yearData['totalExpenses'] = $yearTotals['expenses'];
			$yearData['totalSavings'] = $yearTotals['savings'];
			$yearData['avgMonthlyIncome'] = $maxMonth > 0 ? round($yearData['totalIncome'] / $maxMonth, $decimals) : 0;
			$yearData['avgMonthlyExpenses'] = $maxMonth > 0 ? round($yearData['totalExpenses'] / $maxMonth, $decimals) : 0;

			$result[] = $yearData;
		}

		return [
			'type' => 'monthly_trends',
			'years' => $result,
		];
	}

	/**
	 * Report-scoped income and expenses per month over a range, keyed by
	 * 'YYYY-MM'. The all-accounts view leaves transfers out (#349); a single
	 * account keeps its own legs, as the cash-flow report does.
	 *
	 * The all-accounts view is the Cash Flow report's own figures, converted
	 * to the base currency when accounts are in more than one: summing the
	 * raw amounts added euros to pounds, so a year here never matched Cash
	 * Flow for a multi-currency user. A single account is in one currency.
	 *
	 * @param int[]|null $visibleAccountIds
	 * @return array<string, array{month: string, income: float, expenses: float, net: float, count: int}>
	 */
	private function cashFlowByMonth(string $userId, string $startDate, string $endDate, ?int $accountId, ?array $visibleAccountIds): array {
		$rows = $accountId === null && $this->reportAggregator !== null
			? $this->reportAggregator->getCashFlowReport($userId, null, $startDate, $endDate, [], true, $visibleAccountIds)['data']
			: $this->reportQueries->getCashFlowByMonth(
				$userId, $accountId, $startDate, $endDate, [], true, $accountId === null, $visibleAccountIds
			);
		$byMonth = [];
		foreach ($rows as $row) {
			$byMonth[$row['month']] = $row;
		}
		return $byMonth;
	}

	/**
	 * Totals of some months of cash flow, money added through MoneyCalculator
	 * (#274) at the report's full scale and rounded once, to $decimals. Summed
	 * at two places, bcmath truncated: converted figures (which carry eight)
	 * came out a penny below Cash Flow and the dashboard, and a bitcoin
	 * account's year read 0.
	 *
	 * @param array<array{income: float, expenses: float, count?: int, ...}> $months
	 * @param int $decimals the decimals of the currency the money is in
	 * @return array{income: float, expenses: float, savings: float, transactionCount: int}
	 */
	private function summarise(array $months, int $decimals = 2): array {
		$income = MoneyCalculator::sum(array_column($months, 'income'), ReportScope::MERGE_SCALE);
		$expenses = MoneyCalculator::sum(array_column($months, 'expenses'), ReportScope::MERGE_SCALE);

		return [
			'income' => round(MoneyCalculator::toFloat($income), $decimals),
			'expenses' => round(MoneyCalculator::toFloat($expenses), $decimals),
			'savings' => round(MoneyCalculator::toFloat(MoneyCalculator::subtract($income, $expenses, ReportScope::MERGE_SCALE)), $decimals),
			'transactionCount' => (int)array_sum(array_column($months, 'count')),
		];
	}

	/**
	 * Get month summary data.
	 */
	private function getMonthSummary(string $userId, string $startDate, string $endDate, ?int $accountId = null, ?array $visibleAccountIds = null, int $decimals = 2): array {
		return $this->summarise(array_values(
			$this->cashFlowByMonth($userId, $startDate, $endDate, $accountId, $visibleAccountIds)
		), $decimals);
	}

	/**
	 * Get year summary data with monthly breakdowns.
	 */
	private function getYearSummary(string $userId, int $year, string $startDate, string $endDate, ?int $accountId = null, ?array $visibleAccountIds = null, int $decimals = 2): array {
		$months = array_values($this->cashFlowByMonth($userId, $startDate, $endDate, $accountId, $visibleAccountIds));
		$summary = $this->summarise($months, $decimals);

		// Months with any activity, as before: an empty month is not averaged in
		$monthCount = count(array_filter($months, static fn (array $m) => ($m['count'] ?? 0) > 0));

		return $summary + [
			'avgMonthlyIncome' => $monthCount > 0 ? round($summary['income'] / $monthCount, $decimals) : 0,
			'avgMonthlyExpenses' => $monthCount > 0 ? round($summary['expenses'] / $monthCount, $decimals) : 0,
			'monthsWithData' => $monthCount,
		];
	}

	/**
	 * Calculate percent change.
	 */
	private function calculatePercentChange(float $current, float $previous): ?float {
		if ($previous == 0) {
			return $current > 0 ? 100.0 : ($current < 0 ? -100.0 : null);
		}
		return round((($current - $previous) / $previous) * 100, 1);
	}
}
