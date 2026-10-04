<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Enum\Frequency;
use OCA\Budget\Service\Forecast\ForecastProjector;
use OCA\Budget\Service\Forecast\PatternAnalyzer;
use OCA\Budget\Service\Forecast\ScenarioBuilder;
use OCA\Budget\Service\Forecast\TrendCalculator;
use OCP\ICache;
use OCP\ICacheFactory;

/**
 * Orchestrates forecast generation by delegating to specialized services.
 * OPTIMIZED: Includes caching layer for expensive calculations.
 */
class ForecastService {
	private const CACHE_PREFIX = 'budget_forecast_';
	private const CACHE_TTL = 300; // 5 minutes

	/**
	 * Complete months of history a trend needs before the live forecast
	 * extrapolates it, the same number the forecast calls reliable. A line
	 * through two points is noise: one good month and one weak one took
	 * projected income to zero.
	 */
	private const MIN_TREND_MONTHS = 3;

	private AccountMapper $accountMapper;
	private TransactionMapper $transactionMapper;
	private PatternAnalyzer $patternAnalyzer;
	private TrendCalculator $trendCalculator;
	private ScenarioBuilder $scenarioBuilder;
	private ForecastProjector $projector;
	private ?ICache $cache = null;

	public function __construct(
		AccountMapper $accountMapper,
		TransactionMapper $transactionMapper,
		PatternAnalyzer $patternAnalyzer,
		TrendCalculator $trendCalculator,
		ScenarioBuilder $scenarioBuilder,
		ForecastProjector $projector,
		?ICacheFactory $cacheFactory = null,
		private ?UserClock $userClock = null,
		private ?CurrencyTotals $currencyTotals = null,
		private ?RecurringIncomeMapper $incomeMapper = null,
	) {
		$this->accountMapper = $accountMapper;
		$this->transactionMapper = $transactionMapper;
		$this->patternAnalyzer = $patternAnalyzer;
		$this->trendCalculator = $trendCalculator;
		$this->scenarioBuilder = $scenarioBuilder;
		$this->projector = $projector;

		// Initialize cache if available
		if ($cacheFactory !== null) {
			$this->cache = $cacheFactory->createDistributed(self::CACHE_PREFIX);
		}
	}

	/**
	 * Today in the user's own calendar. A purchase dated their today is
	 * stored cleared; judged against the server's UTC date, the "balance as
	 * of today" took it off again until UTC midnight caught up (#399).
	 */
	private function today(string $userId): string {
		return $this->userClock?->today($userId) ?? date('Y-m-d');
	}

	/**
	 * Net of each account's rows dated after $today, for the accounts being
	 * forecast. By account rather than by the viewer's own accounts: a
	 * shared account's future-dated rows were otherwise left in its balance
	 * for the person it is shared with.
	 *
	 * @return array<int, float> account id => net change after today
	 */
	private function futureChanges(array $accounts, string $today): array {
		return $this->transactionMapper->getNetChangeAfterDateForAccounts(
			array_map(static fn ($a) => (int)$a->getId(), $accounts),
			$today
		);
	}

	/**
	 * The user's active recurring income into the accounts forecast, as a
	 * monthly amount in the forecast's currency. One-off income is not
	 * recurring and a custom pattern has no monthly figure, so neither counts.
	 *
	 * @param array<int, string>|null $rates account id => multiplier, when converting
	 */
	private function recurringMonthlyIncome(string $userId, array $accounts, ?array $rates): float {
		if ($this->incomeMapper === null) {
			return 0.0;
		}
		$inForecast = [];
		foreach ($accounts as $account) {
			if ($rates === null || isset($rates[$account->getId()])) {
				$inForecast[(int)$account->getId()] = true;
			}
		}

		$total = 0.0;
		foreach ($this->incomeMapper->findActive($userId) as $income) {
			$accountId = (int)($income->getAccountId() ?? 0);
			$frequency = Frequency::tryFrom((string)$income->getFrequency());
			if (!isset($inForecast[$accountId]) || $frequency === null || $frequency === Frequency::ONE_TIME) {
				continue;
			}
			$monthly = $frequency->toMonthlyAmount((float)$income->getAmount());
			$total += $rates !== null ? (float)MoneyCalculator::multiply($monthly, $rates[$accountId], 10) : $monthly;
		}
		return $total;
	}

