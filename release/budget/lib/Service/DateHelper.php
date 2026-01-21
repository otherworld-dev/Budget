<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use DateInterval;
use DatePeriod;

/**
 * Centralized date helper service for consistent date operations.
 */
class DateHelper {
    public const DB_DATETIME_FORMAT = 'Y-m-d H:i:s';
    public const DB_DATE_FORMAT = 'Y-m-d';
    public const MONTH_FORMAT = 'Y-m';

    /**
     * Get the current datetime formatted for database storage.
     */
    public function now(): string {
        return date(self::DB_DATETIME_FORMAT);
    }

    /**
     * Get today's date formatted for database storage.
     */
    public function today(): string {
        return date(self::DB_DATE_FORMAT);
    }

    /**
     * Format a DateTime for database storage.
     */
    public function formatForDb(DateTimeInterface $date): string {
        return $date->format(self::DB_DATETIME_FORMAT);
    }

    /**
     * Format a DateTime as date only for database storage.
     */
    public function formatDateForDb(DateTimeInterface $date): string {
        return $date->format(self::DB_DATE_FORMAT);
    }

    /**
     * Format a DateTime as month string (Y-m).
     */
    public function formatMonth(DateTimeInterface $date): string {
        return $date->format(self::MONTH_FORMAT);
    }

    /**
     * Parse a date string into a DateTime object.
     * Supports common formats: Y-m-d, Y-m-d H:i:s
     */
    public function parse(string $date): DateTime {
        $parsed = DateTime::createFromFormat(self::DB_DATETIME_FORMAT, $date);
        if ($parsed === false) {
            $parsed = DateTime::createFromFormat(self::DB_DATE_FORMAT, $date);
        }
        if ($parsed === false) {
            $parsed = new DateTime($date);
        }
        return $parsed;
    }

