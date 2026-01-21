<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

/**
 * Helper class for precise monetary calculations using BCMath.
 *
 * Uses string-based decimal arithmetic to avoid floating-point precision issues
 * that can occur with PHP floats when dealing with currency.
 */
class MoneyCalculator {
    /**
     * Default scale (decimal places) for calculations.
     */
    private const DEFAULT_SCALE = 2;

    /**
     * Add two monetary amounts.
     *
     * @param float|string $a First amount
     * @param float|string $b Second amount
     * @param int $scale Decimal places (default 2)
     * @return string Result as string
     */
    public static function add(float|string $a, float|string $b, int $scale = self::DEFAULT_SCALE): string {
        return bcadd(self::normalize($a), self::normalize($b), $scale);
    }

    /**
     * Subtract two monetary amounts.
     *
     * @param float|string $a Minuend
     * @param float|string $b Subtrahend
     * @param int $scale Decimal places (default 2)
     * @return string Result as string
     */
    public static function subtract(float|string $a, float|string $b, int $scale = self::DEFAULT_SCALE): string {
        return bcsub(self::normalize($a), self::normalize($b), $scale);
    }

    /**
     * Multiply two monetary amounts.
     *
     * @param float|string $a First amount
     * @param float|string $b Second amount
     * @param int $scale Decimal places (default 2)
     * @return string Result as string
     */
    public static function multiply(float|string $a, float|string $b, int $scale = self::DEFAULT_SCALE): string {
        return bcmul(self::normalize($a), self::normalize($b), $scale);
    }

    /**
     * Divide two monetary amounts.
     *
     * @param float|string $a Dividend
     * @param float|string $b Divisor
     * @param int $scale Decimal places (default 2)
     * @return string Result as string
     * @throws \InvalidArgumentException If divisor is zero
     */
    public static function divide(float|string $a, float|string $b, int $scale = self::DEFAULT_SCALE): string {
        $normalizedB = self::normalize($b);
        if (bccomp($normalizedB, '0', $scale) === 0) {
            throw new \InvalidArgumentException('Division by zero');
        }
        return bcdiv(self::normalize($a), $normalizedB, $scale);
    }

    /**
     * Compare two monetary amounts.
     *
     * @param float|string $a First amount
     * @param float|string $b Second amount
     * @param int $scale Decimal places (default 2)
     * @return int -1 if a < b, 0 if equal, 1 if a > b
     */
    public static function compare(float|string $a, float|string $b, int $scale = self::DEFAULT_SCALE): int {
        return bccomp(self::normalize($a), self::normalize($b), $scale);
    }

    /**
     * Check if two amounts are equal within tolerance.
     *
     * @param float|string $a First amount
     * @param float|string $b Second amount
     * @param float|string $tolerance Acceptable difference (default 0.01)
     * @return bool True if amounts are equal within tolerance
     */
    public static function equals(float|string $a, float|string $b, float|string $tolerance = '0.01'): bool {
        $diff = self::abs(self::subtract($a, $b, 4));
        return self::compare($diff, $tolerance, 4) <= 0;
    }

    /**
     * Get absolute value of amount.
     *
     * @param float|string $amount
     * @param int $scale Decimal places (default 2)
     * @return string Absolute value
     */
    public static function abs(float|string $amount, int $scale = self::DEFAULT_SCALE): string {
        $normalized = self::normalize($amount);
        if (bccomp($normalized, '0', $scale) < 0) {
            return bcmul($normalized, '-1', $scale);
        }
        return $normalized;
    }

    /**
     * Sum an array of amounts.
     *
     * @param array $amounts Array of float|string amounts
     * @param int $scale Decimal places (default 2)
     * @return string Sum as string
     */
    public static function sum(array $amounts, int $scale = self::DEFAULT_SCALE): string {
        $total = '0';
        foreach ($amounts as $amount) {
            $total = self::add($total, $amount, $scale);
        }
        return $total;
    }

    /**
     * Convert to float for storage/display (use sparingly).
     *
     * @param string $amount
     * @return float
     */
    public static function toFloat(string $amount): float {
        return (float) $amount;
    }

    /**
     * Format for display with currency symbol.
     *
     * @param float|string $amount
     * @param string $currency Currency code
     * @param int $decimals Number of decimal places
     * @return string Formatted amount
     */
    public static function format(float|string $amount, string $currency = 'USD', int $decimals = 2): string {
        $normalized = self::normalize($amount);
        $formatted = number_format(self::toFloat($normalized), $decimals, '.', ',');

        $symbols = [
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            'JPY' => '¥',
            'CAD' => 'C$',
            'AUD' => 'A$',
            'CHF' => 'CHF ',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . $formatted;
    }

    /**
     * Normalize input to string format suitable for BCMath.
     *
     * @param float|string $amount
     * @return string
     */
    private static function normalize(float|string $amount): string {
        if (is_float($amount)) {
            // Use sprintf to avoid scientific notation
            return sprintf('%.10f', $amount);
        }
        return (string) $amount;
    }
}
