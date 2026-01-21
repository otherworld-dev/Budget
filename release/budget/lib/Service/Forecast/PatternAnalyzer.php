<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Forecast;

use OCA\Budget\Db\CategoryMapper;

/**
 * Analyzes transaction patterns for forecasting.
 */
class PatternAnalyzer {
    private TrendCalculator $trendCalculator;
    private CategoryMapper $categoryMapper;

    public function __construct(
        TrendCalculator $trendCalculator,
        CategoryMapper $categoryMapper
    ) {
        $this->trendCalculator = $trendCalculator;
        $this->categoryMapper = $categoryMapper;
    }

    /**
     * Analyze transaction patterns over a period.
     *
     * @param array $transactions List of transaction entities
     * @param int $months Number of months of data
     * @return array Pattern analysis results
     */
    public function analyzeTransactionPatterns(array $transactions, int $months): array {
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

        foreach ($monthlyData as $data) {
            $incomeValues[] = $data['income'];
            $expenseValues[] = $data['expenses'];
            $patterns['monthly']['net'][] = $data['income'] - $data['expenses'];
        }

        if (!empty($incomeValues)) {
            $patterns['monthly']['income'] = [
                'average' => array_sum($incomeValues) / count($incomeValues),
                'trend' => $this->trendCalculator->calculateTrend($incomeValues),
                'volatility' => $this->trendCalculator->calculateVolatility($incomeValues)
            ];
        }

        if (!empty($expenseValues)) {
            $patterns['monthly']['expenses'] = [
                'average' => array_sum($expenseValues) / count($expenseValues),
                'trend' => $this->trendCalculator->calculateTrend($expenseValues),
                'volatility' => $this->trendCalculator->calculateVolatility($expenseValues)
            ];
        }

        // Analyze category patterns
        foreach ($categoryData as $categoryId => $monthlyAmounts) {
            $values = array_values($monthlyAmounts);
            $monthCount = count($values);

            if ($monthCount > 0) {
                $patterns['categories'][$categoryId] = [
                    'average' => array_sum($values) / $monthCount,
                    'trend' => $this->trendCalculator->calculateTrend($values),
                    'volatility' => $this->trendCalculator->calculateVolatility($values),
                    'frequency' => $monthCount / $months
                ];
            }
        }

        // Detect recurring transactions
        $patterns['recurring'] = $this->detectRecurringTransactions($transactions);

        // Calculate seasonality (if we have enough data)
        if ($months >= 12) {
            $patterns['seasonality'] = $this->calculateSeasonality($monthlyData);
        }

        return $patterns;
    }

    /**
     * Aggregate transactions into monthly totals.
     *
     * @param array $transactions List of transaction entities
     * @return array Monthly income/expense totals
     */
    public function aggregateMonthlyData(array $transactions): array {
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
     * Get spending breakdown by category.
     * OPTIMIZED: Uses batch category lookup instead of N+1 pattern.
     *
     * @param string $userId User ID
     * @param array $transactions List of transactions
     * @return array Category breakdown with trends
     */
    public function getCategoryBreakdown(string $userId, array $transactions): array {
        $categoryTotals = [];

        foreach ($transactions as $transaction) {
            if ($transaction->getType() !== 'debit') {
                continue;
            }

            $categoryId = $transaction->getCategoryId() ?? 0;
            $month = date('Y-m', strtotime($transaction->getDate()));

            if (!isset($categoryTotals[$categoryId])) {
                $categoryTotals[$categoryId] = [];
            }
            if (!isset($categoryTotals[$categoryId][$month])) {
                $categoryTotals[$categoryId][$month] = 0;
            }
            $categoryTotals[$categoryId][$month] += $transaction->getAmount();
        }

        // Batch load all categories at once (replaces N+1 pattern)
        $categoryIds = array_filter(array_keys($categoryTotals), fn($id) => $id > 0);
        $categories = !empty($categoryIds)
            ? $this->categoryMapper->findByIds($categoryIds, $userId)
            : [];

        $breakdown = [];
        foreach ($categoryTotals as $categoryId => $monthlyAmounts) {
            $values = array_values($monthlyAmounts);
            $avgMonthly = count($values) > 0 ? array_sum($values) / count($values) : 0;
            $trend = $this->trendCalculator->calculateTrend($values);

            $categoryName = 'Uncategorized';
            if ($categoryId > 0) {
                $categoryName = isset($categories[$categoryId])
                    ? $categories[$categoryId]->getName()
                    : 'Unknown';
            }

            $breakdown[] = [
                'categoryId' => $categoryId,
                'name' => $categoryName,
                'avgMonthly' => round($avgMonthly, 2),
                'trend' => $this->trendCalculator->getTrendDirection($trend, $avgMonthly)
            ];
        }

        // Sort by average monthly spending (highest first)
        usort($breakdown, fn($a, $b) => $b['avgMonthly'] <=> $a['avgMonthly']);

        return $breakdown;
    }

    /**
     * Detect recurring transactions based on patterns.
     *
     * @param array $transactions List of transactions
     * @return array Detected recurring patterns
     */
    public function detectRecurringTransactions(array $transactions): array {
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
                $dates = array_map(
                    fn($t) => strtotime($t->getDate()),
                    $transactionGroup
                );

                sort($dates);
                $intervals = [];

                for ($i = 1; $i < count($dates); $i++) {
                    $intervals[] = $dates[$i] - $dates[$i - 1];
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

    /**
     * Calculate seasonality factors by month.
     *
     * @param array $monthlyData Keyed by YYYY-MM with income/expenses
     * @return array Seasonality factors for months 1-12
     */
    public function calculateSeasonality(array $monthlyData): array {
        $seasonality = [];
        $monthlyTotals = array_fill(1, 12, 0);
        $monthlyCounts = array_fill(1, 12, 0);

        foreach ($monthlyData as $yearMonth => $data) {
            $month = (int) date('n', strtotime($yearMonth . '-01'));
            $monthlyTotals[$month] += $data['expenses'];
            $monthlyCounts[$month]++;
        }

        $totalSum = array_sum($monthlyTotals);
        $totalCount = array_sum($monthlyCounts);
        $annualAverage = $totalCount > 0 ? $totalSum / $totalCount : 0;

        if ($annualAverage == 0) {
            return array_fill(1, 12, 1.0);
        }

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
}
