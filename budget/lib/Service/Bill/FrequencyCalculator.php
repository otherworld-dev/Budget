<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

use OCA\Budget\Db\Bill;
use OCA\Budget\Enum\Frequency;

/**
 * Handles frequency-based date calculations for bills.
 */
class FrequencyCalculator {
	/** Days an occurrenceAfter() search looks ahead: a yearly item is always found */
	private const SEARCH_DAYS = 800;

	/**
	 * Every date a schedule falls on between $from and $to, inclusive.
	 *
	 * The schedule alone decides: no "today" is involved, so a list, a
	 * payment, a projection and a calendar export all see the same dates.
	 *
	 *  - $anchor is the start date. Nothing occurs before it, a weekly or
	 *    bi-weekly schedule counts its 7 or 14 days from it, a one-time item
	 *    falls on it, and it supplies the day or month a calendar schedule
	 *    doesn't state.
	 *  - A day past a month's end falls on that month's last day, and the
	 *    next month goes back to the stated day.
	 *  - Quarterly and half-yearly schedules repeat from their month round the
	 *    year, so a November quarter also falls in February, May and August.
	 *  - Semi-monthly falls twice a month fifteen days apart: the stated day
	 *    and fifteen days later, or fifteen days earlier for a day past the 15th.
	 *  - A custom schedule with no usable pattern runs monthly on its day.
	 *
	 * @return string[] Y-m-d dates in order
	 */
	public function occurrencesBetween(
		string $frequency,
		?int $dueDay,
		?int $dueMonth,
		string $from,
		string $to,
		?string $customPattern = null,
		?string $anchor = null,
	): array {
		$from = $this->day($from);
		$to = $this->day($to);
		$anchor = ($anchor === null || $anchor === '') ? null : $this->day($anchor);
		if ($anchor !== null && $anchor > $from) {
			$from = $anchor;
		}
		if ($from > $to) {
			return [];
		}

		switch ($frequency) {
			case 'one-time':
				return ($anchor !== null && $anchor >= $from && $anchor <= $to) ? [$anchor] : [];
			case 'daily':
				return $this->stepDays($from, $from, $to, 1);
			case 'weekly':
			case 'biweekly':
				$interval = $frequency === 'biweekly' ? 14 : 7;
				if ($anchor === null) {
					// No start date: a fixed reference week keeps a bi-weekly
					// schedule's week the same however it is asked
					$weekday = ($dueDay !== null && $dueDay >= 1 && $dueDay <= 7) ? $dueDay : 1;
					$anchor = (new \DateTimeImmutable('1970-01-05'))->modify('+' . ($weekday - 1) . ' days')->format('Y-m-d');
				}
				return $this->stepDays($anchor, $from, $to, $interval);
		}

		$dates = [];
		$cursor = new \DateTimeImmutable(substr($from, 0, 7) . '-01');
		$last = substr($to, 0, 7);
		while ($cursor->format('Y-m') <= $last) {
			foreach ($this->daysInMonth($frequency, $dueDay, $dueMonth, $customPattern, $anchor, (int)$cursor->format('Y'), (int)$cursor->format('n')) as $date) {
				if ($date >= $from && $date <= $to) {
					$dates[] = $date;
				}
			}
			$cursor = $cursor->modify('+1 month');
		}
		return $dates;
	}

	/**
	 * The first date the schedule falls on strictly after $date: the
	 * occurrence after one just paid, received or skipped. Null when there
	 * is none (a one-time item on or before its date).
	 */
	public function occurrenceAfter(
		string $frequency,
		?int $dueDay,
		?int $dueMonth,
		string $date,
		?string $customPattern = null,
		?string $anchor = null,
	): ?string {
		$next = (new \DateTimeImmutable($this->day($date)))->modify('+1 day')->format('Y-m-d');
		return $this->occurrenceOnOrAfter($frequency, $dueDay, $dueMonth, $next, $customPattern, $anchor);
	}

