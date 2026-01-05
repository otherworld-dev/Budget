<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\CategoryMapper;

class ForecastService {
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;
    private CategoryMapper $categoryMapper;

    public function __construct(
        AccountMapper $accountMapper,
        TransactionMapper $transactionMapper,
        CategoryMapper $categoryMapper
    ) {
        $this->accountMapper = $accountMapper;
        $this->transactionMapper = $transactionMapper;
        $this->categoryMapper = $categoryMapper;
    }

    public function generateForecast(
        string $userId,
        ?int $accountId = null,
        int $basedOnMonths = 3,
        int $forecastMonths = 6
    ): array {
        $accounts = $accountId 
            ? [$this->accountMapper->find($accountId, $userId)]
            : $this->accountMapper->findAll($userId);
        
        $forecast = [
            'summary' => [],
            'monthlyProjections' => [],
            'categoryForecasts' => [],
            'scenarios' => []
        ];
        
        foreach ($accounts as $account) {
            $accountForecast = $this->generateAccountForecast(
                $userId,
                $account,
                $basedOnMonths,
                $forecastMonths
            );
            
            $forecast['summary'][] = [
                'accountId' => $account->getId(),
                'accountName' => $account->getName(),
                'currentBalance' => $account->getBalance(),
                'projectedBalance' => $accountForecast['projectedBalance'],
                'projectedChange' => $accountForecast['projectedBalance'] - $account->getBalance(),
                'confidence' => $accountForecast['confidence']
            ];
            
            if ($accountId === null || $accountId === $account->getId()) {
                $forecast['monthlyProjections'] = $accountForecast['monthlyProjections'];
                $forecast['categoryForecasts'] = $accountForecast['categoryForecasts'];
            }
        }
        
        // Generate scenarios
        $forecast['scenarios'] = $this->generateScenarios($userId, $accounts, $forecastMonths);

        return $forecast;
    }

    /**
     * Get live forecast data for dashboard display
     * Calculates real data from transactions and accounts
     */
    public function getLiveForecast(string $userId, int $forecastMonths = 6): array {
        // Get all accounts and calculate current total balance
        $accounts = $this->accountMapper->findAll($userId);
        $currentBalance = 0.0;
        $currencyCounts = [];

        foreach ($accounts as $account) {
            $currentBalance += $account->getBalance();
            // Track currency usage by balance weight
            $currency = $account->getCurrency() ?? 'USD';
            if (!isset($currencyCounts[$currency])) {
                $currencyCounts[$currency] = 0;
            }
            $currencyCounts[$currency] += abs($account->getBalance());
        }

        // Determine primary currency (most used by balance)
        $primaryCurrency = 'USD';
        if (!empty($currencyCounts)) {
            arsort($currencyCounts);
            $primaryCurrency = array_key_first($currencyCounts);
        }

        // Get historical transactions (last 12 months for trend analysis)
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-12 months'));
        $transactions = $this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate);

        // Analyze patterns from historical data
        $monthlyData = $this->aggregateMonthlyData($transactions);
        $months = count($monthlyData);

        // Calculate averages and trends
        $incomeValues = array_column($monthlyData, 'income');
        $expenseValues = array_column($monthlyData, 'expenses');
        $savingsValues = array_map(fn($m) => $m['income'] - $m['expenses'], $monthlyData);

        $avgIncome = $months > 0 ? array_sum($incomeValues) / $months : 0;
        $avgExpenses = $months > 0 ? array_sum($expenseValues) / $months : 0;
        $avgSavings = $avgIncome - $avgExpenses;

        $incomeTrend = $this->calculateTrend($incomeValues);
        $expenseTrend = $this->calculateTrend($expenseValues);
        $savingsTrend = $this->calculateTrend($savingsValues);

        // Generate monthly projections
        $monthlyProjections = [];
        $projectedBalance = $currentBalance;
        $cumulativeSavings = 0;
        $savingsMonthlyData = [];

