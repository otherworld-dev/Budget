<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Forecast;

/**
 * Handles trend analysis and volatility calculations for forecasting.
 */
class TrendCalculator {
    /**
     * Calculate linear trend (slope) using least squares regression.
     *
     * @param array $values Numeric values
     * @return float The slope (positive = increasing trend)
     */
    public function calculateTrend(array $values): float {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }

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

        $denominator = $n * $sumX2 - $sumX * $sumX;
        if ($denominator == 0) {
            return 0.0;
        }

        return ($n * $sumXY - $sumX * $sumY) / $denominator;
    }

    /**
     * Calculate volatility (standard deviation) of values.
     *
     * @param array $values Numeric values
     * @return float The standard deviation
     */
    public function calculateVolatility(array $values): float {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        $squaredDiffs = array_map(
            fn($x) => pow($x - $mean, 2),
            $values
        );

        return sqrt(array_sum($squaredDiffs) / $count);
    }

    /**
     * Get trend direction as a descriptive string.
     *
     * @param float $trend The calculated trend value
     * @param float $average The average value for threshold calculation
     * @return string 'up', 'down', or 'stable'
     */
    public function getTrendDirection(float $trend, float $average): string {
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
     * Get trend as a descriptive label.
     *
     * @param float $trend The trend value
     * @return string 'increasing', 'decreasing', or 'stable'
     */
    public function getTrendLabel(float $trend): string {
        if ($trend > 0) {
            return 'increasing';
        } elseif ($trend < 0) {
            return 'decreasing';
        }
        return 'stable';
    }

    /**
     * Calculate coefficient of variation (relative volatility).
     *
     * @param array $values Numeric values
     * @return float Coefficient of variation (0-1 scale)
     */
    public function calculateRelativeVolatility(array $values): float {
        $count = count($values);
        if ($count === 0) {
            return 0.0;
        }

        $mean = array_sum($values) / $count;
        if ($mean == 0) {
            return 0.0;
        }

        $volatility = $this->calculateVolatility($values);
        return $volatility / abs($mean);
    }

    /**
     * Calculate moving average for smoothing data.
     *
     * @param array $values Numeric values
     * @param int $period Number of periods for average
     * @return array Moving averages (shorter than input)
     */
    public function calculateMovingAverage(array $values, int $period = 3): array {
        $count = count($values);
        if ($count < $period) {
            return [];
        }

        $movingAverages = [];
        for ($i = $period - 1; $i < $count; $i++) {
            $sum = 0;
            for ($j = 0; $j < $period; $j++) {
                $sum += $values[$i - $j];
            }
            $movingAverages[] = $sum / $period;
        }

        return $movingAverages;
    }
}