	/**
	 * The first date the schedule falls on on or after $date: due today is
	 * due today. Null when there is none.
	 */
	public function occurrenceOnOrAfter(
		string $frequency,
		?int $dueDay,
		?int $dueMonth,
		string $date,
		?string $customPattern = null,
		?string $anchor = null,
	): ?string {
		$date = $this->day($date);
		if ($anchor !== null && $anchor !== '' && $this->day($anchor) > $date) {
			$date = $this->day($anchor);
		}
		$to = (new \DateTimeImmutable($date))->modify('+' . self::SEARCH_DAYS . ' days')->format('Y-m-d');
		return $this->occurrencesBetween($frequency, $dueDay, $dueMonth, $date, $to, $customPattern, $anchor)[0] ?? null;
	}

	/**
	 * The last date the schedule falls on strictly before $date, or null.
	 */
	public function occurrenceBefore(
		string $frequency,
		?int $dueDay,
		?int $dueMonth,
		string $date,
		?string $customPattern = null,
		?string $anchor = null,
	): ?string {
		$date = new \DateTimeImmutable($this->day($date));
		$dates = $this->occurrencesBetween(
			$frequency, $dueDay, $dueMonth,
			$date->modify('-' . self::SEARCH_DAYS . ' days')->format('Y-m-d'),
			$date->modify('-1 day')->format('Y-m-d'),
			$customPattern, $anchor
		);
		return $dates === [] ? null : end($dates);
	}

	/**
	 * Where a pending occurrence lands when its schedule changes.
	 *
	 * The occurrence of the same period under the new schedule (see
	 * periodStart()), and never one on or before the last occurrence the old
	 * schedule had already settled: switching a weekly bill paid a week ago
	 * to bi-weekly must not bring that week back. A weekly schedule with no
	 * start date counts its old occurrences from the pending date itself,
	 * the only fixed point it has.
	 *
	 * @param array{frequency: string, dueDay: ?int, dueMonth: ?int, pattern: ?string, anchor: ?string} $old
	 * @param array{frequency: string, dueDay: ?int, dueMonth: ?int, pattern: ?string, anchor: ?string} $new
	 */
	public function reschedule(array $old, string $pending, array $new): ?string {
		if ($new['frequency'] === 'one-time') {
			return $new['anchor'];
		}
		$from = $this->periodStart($new['frequency'], $pending);
		if ($old['frequency'] !== 'one-time') {
			$oldAnchor = $old['anchor'] ?? (in_array($old['frequency'], ['weekly', 'biweekly'], true) ? $pending : null);
			$settled = $this->occurrenceBefore($old['frequency'], $old['dueDay'], $old['dueMonth'], $pending, $old['pattern'], $oldAnchor);
			if ($settled !== null) {
				$from = max($from, (new \DateTimeImmutable($settled))->modify('+1 day')->format('Y-m-d'));
			}
		}
		return $this->occurrenceOnOrAfter($new['frequency'], $new['dueDay'], $new['dueMonth'], $from, $new['pattern'], $new['anchor']);
	}

	/**
	 * Where the period of a pending occurrence begins, for moving it when its
	 * schedule changes: the occurrence under the new schedule is the first
	 * on or after this. For calendar schedules that is the month, so moving
	 * a day from the 3rd to the 25th keeps October's payment in October; for
	 * weekly ones it is half an interval back, so the nearest weekday wins.
	 */
	public function periodStart(string $frequency, string $occurrence): string {
		$date = new \DateTimeImmutable($this->day($occurrence));
		return match ($frequency) {
			'daily', 'one-time' => $date->format('Y-m-d'),
			'weekly' => $date->modify('-3 days')->format('Y-m-d'),
			'biweekly' => $date->modify('-7 days')->format('Y-m-d'),
			'semi-monthly' => max($date->format('Y-m-01'), $date->modify('-7 days')->format('Y-m-d')),
			default => $date->format('Y-m-01'),
		};
	}

	/** A date as Y-m-d, whatever time of day it came with */
	private function day(string $date): string {
		return (new \DateTimeImmutable($date))->format('Y-m-d');
	}