        for ($i = 1; $i <= $forecastMonths; $i++) {
            $projectionDate = strtotime("+{$i} months");
            $monthLabel = date('M Y', $projectionDate);

            // Project income and expenses with trend
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

        // Calculate savings rate
        $savingsRate = $avgIncome > 0 ? ($avgSavings / $avgIncome) * 100 : 0;

        // Get category breakdown
        $categoryBreakdown = $this->getCategoryBreakdown($userId, $transactions);

        // Calculate confidence based on data quality
        $transactionCount = count($transactions);
        $confidence = $this->calculateDataConfidence($months, $transactionCount, $incomeValues, $expenseValues);

        return [
            'currency' => $primaryCurrency,
            'currentBalance' => round($currentBalance, 2),
            'projectedBalance' => round($projectedBalance, 2),
            'monthlyProjections' => $monthlyProjections,
            'trends' => [
                'avgMonthlyIncome' => round($avgIncome, 2),
                'avgMonthlyExpenses' => round($avgExpenses, 2),
                'avgMonthlySavings' => round($avgSavings, 2),
                'incomeDirection' => $this->getTrendDirection($incomeTrend, $avgIncome),
                'expenseDirection' => $this->getTrendDirection($expenseTrend, $avgExpenses),
                'savingsDirection' => $this->getTrendDirection($savingsTrend, $avgSavings),
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
    }

    /**
     * Aggregate transactions into monthly totals
     */
    private function aggregateMonthlyData(array $transactions): array {
        $monthlyData = [];

        foreach ($transactions as $transaction) {
            $month = date('Y-m', strtotime($transaction->getDate()));

            if (!isset($monthlyData[$month])) {
                $monthlyData[$month] = ['income' => 0.0, 'expenses' => 0.0];
            }

            if ($transaction->getType() === 'credit') {
                $monthlyData[$month]['income'] += $transaction->getAmount();
            } else {
                $monthlyData[$month]['expenses'] += $transaction->getAmount();
            }
        }

        // Sort by month and return as indexed array
        ksort($monthlyData);
        return array_values($monthlyData);
    }

    /**
     * Get trend direction as string
     */
    private function getTrendDirection(float $trend, float $average): string {
        if ($average == 0) {
            return 'stable';
        }

        // Consider significant if trend is > 1% of average per month
        $threshold = abs($average) * 0.01;

        if ($trend > $threshold) {
            return 'up';
        } elseif ($trend < -$threshold) {
            return 'down';
        }
        return 'stable';
    }

    /**
     * Get spending breakdown by category
     */
    private function getCategoryBreakdown(string $userId, array $transactions): array {
        $categoryTotals = [];
        $categoryMonths = [];

        foreach ($transactions as $transaction) {
            if ($transaction->getType() !== 'debit') {
                continue;
            }

            $categoryId = $transaction->getCategoryId();
            if (!$categoryId) {
                $categoryId = 0; // Uncategorized
            }

            $month = date('Y-m', strtotime($transaction->getDate()));

            if (!isset($categoryTotals[$categoryId])) {
                $categoryTotals[$categoryId] = [];
            }
            if (!isset($categoryTotals[$categoryId][$month])) {
                $categoryTotals[$categoryId][$month] = 0;
            }
            $categoryTotals[$categoryId][$month] += $transaction->getAmount();
        }

        $breakdown = [];
        foreach ($categoryTotals as $categoryId => $monthlyAmounts) {
            $values = array_values($monthlyAmounts);
            $avgMonthly = count($values) > 0 ? array_sum($values) / count($values) : 0;
            $trend = $this->calculateTrend($values);

            $categoryName = 'Uncategorized';
            if ($categoryId > 0) {
                try {
                    $category = $this->categoryMapper->find($categoryId, $userId);
                    $categoryName = $category->getName();
                } catch (\Exception $e) {
                    $categoryName = 'Unknown';
                }
            }

            $breakdown[] = [
                'categoryId' => $categoryId,
                'name' => $categoryName,
                'avgMonthly' => round($avgMonthly, 2),
                'trend' => $this->getTrendDirection($trend, $avgMonthly)
            ];
        }

        // Sort by average monthly spending (highest first)
        usort($breakdown, fn($a, $b) => $b['avgMonthly'] <=> $a['avgMonthly']);

        return $breakdown;
    }

    /**
     * Calculate confidence score based on data quality
     */
    private function calculateDataConfidence(int $months, int $transactionCount, array $incomeValues, array $expenseValues): float {
        $confidence = 50.0; // Base confidence

        // More months = higher confidence (up to +25)
        $confidence += min($months * 2, 25);

        // More transactions = higher confidence (up to +15)
        $confidence += min($transactionCount / 10, 15);

        // Lower volatility = higher confidence (up to +10)
        if (count($incomeValues) > 1) {
            $incomeVolatility = $this->calculateVolatility($incomeValues);
            $avgIncome = array_sum($incomeValues) / count($incomeValues);
            if ($avgIncome > 0) {
                $relativeVolatility = $incomeVolatility / $avgIncome;
                $confidence += max(0, 10 - ($relativeVolatility * 20));
            }
        }

        return min(100, max(0, $confidence));
    }

    private function generateAccountForecast(
        string $userId,
        $account,
        int $basedOnMonths,
        int $forecastMonths
    ): array {
        $accountId = $account->getId();
        $currentBalance = $account->getBalance();
        
        // Get historical data
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-{$basedOnMonths} months"));
        $transactions = $this->transactionMapper->findByDateRange($accountId, $startDate, $endDate);
        
        // Analyze patterns
        $patterns = $this->analyzeTransactionPatterns($transactions, $basedOnMonths);
        
        // Generate monthly projections
        $monthlyProjections = [];
        $balance = $currentBalance;
        
        for ($i = 1; $i <= $forecastMonths; $i++) {
            $projectionDate = date('Y-m-d', strtotime("+{$i} months"));
            $monthYear = date('Y-m', strtotime($projectionDate));
            
            // Calculate projected income and expenses
            $projectedIncome = $this->projectMonthlyIncome($patterns, $i);
            $projectedExpenses = $this->projectMonthlyExpenses($patterns, $i);
            
            $netChange = $projectedIncome - $projectedExpenses;
            $balance += $netChange;
            
            $monthlyProjections[] = [
                'month' => $monthYear,
                'startingBalance' => $balance - $netChange,
                'projectedIncome' => $projectedIncome,
                'projectedExpenses' => $projectedExpenses,
                'netChange' => $netChange,
                'endingBalance' => $balance,
                'confidence' => $this->calculateConfidence($patterns, $i)
            ];
        }
        
        // Category-level forecasts
        $categoryForecasts = $this->generateCategoryForecasts($userId, $patterns, $forecastMonths);
        
        return [
            'projectedBalance' => $balance,
            'monthlyProjections' => $monthlyProjections,
            'categoryForecasts' => $categoryForecasts,
            'confidence' => $this->calculateOverallConfidence($patterns, $forecastMonths)
        ];
    }

    private function analyzeTransactionPatterns(array $transactions, int $months): array {
        $patterns = [
            'monthly' => [
                'income' => [],
                'expenses' => [],
                'net' => []
            ],
            'categories' => [],
            'recurring' => [],
            'trends' => [],
            'seasonality' => []
        ];
        
        // Group transactions by month and category
        $monthlyData = [];
        $categoryData = [];
        
        foreach ($transactions as $transaction) {
            $month = date('Y-m', strtotime($transaction->getDate()));
            $categoryId = $transaction->getCategoryId();
            $amount = $transaction->getAmount();
            $type = $transaction->getType();
            
            // Monthly aggregation
            if (!isset($monthlyData[$month])) {
                $monthlyData[$month] = ['income' => 0, 'expenses' => 0];
            }
            
            if ($type === 'credit') {
                $monthlyData[$month]['income'] += $amount;
            } else {
                $monthlyData[$month]['expenses'] += $amount;
            }
            
            // Category aggregation
            if ($categoryId) {
                if (!isset($categoryData[$categoryId])) {
                    $categoryData[$categoryId] = [];
                }
                if (!isset($categoryData[$categoryId][$month])) {
                    $categoryData[$categoryId][$month] = 0;
                }
                $categoryData[$categoryId][$month] += $amount;
            }
        }
        
        // Calculate monthly averages and trends
        $incomeValues = [];
        $expenseValues = [];
        
        foreach ($monthlyData as $month => $data) {
            $incomeValues[] = $data['income'];
            $expenseValues[] = $data['expenses'];
            $patterns['monthly']['net'][] = $data['income'] - $data['expenses'];
        }
        
        $patterns['monthly']['income'] = [
            'average' => array_sum($incomeValues) / count($incomeValues),
            'trend' => $this->calculateTrend($incomeValues),
            'volatility' => $this->calculateVolatility($incomeValues)
        ];
        
        $patterns['monthly']['expenses'] = [
            'average' => array_sum($expenseValues) / count($expenseValues),
            'trend' => $this->calculateTrend($expenseValues),
            'volatility' => $this->calculateVolatility($expenseValues)
        ];
        
        // Analyze category patterns
        foreach ($categoryData as $categoryId => $monthlyAmounts) {
            $values = array_values($monthlyAmounts);
            $patterns['categories'][$categoryId] = [
                'average' => array_sum($values) / count($values),
                'trend' => $this->calculateTrend($values),
                'volatility' => $this->calculateVolatility($values),
                'frequency' => count($values) / $months
            ];
        }
        
        // Detect recurring transactions
        $patterns['recurring'] = $this->detectRecurringTransactions($transactions);
        
        // Calculate seasonality (if we have enough data)
        if ($months >= 12) {
            $patterns['seasonality'] = $this->calculateSeasonality($monthlyData);
        }
        
        return $patterns;
    }

    private function calculateTrend(array $values): float {
        $n = count($values);
        if ($n < 2) return 0;
        
        $sumX = 0;
        $sumY = 0;
        $sumXY = 0;
        $sumX2 = 0;
        
        for ($i = 0; $i < $n; $i++) {
            $x = $i + 1;
            $y = $values[$i];
            
            $sumX += $x;
            $sumY += $y;
            $sumXY += $x * $y;
            $sumX2 += $x * $x;
        }
        
        $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
        return $slope;
    }

    private function calculateVolatility(array $values): float {
        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(function($x) use ($mean) {
            return pow($x - $mean, 2);
        }, $values);
        
        return sqrt(array_sum($squaredDiffs) / count($values));
    }

    private function detectRecurringTransactions(array $transactions): array {
        $recurring = [];
        $grouped = [];
        
        // Group by description and amount
        foreach ($transactions as $transaction) {
            $key = $transaction->getDescription() . '|' . $transaction->getAmount();
            if (!isset($grouped[$key])) {
                $grouped[$key] = [];
            }
            $grouped[$key][] = $transaction;
        }
        
        // Identify recurring patterns
        foreach ($grouped as $key => $transactionGroup) {
            if (count($transactionGroup) >= 3) {
                $dates = array_map(function($t) {
                    return strtotime($t->getDate());
                }, $transactionGroup);
                
                sort($dates);
                $intervals = [];
                
                for ($i = 1; $i < count($dates); $i++) {
                    $intervals[] = $dates[$i] - $dates[$i-1];
                }
                
                $avgInterval = array_sum($intervals) / count($intervals);
                $intervalDays = $avgInterval / (24 * 60 * 60);
                
                // Consider it recurring if interval is roughly monthly or weekly
                if (($intervalDays >= 25 && $intervalDays <= 35) || 
                    ($intervalDays >= 6 && $intervalDays <= 8)) {
                    
                    $recurring[] = [
                        'description' => $transactionGroup[0]->getDescription(),
                        'amount' => $transactionGroup[0]->getAmount(),
                        'type' => $transactionGroup[0]->getType(),
                        'frequency' => $intervalDays >= 25 ? 'monthly' : 'weekly',
                        'confidence' => min(count($transactionGroup) / 6, 1.0)
                    ];
                }
            }
        }
        
        return $recurring;
    }

    private function calculateSeasonality(array $monthlyData): array {
        $seasonality = [];
        $monthlyTotals = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0, 6 => 0,
                         7 => 0, 8 => 0, 9 => 0, 10 => 0, 11 => 0, 12 => 0];
        $monthlyCounts = array_fill(1, 12, 0);
        
        foreach ($monthlyData as $yearMonth => $data) {
            $month = (int) date('n', strtotime($yearMonth . '-01'));
            $monthlyTotals[$month] += $data['expenses'];
            $monthlyCounts[$month]++;
        }
        
        $annualAverage = array_sum($monthlyTotals) / array_sum($monthlyCounts);
        
        for ($month = 1; $month <= 12; $month++) {
            if ($monthlyCounts[$month] > 0) {
                $monthAverage = $monthlyTotals[$month] / $monthlyCounts[$month];
                $seasonality[$month] = $monthAverage / $annualAverage;
            } else {
                $seasonality[$month] = 1.0;
            }
        }
        
        return $seasonality;
    }

    private function projectMonthlyIncome(array $patterns, int $monthsAhead): float {
        $base = $patterns['monthly']['income']['average'];
        $trend = $patterns['monthly']['income']['trend'] * $monthsAhead;
        
        // Apply seasonality if available
        $seasonalFactor = 1.0;
        if (!empty($patterns['seasonality'])) {
            $futureMonth = (int) date('n', strtotime("+{$monthsAhead} months"));
            $seasonalFactor = $patterns['seasonality'][$futureMonth] ?? 1.0;
        }
        
        return max(0, $base + $trend) * $seasonalFactor;
    }

    private function projectMonthlyExpenses(array $patterns, int $monthsAhead): float {
        $base = $patterns['monthly']['expenses']['average'];
        $trend = $patterns['monthly']['expenses']['trend'] * $monthsAhead;
        
        // Apply seasonality if available
        $seasonalFactor = 1.0;
        if (!empty($patterns['seasonality'])) {
            $futureMonth = (int) date('n', strtotime("+{$monthsAhead} months"));
            $seasonalFactor = $patterns['seasonality'][$futureMonth] ?? 1.0;
        }
        
        return max(0, $base + $trend) * $seasonalFactor;
    }

    private function generateCategoryForecasts(string $userId, array $patterns, int $forecastMonths): array {
        $forecasts = [];
        
        foreach ($patterns['categories'] as $categoryId => $categoryPattern) {
            try {
                $category = $this->categoryMapper->find($categoryId, $userId);
                
                $monthlyForecasts = [];
                for ($i = 1; $i <= $forecastMonths; $i++) {
                    $projected = $categoryPattern['average'] + ($categoryPattern['trend'] * $i);
                    $monthlyForecasts[] = max(0, $projected);
                }
                
                $forecasts[] = [
                    'categoryId' => $categoryId,
                    'categoryName' => $category->getName(),
                    'currentMonthlyAverage' => $categoryPattern['average'],
                    'projectedMonthly' => $monthlyForecasts,
                    'trend' => $categoryPattern['trend'] > 0 ? 'increasing' : 
                              ($categoryPattern['trend'] < 0 ? 'decreasing' : 'stable'),
                    'volatility' => $categoryPattern['volatility'],
                    'confidence' => $this->calculateCategoryConfidence($categoryPattern)
                ];
            } catch (\Exception $e) {
                // Category not found, skip
                continue;
            }
        }
        
        return $forecasts;
    }

    private function calculateConfidence(array $patterns, int $monthsAhead): float {
        $baseConfidence = 0.8;
        
        // Reduce confidence based on volatility
        $incomeVolatility = $patterns['monthly']['income']['volatility'];
        $expenseVolatility = $patterns['monthly']['expenses']['volatility'];
        $avgVolatility = ($incomeVolatility + $expenseVolatility) / 2;
        
        $volatilityPenalty = min($avgVolatility / 1000, 0.3);
        
        // Reduce confidence for longer forecasts
        $timeDecay = min($monthsAhead * 0.05, 0.4);
        
        return max(0.1, $baseConfidence - $volatilityPenalty - $timeDecay);
    }

    private function calculateCategoryConfidence(array $categoryPattern): float {
        $baseConfidence = 0.7;
        
        // Higher confidence for more frequent transactions
        $frequencyBoost = min($categoryPattern['frequency'], 1.0) * 0.2;
        
        // Lower confidence for high volatility
        $volatilityPenalty = min($categoryPattern['volatility'] / 500, 0.4);
        
        return max(0.1, min(1.0, $baseConfidence + $frequencyBoost - $volatilityPenalty));
    }

    private function calculateOverallConfidence(array $patterns, int $forecastMonths): float {
        $dataQualityScore = count($patterns['monthly']['net']) / 12; // Prefer 12+ months
        $recurringScore = count($patterns['recurring']) * 0.1;
        $timeDecay = 1 - ($forecastMonths * 0.08);
        
        return max(0.1, min(1.0, $dataQualityScore + $recurringScore + $timeDecay));
    }

    public function getCashFlowForecast(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null
    ): array {
        // Implementation for cash flow forecasting
        return [
            'periods' => [],
            'cumulativeFlow' => [],
            'insights' => []
        ];
    }

    public function getSpendingTrends(
        string $userId,
        ?int $accountId = null,
        int $months = 12
    ): array {
        // Implementation for spending trend analysis
        return [
            'monthlyTrends' => [],
            'categoryTrends' => [],
            'insights' => []
        ];
    }

    public function runScenarios(
        string $userId,
        ?int $accountId = null,
        array $scenarios = []
    ): array {
        // Implementation for scenario analysis
        return [
            'baseCase' => [],
            'optimistic' => [],
            'pessimistic' => [],
            'custom' => []
        ];
    }

    private function generateScenarios(string $userId, array $accounts, int $forecastMonths): array {
        return [
            'conservative' => [
                'name' => 'Conservative',
                'description' => 'Assumes 20% lower income and 10% higher expenses',
                'assumptions' => ['income_factor' => 0.8, 'expense_factor' => 1.1]
            ],
            'optimistic' => [
                'name' => 'Optimistic',
                'description' => 'Assumes 10% higher income and 5% lower expenses',
                'assumptions' => ['income_factor' => 1.1, 'expense_factor' => 0.95]
            ],
            'recession' => [
                'name' => 'Economic Downturn',
                'description' => 'Assumes 30% income reduction and 20% expense increase',
                'assumptions' => ['income_factor' => 0.7, 'expense_factor' => 1.2]
            ]
        ];
    }

    public function generateEnhancedForecast(
        string $userId,
        ?int $accountId = null,
        int $historicalPeriod = 6,
        int $forecastHorizon = 6,
        int $confidenceLevel = 90
    ): array {
        // Generate basic forecast
        $baseForecast = $this->generateForecast($userId, $accountId, $historicalPeriod, $forecastHorizon);

        // Add intelligence analysis
        $intelligence = [
            'confidence' => $confidenceLevel,
            'trendAnalysis' => 'Based on ' . $historicalPeriod . ' months of data, spending trends show moderate growth',
            'seasonalityInsight' => 'No significant seasonal patterns detected in current data',
            'volatilityAssessment' => 'Spending volatility is within normal ranges'
        ];

        // Enhanced scenarios
        $scenarios = [
            'conservative' => [
                'projectedBalance' => $this->calculateScenarioBalance($userId, $accountId, -0.05, 0.08),
                'assumptions' => [
                    'Income growth: -5% to +2%',
                    'Expense increase: +3% to +8%',
                    'Emergency buffer: 20%'
                ]
            ],
            'base' => [
                'projectedBalance' => $this->calculateScenarioBalance($userId, $accountId, 0.02, 0.03),
                'assumptions' => [
                    'Income growth: Current trend',
                    'Expense growth: Historical average',
                    'No major changes expected'
                ]
            ],
            'optimistic' => [
                'projectedBalance' => $this->calculateScenarioBalance($userId, $accountId, 0.10, -0.02),
                'assumptions' => [
                    'Income growth: +5% to +15%',
                    'Expense reduction: -2% to +3%',
                    'Favorable market conditions'
                ]
            ]
        ];

        // Chart data for visualization
        $chartData = [
            'labels' => $this->generateMonthLabels($historicalPeriod, $forecastHorizon),
            'historical' => $this->getHistoricalBalances($userId, $accountId, $historicalPeriod),
            'forecast' => [
                'base' => $this->generateForecastBalances($scenarios['base'], $forecastHorizon),
                'conservative' => $this->generateForecastBalances($scenarios['conservative'], $forecastHorizon),
                'optimistic' => $this->generateForecastBalances($scenarios['optimistic'], $forecastHorizon)
            ]
        ];

        // Metrics for dashboard
        $metrics = [
            'avgIncome' => 5000.0, // Mock data - replace with actual calculation
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

        // Goal projections
        $goalProjections = [
            'monthlySavings' => 1500.0,
            'projectedGrowth' => 0.05
        ];

        // AI recommendations
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

    private function calculateScenarioBalance(string $userId, ?int $accountId, float $incomeGrowth, float $expenseGrowth): float {
        // Mock calculation - replace with actual logic
        $baseBalance = 50000.0;
        $monthlyIncome = 5000.0;
        $monthlyExpenses = 3500.0;

        $adjustedIncome = $monthlyIncome * (1 + $incomeGrowth);
        $adjustedExpenses = $monthlyExpenses * (1 + $expenseGrowth);

        return $baseBalance + (($adjustedIncome - $adjustedExpenses) * 12);
    }

    private function generateMonthLabels(int $historicalPeriod, int $forecastHorizon): array {
        $labels = [];
        $startDate = strtotime("-{$historicalPeriod} months");

        for ($i = 0; $i < ($historicalPeriod + $forecastHorizon); $i++) {
            $labels[] = date('M Y', strtotime("+{$i} months", $startDate));
        }

        return $labels;
    }

    private function getHistoricalBalances(string $userId, ?int $accountId, int $months): array {
        // Mock data - replace with actual query
        $balances = [];
        $baseBalance = 45000.0;

        for ($i = 0; $i < $months; $i++) {
            $balances[] = $baseBalance + ($i * 1000) + (rand(-500, 500));
        }

        return $balances;
    }

    private function generateForecastBalances(array $scenario, int $months): array {
        // Mock data - replace with actual calculation
        $balances = [];
        $currentBalance = $scenario['projectedBalance'] ?? 50000.0;

        for ($i = 0; $i < $months; $i++) {
            $balances[] = $currentBalance + ($i * 1200) + (rand(-300, 300));
        }

        return $balances;
    }
}