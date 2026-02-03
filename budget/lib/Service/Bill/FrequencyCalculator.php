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
     * @param string|null $customPattern JSON pattern for custom frequency
     * @return string Next due date in Y-m-d format
     */
    public function calculateNextDueDate(
        string $frequency,
        ?int $dueDay,
        ?int $dueMonth,
        ?string $fromDate = null,
        ?string $customPattern = null
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

            case 'custom':
                return $this->calculateCustomNextDueDate($customPattern, $dueDay, $fromDate);

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
        $frequency = $bill->getFrequency();
        $amount = $bill->getAmount();

        if ($frequency === 'custom') {
            $occurrences = $this->getCustomOccurrencesPerYear($bill->getCustomRecurrencePattern());
            if ($occurrences > 0) {
                return ($amount * $occurrences) / 12;
            }
            return 0;
        }

        return $this->getMonthlyEquivalentFromValues($amount, $frequency);
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

    /**
     * Calculate next due date for custom frequency pattern.
     *
     * @param string|null $customPattern JSON pattern (e.g., {"months": [1, 6, 7]})
     * @param int|null $dueDay Day of the month for occurrences
     * @param string|null $fromDate Base date to calculate from
     * @return string Next due date in Y-m-d format
     */
    private function calculateCustomNextDueDate(?string $customPattern, ?int $dueDay, ?string $fromDate = null): string {
        $today = new \DateTime();
        $baseDate = $fromDate ? new \DateTime($fromDate) : clone $today;

        if (empty($customPattern)) {
            // No pattern defined, default to monthly
            return $baseDate->format('Y-m-d');
        }

        $pattern = json_decode($customPattern, true);
        if (!is_array($pattern)) {
            // Invalid pattern, default to monthly
            return $baseDate->format('Y-m-d');
        }

        // Handle {"months": [1, 6, 7]} pattern
        if (isset($pattern['months']) && is_array($pattern['months'])) {
            return $this->findNextMonthOccurrence($pattern['months'], $dueDay ?? 1, $baseDate, $today);
        }

        // Handle {"dates": [{"month": 1, "day": 15}, ...]} pattern (future enhancement)
        if (isset($pattern['dates']) && is_array($pattern['dates'])) {
            return $this->findNextDateOccurrence($pattern['dates'], $baseDate, $today);
        }

        // No valid pattern found
        return $baseDate->format('Y-m-d');
    }

    /**
     * Find next occurrence from a list of months.
     *
     * @param array $months List of month numbers (1-12)
     * @param int $day Day of the month
     * @param \DateTime $baseDate Base date
     * @param \DateTime $today Today's date
     * @return string Next due date
     */
    private function findNextMonthOccurrence(array $months, int $day, \DateTime $baseDate, \DateTime $today): string {
        if (empty($months)) {
            return $baseDate->format('Y-m-d');
        }

        // Sort months in ascending order
        sort($months);

        $currentYear = (int)$today->format('Y');
        $currentMonth = (int)$today->format('n');

        // Try to find next occurrence in current year
        foreach ($months as $month) {
            if ($month < 1 || $month > 12) {
                continue; // Skip invalid months
            }

            $candidate = new \DateTime();
            $candidate->setDate($currentYear, $month, min($day, (int)date('t', mktime(0, 0, 0, $month, 1, $currentYear))));

            if ($candidate > $today) {
                return $candidate->format('Y-m-d');
            }
        }

        // No occurrence found in current year, use first month of next year
        $nextYear = $currentYear + 1;
        $firstMonth = $months[0];
        $next = new \DateTime();
        $next->setDate($nextYear, $firstMonth, min($day, (int)date('t', mktime(0, 0, 0, $firstMonth, 1, $nextYear))));

        return $next->format('Y-m-d');
    }

    /**
     * Find next occurrence from a list of specific dates.
     *
     * @param array $dates List of date objects with 'month' and 'day' keys
     * @param \DateTime $baseDate Base date
     * @param \DateTime $today Today's date
     * @return string Next due date
     */
    private function findNextDateOccurrence(array $dates, \DateTime $baseDate, \DateTime $today): string {
        if (empty($dates)) {
            return $baseDate->format('Y-m-d');
        }

        $currentYear = (int)$today->format('Y');
        $candidates = [];

        // Build candidate dates for current and next year
        foreach ($dates as $dateSpec) {
            if (!isset($dateSpec['month']) || !isset($dateSpec['day'])) {
                continue;
            }

            $month = (int)$dateSpec['month'];
            $day = (int)$dateSpec['day'];

            if ($month < 1 || $month > 12) {
                continue;
            }

            // Try current year
            $maxDay = (int)date('t', mktime(0, 0, 0, $month, 1, $currentYear));
            $candidate = new \DateTime();
            $candidate->setDate($currentYear, $month, min($day, $maxDay));

            if ($candidate > $today) {
                $candidates[] = $candidate;
            }

            // Also add next year occurrence
            $nextYear = $currentYear + 1;
            $maxDayNext = (int)date('t', mktime(0, 0, 0, $month, 1, $nextYear));
            $candidateNext = new \DateTime();
            $candidateNext->setDate($nextYear, $month, min($day, $maxDayNext));
            $candidates[] = $candidateNext;
        }

        if (empty($candidates)) {
            return $baseDate->format('Y-m-d');
        }

        // Sort and return earliest date
        usort($candidates, fn($a, $b) => $a <=> $b);
        return $candidates[0]->format('Y-m-d');
    }

    /**
     * Get occurrences per year from custom pattern.
     *
     * @param string|null $customPattern JSON pattern
     * @return int Number of occurrences per year
     */
    public function getCustomOccurrencesPerYear(?string $customPattern): int {
        if (empty($customPattern)) {
            return 0;
        }

        $pattern = json_decode($customPattern, true);
        if (!is_array($pattern)) {
            return 0;
        }

        // For months pattern, count unique months
        if (isset($pattern['months']) && is_array($pattern['months'])) {
            return count(array_unique($pattern['months']));
        }

        // For dates pattern, count unique dates
        if (isset($pattern['dates']) && is_array($pattern['dates'])) {
            return count($pattern['dates']);
        }

        return 0;
    }
}
