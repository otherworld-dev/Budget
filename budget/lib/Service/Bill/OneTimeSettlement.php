<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

/**
 * Whether a bill or recurring income has already paid (or received) the
 * one-time date an edit puts it on (#399).
 *
 * Switching a paid Custom or monthly bill to One-Time put the occurrence it
 * had paid back on the list as owed, and Mark Paid then paid it a second
 * time. The date is settled when a payment, or the occurrence the last
 * payment settled, lies within three days of it, whatever the schedule was.
 * Further off it is settled only under the schedule the item had: within
 * that schedule's due window, and never after the occurrence the last
 * payment settled. A date after that is a payment of its own, such as the
 * final invoice after the last month paid.
 *
 * Once the item is one-time its old schedule is gone, and the three days
 * are all that is left. Judged with a one-time item's two weeks, a weekly
 * bill the switch had left owing a week after its last payment was marked
 * paid by its next edit, a rename included.
 */
final class OneTimeSettlement {
	/** Days either side of a date a payment still pays it: a weekend or a bank holiday apart */
	public const BESIDE_DAYS = 3;

	/**
	 * @param string $date the one-time date, Y-m-d
	 * @param string[] $payments the days payments were made or recorded on
	 * @param string|null $settled the occurrence the last payment settled, as its undo snapshot names it
	 * @param string|null $before the occurrence the old schedule last settled, when no snapshot names one
	 * @param int|null $window the old schedule's due window in days; null when the item is one-time
	 */
	public static function covers(string $date, array $payments, ?string $settled, ?string $before, ?int $window): bool {
		$days = [];
		foreach ([...$payments, $settled] as $day) {
			if (is_string($day) && $day !== '') {
				$days[] = self::daysApart($day, $date);
			}
		}
		if ($days === []) {
			return false;
		}
		if (min($days) <= self::BESIDE_DAYS) {
			return true;
		}
		$bound = $settled ?? $before;
		if ($window === null || ($bound !== null && $date > $bound)) {
			return false;
		}
		return min($days) <= $window;
	}

	/**
	 * The occurrence before a pending one: the last its schedule settled,
	 * paid or skipped. A weekly or bi-weekly schedule with no start date
	 * counts on from each occurrence it closes (calculateNextDueDate()), so
	 * that one is an interval back. Null when there is none.
	 */
	public static function occurrenceBefore(
		FrequencyCalculator $calculator,
		string $frequency,
		?int $dueDay,
		?int $dueMonth,
		?string $pattern,
		?string $anchor,
		string $pending,
	): ?string {
		if ($frequency === 'one-time') {
			return null;
		}
		$anchor = ($anchor === null || $anchor === '') ? null : $anchor;
		if ($anchor === null && ($frequency === 'weekly' || $frequency === 'biweekly')) {
			return (new \DateTimeImmutable($pending))->modify($frequency === 'weekly' ? '-7 days' : '-14 days')->format('Y-m-d');
		}
		return $calculator->occurrenceBefore($frequency, $dueDay, $dueMonth, $pending, $pattern, $anchor);
	}

	/** Calendar days between two dates, whatever time of day either carries */
	private static function daysApart(string $a, string $b): int {
		return (int)(new \DateTimeImmutable(substr($a, 0, 10)))->diff(new \DateTimeImmutable(substr($b, 0, 10)))->days;
	}
}
