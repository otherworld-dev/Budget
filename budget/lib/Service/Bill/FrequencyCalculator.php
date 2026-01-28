<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

use OCA\Budget\Db\Bill;
use OCA\Budget\Enum\Frequency;

/**
 * Handles frequency-based date calculations for bills.
 */
class FrequencyCalculator {
    /**
     * Calculate the next due date based on frequency and settings.
     *
     * @param string $frequency Bill frequency
     * @param int|null $dueDay Day of week (1-7) or day of month (1-31)
     * @param int|null $dueMonth Month for quarterly/yearly bills
     * @param string|null $fromDate Base date to calculate from
     * @return string Next due date in Y-m-d format
     */
    public function calculateNextDueDate(
        string $frequency,
        ?int $dueDay,
        ?int $dueMonth,
        ?string $fromDate = null
    ): string {
        $baseDate = $fromDate ? new \DateTime($fromDate) : new \DateTime();
        $today = new \DateTime();

        switch ($frequency) {
            case 'daily':
                $next = clone $baseDate;
                if ($next <= $today) {
                    $next->modify('+1 day');
                }
                return $next->format('Y-m-d');

            case 'weekly':
            case 'biweekly':
                $dayOfWeek = $dueDay ?? 1; // Default to Monday
                $next = clone $baseDate;
                $currentDayOfWeek = (int)$next->format('N');
                $daysToAdd = ($dayOfWeek - $currentDayOfWeek + 7) % 7;
                if ($daysToAdd === 0 && $next <= $today) {
                    $daysToAdd = $frequency === 'biweekly' ? 14 : 7;
                }
                $next->modify("+{$daysToAdd} days");
                return $next->format('Y-m-d');

            case 'monthly':
                $day = $dueDay ?? 1;
                $next = clone $baseDate;
                $maxDay = (int)$next->format('t');
                $next->setDate(
                    (int)$next->format('Y'),
                    (int)$next->format('m'),
                    min($day, $maxDay)
                );
                if ($next <= $today) {
                    $next->modify('+1 month');
                    $maxDay = (int)$next->format('t');
                    $next->setDate(
                        (int)$next->format('Y'),
                        (int)$next->format('m'),
                        min($day, $maxDay)
                    );
                }
                return $next->format('Y-m-d');

            case 'quarterly':
                $day = $dueDay ?? 1;
                $next = clone $baseDate;
                $currentMonth = (int)$next->format('n');
                $quarterMonth = ((int)ceil($currentMonth / 3)) * 3 - 2;
                if ($dueMonth) {
                    $quarterMonth = $dueMonth;
                }
                $next->setDate((int)$next->format('Y'), $quarterMonth, min($day, 28));
                if ($next <= $today) {
                    $next->modify('+3 months');
                }
                return $next->format('Y-m-d');

            case 'yearly':
                $day = $dueDay ?? 1;
                $month = $dueMonth ?? 1;
                $next = clone $baseDate;
                $next->setDate((int)$next->format('Y'), $month, min($day, 28));
                if ($next <= $today) {
                    $next->modify('+1 year');
                }
                return $next->format('Y-m-d');

            default:
                return $baseDate->format('Y-m-d');
        }
    }

    /**
     * Get the monthly equivalent amount for a bill.
     *
     * @param Bill $bill The bill entity
     * @return float Monthly equivalent amount
     */
    public function getMonthlyEquivalent(Bill $bill): float {
        return $this->getMonthlyEquivalentFromValues($bill->getAmount(), $bill->getFrequency());
    }

    /**
     * Get monthly equivalent from raw values.
     *
     * @param float $amount The bill amount
     * @param string $frequency The bill frequency
     * @return float Monthly equivalent
     */
    public function getMonthlyEquivalentFromValues(float $amount, string $frequency): float {
        return match ($frequency) {
            'daily' => $amount * 30,
            'weekly' => $amount * 52 / 12,
            'biweekly' => $amount * 26 / 12,
            'monthly' => $amount,
            'quarterly' => $amount / 3,
            'yearly' => $amount / 12,
            default => $amount,
        };
    }

    /**
     * Detect frequency from average interval in days.
     *
     * @param float $avgIntervalDays Average days between occurrences
     * @return string|null Detected frequency or null
     */
    public function detectFrequency(float $avgIntervalDays): ?string {
        if ($avgIntervalDays >= 0.5 && $avgIntervalDays <= 1.5) {
            return 'daily';
        }
        if ($avgIntervalDays >= 6 && $avgIntervalDays <= 8) {
            return 'weekly';
        }
        if ($avgIntervalDays >= 12 && $avgIntervalDays <= 16) {
            return 'biweekly';
        }
        // Expanded range for monthly: 23-37 days to catch 4-week payments (28 days) with variance
        if ($avgIntervalDays >= 23 && $avgIntervalDays <= 37) {
            return 'monthly';
        }
        if ($avgIntervalDays >= 85 && $avgIntervalDays <= 100) {
            return 'quarterly';
        }
        if ($avgIntervalDays >= 350 && $avgIntervalDays <= 380) {
            return 'yearly';
        }
        return null;
    }

    /**
     * Get the number of occurrences per year for a frequency.
     *
     * @param string $frequency The frequency
     * @return int Occurrences per year
     */
    public function getOccurrencesPerYear(string $frequency): int {
        return match ($frequency) {
            'daily' => 365,
            'weekly' => 52,
            'biweekly' => 26,
            'monthly' => 12,
            'quarterly' => 4,
            'yearly' => 1,
            default => 12,
        };
    }

    /**
     * Calculate the yearly total for a bill.
     *
     * @param float $amount The bill amount
     * @param string $frequency The frequency
     * @return float Yearly total
     */
    public function getYearlyTotal(float $amount, string $frequency): float {
        return $amount * $this->getOccurrencesPerYear($frequency);
    }
}
