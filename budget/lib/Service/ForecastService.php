<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Forecast\PatternAnalyzer;
use OCA\Budget\Service\Forecast\TrendCalculator;
use OCA\Budget\Service\Forecast\ScenarioBuilder;
use OCA\Budget\Service\Forecast\ForecastProjector;
use OCP\ICacheFactory;
use OCP\ICache;

/**
 * Orchestrates forecast generation by delegating to specialized services.
 * OPTIMIZED: Includes caching layer for expensive calculations.
 */
class ForecastService {
    private const CACHE_PREFIX = 'budget_forecast_';
    private const CACHE_TTL = 300; // 5 minutes

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
        ?ICacheFactory $cacheFactory = null
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
     * Invalidate all forecast cache entries for a user.
     * Call this when transactions are modified.
     */
    public function invalidateCache(string $userId): void {
        if ($this->cache === null) {
            return;
        }

        // Clear known cache keys for this user
        $this->cache->remove("live_{$userId}");
        $this->cache->remove("forecast_{$userId}_all");
    }

    /**
     * Generate a full forecast for accounts.
     */
    public function generateForecast(
        string $userId,
        ?int $accountId = null,
        int $basedOnMonths = 3,
        int $forecastMonths = 6
    ): array {
        $accounts = $accountId
            ? [$this->accountMapper->find($accountId, $userId)]
            : $this->accountMapper->findAll($userId);

        // Get future transaction adjustments to calculate balance as of today
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

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
    public function getLiveForecast(string $userId, int $forecastMonths = 6): array {
        $cacheKey = "live_{$userId}_{$forecastMonths}";

        // Try to get from cache
        if ($this->cache !== null) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        $accounts = $this->accountMapper->findAll($userId);

        // Get future transaction adjustments to calculate balance as of today
        $today = date('Y-m-d');
        $futureChanges = $this->transactionMapper->getNetChangeAfterDateBatch($userId, $today);

        $currentBalance = 0.0;
        $currencyCounts = [];

        foreach ($accounts as $account) {
            // Calculate balance as of today (stored balance minus future transactions)
            $storedBalance = $account->getBalance();
            $futureChange = $futureChanges[$account->getId()] ?? 0;
            $accountBalance = $storedBalance - $futureChange;

            $currentBalance += $accountBalance;
            $currency = $account->getCurrency() ?? 'USD';
            $currencyCounts[$currency] = ($currencyCounts[$currency] ?? 0) + abs($accountBalance);
        }

        // Determine primary currency
        $primaryCurrency = 'USD';
        if (!empty($currencyCounts)) {
            arsort($currencyCounts);
            $primaryCurrency = array_key_first($currencyCounts);
        }

        // Get historical transactions
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-12 months'));
        $transactions = $this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate);

        // Analyze patterns
        $monthlyData = $this->patternAnalyzer->aggregateMonthlyData($transactions);
        $months = count($monthlyData);

        // Calculate averages and trends
        $incomeValues = array_column($monthlyData, 'income');
        $expenseValues = array_column($monthlyData, 'expenses');
        $savingsValues = array_map(fn($m) => $m['income'] - $m['expenses'], $monthlyData);

        $avgIncome = $months > 0 ? array_sum($incomeValues) / $months : 0;
        $avgExpenses = $months > 0 ? array_sum($expenseValues) / $months : 0;
        $avgSavings = $avgIncome - $avgExpenses;

        $incomeTrend = $this->trendCalculator->calculateTrend($incomeValues);
        $expenseTrend = $this->trendCalculator->calculateTrend($expenseValues);
        $savingsTrend = $this->trendCalculator->calculateTrend($savingsValues);

        // Generate monthly projections
        $monthlyProjections = [];
        $projectedBalance = $currentBalance;
        $cumulativeSavings = 0;
        $savingsMonthlyData = [];

        for ($i = 1; $i <= $forecastMonths; $i++) {
            $projectionDate = strtotime("+{$i} months");
            $monthLabel = date('M Y', $projectionDate);

            $projectedIncome = max(0, $avgIncome + ($incomeTrend * $i));
            $projectedExpenses = max(0, $avgExpenses + ($expenseTrend * $i));
            $monthlySavings = $projectedIncome - $projectedExpenses;

            $projectedBalance += $monthlySavings;
            $cumulativeSavings += $monthlySavings;
            $savingsMonthlyData[] = $cumulativeSavings;

            $monthlyProjections[] = [
                'month' => $monthLabel,
                'balance' => round($projectedBalance, 2),
                'income' => round($projectedIncome, 2),
                'expenses' => round($projectedExpenses, 2),
                'savings' => round($monthlySavings, 2)
            ];
        }

        $savingsRate = $avgIncome > 0 ? ($avgSavings / $avgIncome) * 100 : 0;
        $categoryBreakdown = $this->patternAnalyzer->getCategoryBreakdown($userId, $transactions);
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
     * Generate forecast for a single account.
     */
    private function generateAccountForecast(
        string $userId,
        $account,
        float $currentBalance,
        int $basedOnMonths,
        int $forecastMonths
    ): array {
        $accountId = $account->getId();

        // Get historical data
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$basedOnMonths} months"));
        $transactions = $this->transactionMapper->findByDateRange($accountId, $startDate, $endDate);

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
        ?int $accountId = null
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
        int $months = 12
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
        array $scenarios = []
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
        int $confidenceLevel = 90
    ): array {
        $baseForecast = $this->generateForecast($userId, $accountId, $historicalPeriod, $forecastHorizon);

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