	/**
	 * Invalidate all forecast cache entries for a user.
	 * Call this when transactions are modified.
	 */
	public function invalidateCache(string $userId): void {
		if ($this->cache === null) {
			return;
		}

		// Prefix-clear: live keys carry horizon + visibility suffixes, so a
		// plain remove() of the bare key never matched anything and toggles
		// (e.g. exclude-from-forecast) appeared to do nothing for 5 minutes.
		$this->cache->clear("live_{$userId}_");
		$this->cache->remove("forecast_{$userId}_all");
	}

	/**
	 * Generate a full forecast for accounts.
	 */
	public function generateForecast(
		string $userId,
		?int $accountId = null,
		int $basedOnMonths = 3,
		int $forecastMonths = 6,
		?array $visibleAccountIds = null,
	): array {
		if ($accountId) {
			$accounts = [$this->accountMapper->find($accountId, $userId)];
		} else {
			$accounts = !empty($visibleAccountIds)
				? $this->accountMapper->findByIds($visibleAccountIds)
				: $this->accountMapper->findAll($userId);
			// The all-accounts forecast skips accounts flagged out of reports (#286)
			$accounts = array_values(array_filter($accounts, static fn ($a) => !$a->getExcludedFromReports()));
		}

		// Get future transaction adjustments to calculate balance as of today,
		// for every account forecast, shared ones included
		$today = $this->today($userId);
		$futureChanges = $this->futureChanges($accounts, $today);

		$forecast = [
			'summary' => [],
			'monthlyProjections' => [],
			'categoryForecasts' => [],
			'scenarios' => []
		];

		foreach ($accounts as $account) {
			// Calculate balance as of today (stored balance minus future transactions)
			$storedBalance = $account->getBalance();
			$futureChange = $futureChanges[$account->getId()] ?? 0;
			$currentBalance = $storedBalance - $futureChange;

			$accountForecast = $this->generateAccountForecast(
				$userId,
				$account,
				$currentBalance,
				$basedOnMonths,
				$forecastMonths
			);

			$forecast['summary'][] = [
				'accountId' => $account->getId(),
				'accountName' => $account->getName(),
				'currentBalance' => $currentBalance,
				'projectedBalance' => $accountForecast['projectedBalance'],
				'projectedChange' => $accountForecast['projectedBalance'] - $currentBalance,
				'confidence' => $accountForecast['confidence']
			];

			if ($accountId === null || $accountId === $account->getId()) {
				$forecast['monthlyProjections'] = $accountForecast['monthlyProjections'];
				$forecast['categoryForecasts'] = $accountForecast['categoryForecasts'];
			}
		}

		$forecast['scenarios'] = $this->scenarioBuilder->generateScenarios($userId, $accounts, $forecastMonths);

		return $forecast;
	}

	/**
	 * Get live forecast data for dashboard display.
	 * OPTIMIZED: Results are cached for 5 minutes.
	 */
	public function getLiveForecast(string $userId, int $forecastMonths = 6, ?array $visibleAccountIds = null): array {
		// Key includes account visibility: a share-restricted viewer and the
		// owner must never share a cache entry (the owner's totals would leak
		// across the visibility boundary for up to the TTL).
		$cacheKey = "live_{$userId}_{$forecastMonths}_" . md5(json_encode($visibleAccountIds ?? []));

		// Try to get from cache
		if ($this->cache !== null) {
			$cached = $this->cache->get($cacheKey);
			if ($cached !== null) {
				return $cached;
			}
		}

		$accounts = !empty($visibleAccountIds)
			? $this->accountMapper->findByIds($visibleAccountIds)
			: $this->accountMapper->findAll($userId);

		// The live (all-accounts) forecast skips accounts flagged out of reports (#286)
		$accounts = array_values(array_filter($accounts, static fn ($a) => !$a->getExcludedFromReports()));

		// Get future transaction adjustments to calculate balance as of today,
		// for every account forecast, shared ones included
		$today = $this->today($userId);
		$futureChanges = $this->futureChanges($accounts, $today);

		// Accounts in more than one currency are added up in the base
		// currency, as the dashboard summary adds them: the balances and the
		// history were summed as stored, so euros counted as pounds and half a
		// bitcoin as 50p. An account whose currency has no rate stays out.
		$conversion = $this->currencyTotals?->accountRates($accounts, $userId);
		$rates = ($conversion['currency'] ?? null) !== null ? $conversion['rates'] : null;

		$currentBalance = 0.0;
		$currencyCounts = [];

		foreach ($accounts as $account) {
			if ($rates !== null && !isset($rates[$account->getId()])) {
				continue;
			}

			// Calculate balance as of today (stored balance minus future transactions)
			$storedBalance = $account->getBalance();
			$futureChange = $futureChanges[$account->getId()] ?? 0;
			$accountBalance = $storedBalance - $futureChange;
			if ($rates !== null) {
				$accountBalance = (float)MoneyCalculator::multiply($accountBalance, $rates[$account->getId()], 10);
			}

			$currentBalance += $accountBalance;
			$currency = $account->getCurrency() ?? 'USD';
			$currencyCounts[$currency] = ($currencyCounts[$currency] ?? 0) + abs($accountBalance);
		}

		// Determine primary currency
		$primaryCurrency = 'USD';
		if ($rates !== null) {
			$primaryCurrency = (string)$conversion['currency'];
		} elseif (!empty($currencyCounts)) {
			arsort($currencyCounts);
			$primaryCurrency = array_key_first($currencyCounts);
		}

		// History: the twelve complete months before this one. The month in
		// progress is not a month of income and spending yet; counted as one,
		// four days of October read as a month in which almost nothing came
		// in, and the trend through it projected no income at all.
		$thisMonth = new \DateTimeImmutable(substr($today, 0, 8) . '01');
		$endDate = $thisMonth->modify('-1 day')->format('Y-m-d');
		$startDate = $thisMonth->modify('-12 months')->format('Y-m-d');
		$transactions = $this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate, null, $visibleAccountIds);
		if ($rates !== null) {
			// The history of an account left out above stays out with it
			$transactions = array_values(array_filter(
				$transactions,
				static fn ($t) => isset($rates[(int)$t->getAccountId()])
			));
		}

