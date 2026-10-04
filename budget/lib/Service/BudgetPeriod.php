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

	/**
	 * Budget amounts of any periods as one monthly total, by the yearly
	 * ratios the Budget page's summary uses (formatters.prorateBudget): each
	 * amount turned yearly, added, and the sum divided by 12 once, so the
	 * total is exact. Turned monthly one by one and cut at six places, a
	 * weekly 100 and a yearly 27.50 came to 435.624999 instead of 435.625,
	 * a penny below the page once rounded.
	 *
	 * @param list<array{0: string|float, 1: string}> $budgets [amount, period] pairs
	 * @return string the monthly total, at ten places
	 */
	public static function monthlyTotal(array $budgets): string {
		return self::totalFor($budgets, 'monthly');
	}

	/**
	 * Budget amounts of any periods as one total for $period, the same way
	 * monthlyTotal() makes a monthly one: a yearly 1,200 and a monthly 100
	 * are 2,400 a year.
	 *
	 * @param list<array{0: string|float, 1: string}> $budgets [amount, period] pairs
	 * @return string the total, at ten places
	 */
	public static function totalFor(array $budgets, string $period): string {
		$yearly = '0';
		foreach ($budgets as [$amount, $from]) {
			$yearly = MoneyCalculator::add($yearly, MoneyCalculator::multiply($amount, self::perYear($from), 10), 10);
		}
		return MoneyCalculator::divide($yearly, self::perYear($period), 10);
	}

	/**
	 * The dates a quarterly or yearly budget has run by the end of budget
	 * month $month: from the start of the first budget month of its calendar
	 * quarter or year to the end of $month, so with a start day it is made
	 * of whole budget months. Null for a weekly or monthly budget.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	public static function toDateRange(string $period, string $month, int $startDay): ?array {
		$monthNumber = (int)substr($month, 5, 2);
		$first = match ($period) {
			'yearly' => 1,
			'quarterly' => intdiv($monthNumber - 1, 3) * 3 + 1,
			default => null,
		};
		if ($first === null) {
			return null;
		}
		return [
			self::range(sprintf('%s-%02d', substr($month, 0, 4), $first), $startDay)[0],
			self::range($month, $startDay)[1],
		];
	}

	/** How many of a budget period make a year; an unknown one is monthly. */
	private static function perYear(string $period): string {
		return ['weekly' => '52', 'monthly' => '12', 'quarterly' => '4', 'yearly' => '1'][$period] ?? '12';
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