	/**
	 * Dates $start + n * $interval days (n >= 0) between $from and $to.
	 *
	 * @return string[]
	 */
	private function stepDays(string $start, string $from, string $to, int $interval): array {
		$cursor = new \DateTimeImmutable($start);
		$fromDay = new \DateTimeImmutable($from);
		if ($cursor < $fromDay) {
			// Jump whole intervals at once, so an old start date stays cheap
			$steps = intdiv((int)$cursor->diff($fromDay)->format('%a') + $interval - 1, $interval);
			$cursor = $cursor->modify('+' . ($steps * $interval) . ' days');
		}
		$dates = [];
		while (($d = $cursor->format('Y-m-d')) <= $to) {
			$dates[] = $d;
			$cursor = $cursor->modify("+{$interval} days");
		}
		return $dates;
	}

	/**
	 * The dates a calendar schedule falls on within one month.
	 *
	 * @return string[]
	 */
	private function daysInMonth(string $frequency, ?int $dueDay, ?int $dueMonth, ?string $customPattern, ?string $anchor, int $year, int $month): array {
		$day = $dueDay ?? ($anchor !== null ? (int)substr($anchor, 8, 2) : 1);
		$baseMonth = $dueMonth ?? ($anchor !== null ? (int)substr($anchor, 5, 2) : 1);
		$onCycle = fn (int $cycle): bool => (($month - $baseMonth) % $cycle + $cycle) % $cycle === 0;

		switch ($frequency) {
			case 'monthly':
				return [$this->clamped($year, $month, $day)];
			case 'quarterly':
				return $onCycle(3) ? [$this->clamped($year, $month, $day)] : [];
			case 'semi-annually':
				return $onCycle(6) ? [$this->clamped($year, $month, $day)] : [];
			case 'yearly':
				return $month === $baseMonth ? [$this->clamped($year, $month, $day)] : [];
			case 'semi-monthly':
				[$first, $second] = $day <= 15 ? [$day, $day + 15] : [$day - 15, $day];
				return array_values(array_unique([$this->clamped($year, $month, $first), $this->clamped($year, $month, $second)]));
			case 'custom':
				$pattern = $customPattern !== null && $customPattern !== '' ? json_decode($customPattern, true) : null;
				if (is_array($pattern) && !empty($pattern['months']) && is_array($pattern['months'])) {
					$months = array_map('intval', $pattern['months']);
					return in_array($month, $months, true) ? [$this->clamped($year, $month, $dueDay ?? 1)] : [];
				}
				if (is_array($pattern) && !empty($pattern['dates']) && is_array($pattern['dates'])) {
					$dates = [];
					foreach ($pattern['dates'] as $spec) {
						if (is_array($spec) && (int)($spec['month'] ?? 0) === $month && isset($spec['day'])) {
							$dates[] = $this->clamped($year, $month, (int)$spec['day']);
						}
					}
					sort($dates);
					return array_values(array_unique($dates));
				}
				// No usable pattern: monthly on the day, never every day
				return [$this->clamped($year, $month, $day)];
			default:
				return [];
		}
	}

	/**
	 * The occurrence a stored due date stands for.
	 *
	 * Before 3.0 a schedule with no day fell on the 1st, one with no month in
	 * January (January and July half-yearly, the first month of each
	 * calendar quarter quarterly), and a semi-monthly second date stopped at
	 * the 28th. 3.0 takes the day and month from the start date, so a date
	 * saved then can sit before its period's occurrence: a monthly bill due
	 * on the 20th saved for 1 November. Paying it found 20 November next,
	 * and auto-pay paid November twice. Such a date is the next occurrence in
	 * its own month (calendar quarter, half-year or year for the longer
	 * schedules). A date past its period's occurrence is a late one and
	 * advances as it always did; a weekly schedule realigns on its start
	 * date's week instead.
	 */
	private function storedOccurrence(string $frequency, ?int $dueDay, ?int $dueMonth, string $date, ?string $anchor): string {
		$months = match ($frequency) {
			'monthly', 'semi-monthly' => 1,
			'quarterly' => 3,
			'semi-annually' => 6,
			'yearly' => 12,
			default => null,
		};
		if ($months === null) {
			return $date;
		}
		$occurrence = $this->occurrenceOnOrAfter($frequency, $dueDay, $dueMonth, $date, null, $anchor);
		$period = fn (string $day): string => substr($day, 0, 4) . '/' . intdiv((int)substr($day, 5, 2) - 1, $months);
		return ($occurrence !== null && $period($occurrence) === $period($date)) ? $occurrence : $date;
	}