		// Drop extraordinary/one-time transactions so they don't skew the
		// projection averages. They still affect the real (current) balance
		// above — we only exclude them from the historical pattern (#270).
		$transactions = $this->filterForecastTransactions($transactions);
		// Transfers between the accounts forecast are neither income nor spending
		$transactions = PatternAnalyzer::withoutInternalTransfers($transactions);

		// Analyze patterns, each amount in the forecast's currency
		$monthlyData = $this->patternAnalyzer->aggregateMonthlyData($transactions, $rates ?? []);
		$months = count($monthlyData);

		// Calculate averages and trends
		$incomeValues = array_column($monthlyData, 'income');
		$expenseValues = array_column($monthlyData, 'expenses');
		$savingsValues = array_map(fn ($m) => $m['income'] - $m['expenses'], $monthlyData);

		$avgIncome = $months > 0 ? array_sum($incomeValues) / $months : 0;
		$avgExpenses = $months > 0 ? array_sum($expenseValues) / $months : 0;
		$avgSavings = $avgIncome - $avgExpenses;

		// Too few months for a trend: project the averages as they are
		$withTrend = $months >= self::MIN_TREND_MONTHS;
		$incomeTrend = $withTrend ? $this->trendCalculator->calculateTrend($incomeValues) : 0.0;
		$expenseTrend = $withTrend ? $this->trendCalculator->calculateTrend($expenseValues) : 0.0;
		$savingsTrend = $withTrend ? $this->trendCalculator->calculateTrend($savingsValues) : 0.0;

		// Income the user has set up to recur is known to keep coming, so
		// no trend takes projected income below it. Only with some history
		// to set spending against: on its own it would project nothing but
		// money coming in.
		$recurringIncome = $months > 0 ? $this->recurringMonthlyIncome($userId, $accounts, $rates) : 0.0;

		// Generate monthly projections
		$monthlyProjections = [];
		$projectedBalance = $currentBalance;
		$cumulativeSavings = 0;
		$savingsMonthlyData = [];

		// Months after the user's own, stepped from the 1st: from the 31st,
		// "+1 month" skipped any shorter month
		for ($i = 1; $i <= $forecastMonths; $i++) {
			$projectionDate = $thisMonth->modify("+{$i} months");
			$monthLabel = $projectionDate->format('M Y');

			$projectedIncome = max(0, $recurringIncome, $avgIncome + ($incomeTrend * $i));
			$projectedExpenses = max(0, $avgExpenses + ($expenseTrend * $i));
			$monthlySavings = $projectedIncome - $projectedExpenses;

			$projectedBalance += $monthlySavings;
			$cumulativeSavings += $monthlySavings;
			$savingsMonthlyData[] = $cumulativeSavings;

			$monthlyProjections[] = [
				// `yearMonth` (Y-m) is what the web UI formats in the user's
				// own language; `month` stays the English "M Y" label for the
				// forecast-warning notification and anything else reading it.
				'month' => $monthLabel,
				'yearMonth' => $projectionDate->format('Y-m'),
				'balance' => round($projectedBalance, 2),
				'income' => round($projectedIncome, 2),
				'expenses' => round($projectedExpenses, 2),
				'savings' => round($monthlySavings, 2)
			];
		}