    /**
     * Try to parse a date string, returns null on failure.
     */
    public function tryParse(string $date): ?DateTime {
        try {
            return $this->parse($date);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Get the first day of a month.
     */
    public function getMonthStart(?DateTimeInterface $date = null): DateTime {
        $date = $date ?? new DateTime();
        return new DateTime($date->format('Y-m-01 00:00:00'));
    }

    /**
     * Get the last day of a month.
     */
    public function getMonthEnd(?DateTimeInterface $date = null): DateTime {
        $date = $date ?? new DateTime();
        return new DateTime($date->format('Y-m-t 23:59:59'));
    }

    /**
     * Get the first day of a year.
     */
    public function getYearStart(?DateTimeInterface $date = null): DateTime {
        $date = $date ?? new DateTime();
        return new DateTime($date->format('Y-01-01 00:00:00'));
    }

    /**
     * Get the last day of a year.
     */
    public function getYearEnd(?DateTimeInterface $date = null): DateTime {
        $date = $date ?? new DateTime();
        return new DateTime($date->format('Y-12-31 23:59:59'));
    }

    /**
     * Get a date range for a number of months from now or a given date.
     *
     * @param int $months Number of months (negative for past, positive for future)
     * @param DateTimeInterface|null $from Starting date (defaults to now)
     * @return array{start: string, end: string} Date strings in Y-m-d format
     */
    public function getDateRange(int $months, ?DateTimeInterface $from = null): array {
        $from = $from ? DateTime::createFromInterface($from) : new DateTime();

        if ($months >= 0) {
            $start = clone $from;
            $end = (clone $from)->modify("+{$months} months");
        } else {
            $absMonths = abs($months);
            $start = (clone $from)->modify("-{$absMonths} months");
            $end = clone $from;
        }

        return [
            'start' => $start->format(self::DB_DATE_FORMAT),
            'end' => $end->format(self::DB_DATE_FORMAT),
        ];
    }

    /**
     * Get the number of months between two dates.
     */
    public function getMonthsBetween(DateTimeInterface $start, DateTimeInterface $end): int {
        $interval = $start->diff($end);
        return ($interval->y * 12) + $interval->m;
    }

    /**
     * Get the number of days between two dates.
     */
    public function getDaysBetween(DateTimeInterface $start, DateTimeInterface $end): int {
        $interval = $start->diff($end);
        return (int) $interval->days;
    }

    /**
     * Check if a date is in the past.
     */
    public function isPast(DateTimeInterface $date): bool {
        return $date < new DateTime();
    }

    /**
     * Check if a date is in the future.
     */
    public function isFuture(DateTimeInterface $date): bool {
        return $date > new DateTime();
    }

    /**
     * Check if a date is today.
     */
    public function isToday(DateTimeInterface $date): bool {
        return $date->format(self::DB_DATE_FORMAT) === date(self::DB_DATE_FORMAT);
    }

    /**
     * Check if a date is within a range.
     */
    public function isWithinRange(
        DateTimeInterface $date,
        DateTimeInterface $start,
        DateTimeInterface $end
    ): bool {
        return $date >= $start && $date <= $end;
    }

    /**
     * Check if a date is in the current month.
     */
    public function isCurrentMonth(DateTimeInterface $date): bool {
        return $date->format(self::MONTH_FORMAT) === date(self::MONTH_FORMAT);
    }

    /**
     * Add months to a date.
     */
    public function addMonths(DateTimeInterface $date, int $months): DateTime {
        $result = DateTime::createFromInterface($date);
        $result->modify("+{$months} months");
        return $result;
    }

    /**
     * Subtract months from a date.
     */
    public function subMonths(DateTimeInterface $date, int $months): DateTime {
        $result = DateTime::createFromInterface($date);
        $result->modify("-{$months} months");
        return $result;
    }

    /**
     * Add days to a date.
     */
    public function addDays(DateTimeInterface $date, int $days): DateTime {
        $result = DateTime::createFromInterface($date);
        $result->modify("+{$days} days");
        return $result;
    }

    /**
     * Get an array of month labels for a date range.
     *
     * @param int $months Number of months
     * @param DateTimeInterface|null $from Starting date
     * @return array<string> Array of month labels (e.g., ['Jan 2024', 'Feb 2024'])
     */
    public function getMonthLabels(int $months, ?DateTimeInterface $from = null): array {
        $from = $from ? DateTime::createFromInterface($from) : new DateTime();
        $labels = [];

        for ($i = 0; $i < $months; $i++) {
            $date = (clone $from)->modify("+{$i} months");
            $labels[] = $date->format('M Y');
        }

        return $labels;
    }

    /**
     * Get an array of month keys for a date range.
     *
     * @param int $months Number of months
     * @param DateTimeInterface|null $from Starting date
     * @return array<string> Array of month keys (e.g., ['2024-01', '2024-02'])
     */
    public function getMonthKeys(int $months, ?DateTimeInterface $from = null): array {
        $from = $from ? DateTime::createFromInterface($from) : new DateTime();
        $keys = [];

        for ($i = 0; $i < $months; $i++) {
            $date = (clone $from)->modify("+{$i} months");
            $keys[] = $date->format(self::MONTH_FORMAT);
        }

        return $keys;
    }

    /**
     * Get the start of the day for a date.
     */
    public function startOfDay(DateTimeInterface $date): DateTime {
        return new DateTime($date->format('Y-m-d 00:00:00'));
    }

    /**
     * Get the end of the day for a date.
     */
    public function endOfDay(DateTimeInterface $date): DateTime {
        return new DateTime($date->format('Y-m-d 23:59:59'));
    }

    /**
     * Calculate the next occurrence of a day-of-month from a given date.
     *
     * @param int $dayOfMonth The target day (1-31)
     * @param DateTimeInterface|null $from Starting date
     * @return DateTime
     */
    public function getNextDayOfMonth(int $dayOfMonth, ?DateTimeInterface $from = null): DateTime {
        $from = $from ? DateTime::createFromInterface($from) : new DateTime();
        $currentDay = (int) $from->format('d');
        $currentMonth = (int) $from->format('m');
        $currentYear = (int) $from->format('Y');

        // Clamp day to valid range for the month
        $dayOfMonth = min($dayOfMonth, 28); // Safe for all months

        if ($currentDay >= $dayOfMonth) {
            // Move to next month
            if ($currentMonth === 12) {
                $currentMonth = 1;
                $currentYear++;
            } else {
                $currentMonth++;
            }
        }

        return new DateTime(sprintf('%04d-%02d-%02d', $currentYear, $currentMonth, $dayOfMonth));
    }

    /**
     * Get the quarter for a date (1-4).
     */
    public function getQuarter(DateTimeInterface $date): int {
        $month = (int) $date->format('n');
        return (int) ceil($month / 3);
    }

    /**
     * Get the start of a quarter.
     */
    public function getQuarterStart(DateTimeInterface $date): DateTime {
        $quarter = $this->getQuarter($date);
        $month = (($quarter - 1) * 3) + 1;
        return new DateTime($date->format('Y') . sprintf('-%02d-01 00:00:00', $month));
    }

    /**
     * Get the end of a quarter.
     */
    public function getQuarterEnd(DateTimeInterface $date): DateTime {
        $quarter = $this->getQuarter($date);
        $month = $quarter * 3;
        $lastDay = cal_days_in_month(CAL_GREGORIAN, $month, (int) $date->format('Y'));
        return new DateTime($date->format('Y') . sprintf('-%02d-%02d 23:59:59', $month, $lastDay));
    }
}
