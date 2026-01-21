<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Forecast;

use OCA\Budget\Db\CategoryMapper;

/**
 * Projects future values based on patterns and calculates confidence.
 */
class ForecastProjector {
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
     * Project monthly income based on patterns.
     *
     * @param array $patterns Analyzed patterns
     * @param int $monthsAhead Number of months into future
     * @return float Projected income
     */
    public function projectMonthlyIncome(array $patterns, int $monthsAhead): float {
        if (empty($patterns['monthly']['income'])) {
            return 0.0;
        }

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

    /**
     * Project monthly expenses based on patterns.
     *
     * @param array $patterns Analyzed patterns
     * @param int $monthsAhead Number of months into future
     * @return float Projected expenses
     */
    public function projectMonthlyExpenses(array $patterns, int $monthsAhead): float {
        if (empty($patterns['monthly']['expenses'])) {
            return 0.0;
        }

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

    /**
     * Generate category-level forecasts.
     * OPTIMIZED: Uses batch category lookup instead of N+1 pattern.
     *
     * @param string $userId User ID
     * @param array $patterns Analyzed patterns
     * @param int $forecastMonths Number of months to forecast
     * @return array Category forecasts
     */
    public function generateCategoryForecasts(string $userId, array $patterns, int $forecastMonths): array {
        $categoryPatterns = $patterns['categories'] ?? [];

        if (empty($categoryPatterns)) {
            return [];
        }

        // Batch load all categories at once (replaces N+1 pattern)
        $categoryIds = array_keys($categoryPatterns);
        $categories = $this->categoryMapper->findByIds($categoryIds, $userId);

        $forecasts = [];

        foreach ($categoryPatterns as $categoryId => $categoryPattern) {
            // Skip if category not found
            if (!isset($categories[$categoryId])) {
                continue;
            }

            $category = $categories[$categoryId];

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
                'trend' => $this->trendCalculator->getTrendLabel($categoryPattern['trend']),
                'volatility' => $categoryPattern['volatility'],
                'confidence' => $this->calculateCategoryConfidence($categoryPattern)
            ];
        }

        return $forecasts;
    }

    /**
     * Calculate confidence for a specific month's projection.
     *
     * @param array $patterns Analyzed patterns
     * @param int $monthsAhead Number of months into future
     * @return float Confidence score (0-1)
     */
    public function calculateConfidence(array $patterns, int $monthsAhead): float {
        $baseConfidence = 0.8;

        // Reduce confidence based on volatility
        $incomeVolatility = $patterns['monthly']['income']['volatility'] ?? 0;
        $expenseVolatility = $patterns['monthly']['expenses']['volatility'] ?? 0;
        $avgVolatility = ($incomeVolatility + $expenseVolatility) / 2;

        $volatilityPenalty = min($avgVolatility / 1000, 0.3);

        // Reduce confidence for longer forecasts
        $timeDecay = min($monthsAhead * 0.05, 0.4);

        return max(0.1, $baseConfidence - $volatilityPenalty - $timeDecay);
    }

    /**
     * Calculate confidence score based on data quality.
     *
     * @param int $months Number of months of data
     * @param int $transactionCount Number of transactions
     * @param array $incomeValues Income values for volatility
     * @param array $expenseValues Expense values for volatility
     * @return float Confidence score (0-100)
     */
    public function calculateDataConfidence(
        int $months,
        int $transactionCount,
        array $incomeValues,
        array $expenseValues
    ): float {
        $confidence = 50.0; // Base confidence

        // More months = higher confidence (up to +25)
        $confidence += min($months * 2, 25);

        // More transactions = higher confidence (up to +15)
        $confidence += min($transactionCount / 10, 15);

        // Lower volatility = higher confidence (up to +10)
        if (count($incomeValues) > 1) {
            $incomeVolatility = $this->trendCalculator->calculateVolatility($incomeValues);
            $avgIncome = array_sum($incomeValues) / count($incomeValues);
            if ($avgIncome > 0) {
                $relativeVolatility = $incomeVolatility / $avgIncome;
                $confidence += max(0, 10 - ($relativeVolatility * 20));
            }
        }

        return min(100, max(0, $confidence));
    }

    /**
     * Calculate confidence for category forecast.
     *
     * @param array $categoryPattern Category pattern data
     * @return float Confidence score (0-1)
     */
    public function calculateCategoryConfidence(array $categoryPattern): float {
        $baseConfidence = 0.7;

        // Higher confidence for more frequent transactions
        $frequencyBoost = min($categoryPattern['frequency'] ?? 0, 1.0) * 0.2;

        // Lower confidence for high volatility
        $volatilityPenalty = min(($categoryPattern['volatility'] ?? 0) / 500, 0.4);

        return max(0.1, min(1.0, $baseConfidence + $frequencyBoost - $volatilityPenalty));
    }

    /**
     * Calculate overall forecast confidence.
     *
     * @param array $patterns Analyzed patterns
     * @param int $forecastMonths Forecast horizon
     * @return float Confidence score (0-1)
     */
    public function calculateOverallConfidence(array $patterns, int $forecastMonths): float {
        $netValues = $patterns['monthly']['net'] ?? [];
        $dataQualityScore = count($netValues) / 12; // Prefer 12+ months
        $recurringScore = count($patterns['recurring'] ?? []) * 0.1;
        $timeDecay = 1 - ($forecastMonths * 0.08);

        return max(0.1, min(1.0, $dataQualityScore + $recurringScore + $timeDecay));
    }

    /**
     * Generate monthly projections.
     *
     * @param float $currentBalance Starting balance
     * @param array $patterns Analyzed patterns
     * @param int $forecastMonths Number of months to project
     * @return array Monthly projection data
     */
    public function generateMonthlyProjections(
        float $currentBalance,
        array $patterns,
        int $forecastMonths
    ): array {
        $projections = [];
        $balance = $currentBalance;

        for ($i = 1; $i <= $forecastMonths; $i++) {
            $projectionDate = date('Y-m-d', strtotime("+{$i} months"));
            $monthYear = date('Y-m', strtotime($projectionDate));

            $projectedIncome = $this->projectMonthlyIncome($patterns, $i);
            $projectedExpenses = $this->projectMonthlyExpenses($patterns, $i);
            $netChange = $projectedIncome - $projectedExpenses;
            $balance += $netChange;

            $projections[] = [
                'month' => $monthYear,
                'startingBalance' => $balance - $netChange,
                'projectedIncome' => round($projectedIncome, 2),
                'projectedExpenses' => round($projectedExpenses, 2),
                'netChange' => round($netChange, 2),
                'endingBalance' => round($balance, 2),
                'confidence' => $this->calculateConfidence($patterns, $i)
            ];
        }

        return $projections;
    }
}