		$savingsRate = $avgIncome > 0 ? ($avgSavings / $avgIncome) * 100 : 0;
		$categoryBreakdown = $this->patternAnalyzer->getCategoryBreakdown($userId, $transactions, $rates ?? []);
		$transactionCount = count($transactions);
		$confidence = $this->projector->calculateDataConfidence($months, $transactionCount, $incomeValues, $expenseValues);

		$result = [
			'currency' => $primaryCurrency,
			'currentBalance' => round($currentBalance, 2),
			'projectedBalance' => round($projectedBalance, 2),
			'monthlyProjections' => $monthlyProjections,
			'trends' => [
				'avgMonthlyIncome' => round($avgIncome, 2),
				'avgMonthlyExpenses' => round($avgExpenses, 2),
				'avgMonthlySavings' => round($avgSavings, 2),
				'incomeDirection' => $this->trendCalculator->getTrendDirection($incomeTrend, $avgIncome),
				'expenseDirection' => $this->trendCalculator->getTrendDirection($expenseTrend, $avgExpenses),
				'savingsDirection' => $this->trendCalculator->getTrendDirection($savingsTrend, $avgSavings),
			],
			'savingsProjection' => [
				'currentMonthlySavings' => round($avgSavings, 2),
				'projectedTotalSavings' => round($cumulativeSavings, 2),
				'savingsRate' => round($savingsRate, 1),
				'monthlyData' => $savingsMonthlyData
			],
			'categoryBreakdown' => $categoryBreakdown,
			'confidence' => round($confidence, 0),
			'dataQuality' => [
				'monthsOfData' => $months,
				'transactionCount' => $transactionCount,
				'isReliable' => $months >= 3 && $transactionCount >= 10
			]
		];

		// Store in cache
		if ($this->cache !== null) {
			$this->cache->set($cacheKey, $result, self::CACHE_TTL);
		}

