<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

/**
 * Budget months under a custom budget start day.
 *
 * With a start day other than the 1st, a budget period runs from the start
 * day to the day before the next one, so it spans two calendar months. Budget
 * month M is the period CONTAINING the 15th of M: with start day 10, "June"
 * is 10 Jun - 9 Jul; with start day 25, "June" is 25 May - 24 Jun. The
 * frontend names periods the same way (getPeriodDateRange with the 15th as
 * reference, budgetMonthForCycle).
 *
 * Every budget surface (Budget view, dashboard, alerts, envelope carryover)
 * decides which month a period is, and which month is "now", through here so
 * they agree on which month's budgets apply.
 */
final class BudgetPeriod {

	/**
	 * The dates [start, end] (Y-m-d) budget month $month covers.
	 *
	 * @return array{0: string, 1: string}
	 */
	public static function range(string $month, int $startDay): array {
		$monthStart = \DateTime::createFromFormat('!Y-m-d', $month . '-01');
		if ($startDay <= 1) {
			return [$monthStart->format('Y-m-d'), $monthStart->format('Y-m-t')];
		}

		$effectiveStartDay = min($startDay, (int)$monthStart->format('t'));

		if ($effectiveStartDay <= 15) {
			// Starts in $month, ends the day before next month's start day
			$start = self::clampedDay($monthStart, $startDay)->format('Y-m-d');
			$next = (clone $monthStart)->modify('first day of next month');
			$end = self::clampedDay($next, $startDay)->modify('-1 day')->format('Y-m-d');
		} else {
			// Starts in the previous month, ends the day before $month's start day
			$prev = (clone $monthStart)->modify('first day of last month');
			$start = self::clampedDay($prev, $startDay)->format('Y-m-d');
			$end = self::clampedDay($monthStart, $startDay)->modify('-1 day')->format('Y-m-d');
		}

		return [$start, $end];
	}

	/**
	 * The budget month (Y-m) a period [start, end] belongs to: the month
	 * holding its 15th. A period holds exactly one.
	 */
	public static function monthOf(string $start, string $end): string {
		return (int)substr($start, 8, 2) <= 15 ? substr($start, 0, 7) : substr($end, 0, 7);
	}

	/**
	 * The budget month (Y-m) whose period contains $date (Y-m-d).
	 */
	public static function monthContaining(string $date, int $startDay): string {
		$month = \DateTime::createFromFormat('!Y-m-d', substr($date, 0, 7) . '-01');
		// A period containing $date is named after $date's month or one
		// either side of it.
		foreach (['+0 month', '+1 month', '-1 month'] as $shift) {
			$candidate = (clone $month)->modify($shift)->format('Y-m');
			[$start, $end] = self::range($candidate, $startDay);
			if ($date >= $start && $date <= $end) {
				return $candidate;
			}
		}
		// Unreachable: consecutive periods tile the calendar
		return substr($date, 0, 7);
	}

	private static function clampedDay(\DateTime $monthStart, int $startDay): \DateTime {
		$day = min($startDay, (int)$monthStart->format('t'));
		return (clone $monthStart)->setDate(
			(int)$monthStart->format('Y'),
			(int)$monthStart->format('n'),
			$day
		);
	}
}