	/** $day of the month, or the month's last day when it has fewer */
	private function clamped(int $year, int $month, int $day): string {
		$last = (int)(new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
		return sprintf('%04d-%02d-%02d', $year, $month, max(1, min($day, $last)));
	}

	/**
	 * The next due date for a bill, income or contribution.
	 *
	 *  - With $forceAdvance and a $fromDate (the occurrence just paid,
	 *    received or skipped): the one occurrence after it. Exactly one, so a
	 *    payment settles one occurrence whatever the frequency.
	 *  - With only a $fromDate: the first occurrence on or after it that
	 *    isn't before today.
	 *  - Otherwise: the first occurrence on or after today.
	 *
	 * A one-time item is its date: the anchor, else $fromDate, else today.
	 * It is never rolled forward to a year that nobody entered.
	 *
	 * @param string $frequency Bill frequency
	 * @param int|null $dueDay Day of week (1-7) or day of month (1-31)
	 * @param int|null $dueMonth Month for quarterly/yearly bills
	 * @param string|null $fromDate Base date to calculate from
	 * @param string|null $customPattern JSON pattern for custom frequency
	 * @param bool $forceAdvance Always advance past fromDate (used after payment)
	 * @param string|null $anchorDate The start date (see occurrencesBetween())
	 * @param string|null $today The owner's today, Y-m-d (defaults to the server's)
	 * @return string Next due date in Y-m-d format
	 */
	public function calculateNextDueDate(
		string $frequency,
		?int $dueDay,
		?int $dueMonth,
		?string $fromDate = null,
		?string $customPattern = null,
		bool $forceAdvance = false,
		?string $anchorDate = null,
		?string $today = null,
	): string {
		$today = $today !== null ? $this->day($today) : date('Y-m-d');
		$anchorDate = ($anchorDate === null || $anchorDate === '') ? null : $anchorDate;
		$fromDate = ($fromDate === null || $fromDate === '') ? null : $this->day($fromDate);

		if ($frequency === 'one-time') {
			return $anchorDate !== null ? $this->day($anchorDate) : ($fromDate ?? $today);
		}

		if ($forceAdvance && $fromDate !== null) {
			// Without a start date, a weekly schedule counts on from the
			// occurrence it just closed
			$anchor = $anchorDate ?? (in_array($frequency, ['weekly', 'biweekly'], true) ? $fromDate : null);
			$fromDate = $this->storedOccurrence($frequency, $dueDay, $dueMonth, $fromDate, $anchorDate);
			$next = $this->occurrenceAfter($frequency, $dueDay, $dueMonth, $fromDate, $customPattern, $anchor);
		} elseif ($fromDate !== null) {
			$next = $this->occurrenceOnOrAfter($frequency, $dueDay, $dueMonth, max($fromDate, $today), $customPattern, $anchorDate);
		} else {
			$next = $this->occurrenceOnOrAfter($frequency, $dueDay, $dueMonth, $today, $customPattern, $anchorDate);
		}

		return $next ?? ($fromDate ?? $today);
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
			'semi-monthly' => $amount * 2,
			'monthly' => $amount,
			'quarterly' => $amount / 3,
			'semi-annually' => $amount / 6,
			'yearly' => $amount / 12,
			'one-time' => $amount / 12,
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
		if ($avgIntervalDays >= 170 && $avgIntervalDays <= 200) {
			return 'semi-annually';
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
			'semi-monthly' => 24,
			'monthly' => 12,
			'quarterly' => 4,
			'semi-annually' => 2,
			'yearly' => 1,
			'one-time' => 1,
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