		return $result;
	}

	/**
	 * Remove transactions flagged as excluded-from-forecast (#270).
	 * Re-indexes the array so downstream consumers can rely on sequential keys.
	 *
	 * @param array $transactions Transaction entities
	 * @return array Filtered transactions
	 */
	private function filterForecastTransactions(array $transactions): array {
		return array_values(array_filter(
			$transactions,
			fn ($t) => !($t->getExcludedFromForecast() ?? false)
		));
	}

	/**
	 * Generate forecast for a single account.
	 */
	private function generateAccountForecast(
		string $userId,
		$account,
		float $currentBalance,
		int $basedOnMonths,
		int $forecastMonths,
	): array {
		$accountId = $account->getId();

		// Get historical data, up to the user's today
		$endDate = $this->today($userId);
		$startDate = (new \DateTimeImmutable($endDate))->modify("-{$basedOnMonths} months")->format('Y-m-d');
		$transactions = $this->transactionMapper->findByDateRange($accountId, $startDate, $endDate);

		// Exclude extraordinary/one-time transactions from the pattern (#270).
		$transactions = $this->filterForecastTransactions($transactions);

		// Analyze patterns
		$patterns = $this->patternAnalyzer->analyzeTransactionPatterns($transactions, $basedOnMonths);

		// Generate monthly projections
		$monthlyProjections = $this->projector->generateMonthlyProjections(
			$currentBalance,
			$patterns,
			$forecastMonths
		);

		// Calculate final projected balance
		$projectedBalance = !empty($monthlyProjections)
			? $monthlyProjections[count($monthlyProjections) - 1]['endingBalance']
			: $currentBalance;

		// Category-level forecasts
		$categoryForecasts = $this->projector->generateCategoryForecasts($userId, $patterns, $forecastMonths);

		return [
			'projectedBalance' => $projectedBalance,
			'monthlyProjections' => $monthlyProjections,
			'categoryForecasts' => $categoryForecasts,
			'confidence' => $this->projector->calculateOverallConfidence($patterns, $forecastMonths)
		];
	}

	/**
	 * Get cash flow forecast.
	 */
	public function getCashFlowForecast(
		string $userId,
		string $startDate,
		string $endDate,
		?int $accountId = null,
		?array $visibleAccountIds = null,
	): array {
		return [
			'periods' => [],
			'cumulativeFlow' => [],
			'insights' => []
		];
	}

	/**
	 * Get spending trends analysis.
	 */
	public function getSpendingTrends(
		string $userId,
		?int $accountId = null,
		int $months = 12,
		?array $visibleAccountIds = null,
	): array {
		return [
			'monthlyTrends' => [],
			'categoryTrends' => [],
			'insights' => []
		];
	}

	/**
	 * Run scenario analysis.
	 */
	public function runScenarios(
		string $userId,
		?int $accountId = null,
		array $scenarios = [],
		?array $visibleAccountIds = null,
	): array {
		return $this->scenarioBuilder->runScenarios($userId, $accountId, $scenarios);
	}

	/**
	 * Generate enhanced forecast with scenarios and charts.
	 */
	public function generateEnhancedForecast(
		string $userId,
		?int $accountId = null,
		int $historicalPeriod = 6,
		int $forecastHorizon = 6,
		int $confidenceLevel = 90,
		?array $visibleAccountIds = null,
	): array {
		$baseForecast = $this->generateForecast($userId, $accountId, $historicalPeriod, $forecastHorizon, $visibleAccountIds);

		$intelligence = [
			'confidence' => $confidenceLevel,
			'trendAnalysis' => 'Based on ' . $historicalPeriod . ' months of data, spending trends show moderate growth',
			'seasonalityInsight' => 'No significant seasonal patterns detected in current data',
			'volatilityAssessment' => 'Spending volatility is within normal ranges'
		];

		$scenarios = [
			'conservative' => [
				'projectedBalance' => $this->scenarioBuilder->calculateScenarioBalance($userId, $accountId, -0.05, 0.08),
				'assumptions' => [
					'Income growth: -5% to +2%',
					'Expense increase: +3% to +8%',
					'Emergency buffer: 20%'
				]
			],
			'base' => [
				'projectedBalance' => $this->scenarioBuilder->calculateScenarioBalance($userId, $accountId, 0.02, 0.03),
				'assumptions' => [
					'Income growth: Current trend',
					'Expense growth: Historical average',
					'No major changes expected'
				]
			],
			'optimistic' => [
				'projectedBalance' => $this->scenarioBuilder->calculateScenarioBalance($userId, $accountId, 0.10, -0.02),
				'assumptions' => [
					'Income growth: +5% to +15%',
					'Expense reduction: -2% to +3%',
					'Favorable market conditions'
				]
			]
		];

		$chartData = [
			'labels' => $this->scenarioBuilder->generateMonthLabels($historicalPeriod, $forecastHorizon),
			'historical' => $this->scenarioBuilder->getHistoricalBalances($userId, $accountId, $historicalPeriod),
			'forecast' => [
				'base' => $this->scenarioBuilder->generateForecastBalances($scenarios['base'], $forecastHorizon),
				'conservative' => $this->scenarioBuilder->generateForecastBalances($scenarios['conservative'], $forecastHorizon),
				'optimistic' => $this->scenarioBuilder->generateForecastBalances($scenarios['optimistic'], $forecastHorizon)
			]
		];

		$metrics = [
			'avgIncome' => 5000.0,
			'avgExpenses' => 3500.0,
			'netCashflow' => 1500.0,
			'savingsRate' => 30.0,
			'incomeTrend' => 1,
			'expenseTrend' => 1,
			'cashflowTrend' => 1,
			'savingsTrend' => 1,
			'incomeChange' => '+5.2%',
			'expenseChange' => '+2.8%',
			'cashflowChange' => '+12.4%',
			'savingsChange' => '+3.1%'
		];

		$goalProjections = [
			'monthlySavings' => 1500.0,
			'projectedGrowth' => 0.05
		];

		$recommendations = [
			'high' => 'Consider increasing emergency fund by $500/month',
			'medium' => 'Optimize spending in dining category for better savings',
			'low' => 'Set up automated transfers to savings account'
		];

		return [
			'intelligence' => $intelligence,
			'scenarios' => $scenarios,
			'chartData' => $chartData,
			'metrics' => $metrics,
			'goalProjections' => $goalProjections,
			'recommendations' => $recommendations
		];
	}

	/**
	 * Export forecast data.
	 */
	public function exportForecast(string $userId, array $forecastData): array {
		return [
			'exportId' => uniqid(),
			'timestamp' => date('Y-m-d H:i:s'),
			'userId' => $userId,
			'data' => $forecastData,
			'format' => 'json',
			'version' => '1.0'
		];
	}
}
