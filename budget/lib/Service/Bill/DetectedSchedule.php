<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Bill;

/**
 * The schedule a detected bill, transfer or income is created with, worked
 * out from the dates its payments were actually seen on.
 *
 * Shared by the bill and income detectors so both read a payment history
 * the same way, and so what they propose is a schedule the forms can edit.
 */
class DetectedSchedule {
	/**
	 * The day is an ISO weekday (1-7) for weekly and bi-weekly schedules,
	 * else a day of the month. The month is the one a quarterly, half-yearly
	 * or yearly cycle runs from, and the start date the anchor a weekly or
	 * bi-weekly schedule counts from.
	 *
	 * @param string $frequency A detected frequency
	 * @param string[] $dates Y-m-d dates the payments were seen on
	 * @return array{day: int, month: ?int, startDate: ?string}
	 */
	public static function fromDates(string $frequency, array $dates): array {
		sort($dates);

		if ($frequency === 'weekly' || $frequency === 'biweekly') {
			// Count from a real payment, on the weekday it usually falls on:
			// without an anchor the schedule's week was the creation week, and
			// a day of the month was read as a weekday. A payment that once
			// moved a day (a bank holiday) doesn't move the whole schedule.
			$weekdays = array_map(fn (string $d): int => (int)(new \DateTimeImmutable($d))->format('N'), $dates);
			$counts = array_count_values($weekdays);
			$usual = (int)(new \DateTimeImmutable(end($dates)))->format('N');
			foreach ($counts as $weekday => $count) {
				if ($count > $counts[$usual]) {
					$usual = (int)$weekday;
				}
			}
			$anchor = end($dates);
			foreach (array_reverse($dates) as $date) {
				if ((int)(new \DateTimeImmutable($date))->format('N') === $usual) {
					$anchor = $date;
					break;
				}
			}
			return ['day' => $usual, 'month' => null, 'startDate' => $anchor];
		}

		$day = self::typicalDayOfMonth($dates);
		$month = in_array($frequency, ['quarterly', 'semi-annually', 'yearly'], true)
			? self::cycleMonth(end($dates), $day)
			: null;

		return ['day' => $day, 'month' => $month, 'startDate' => null];
	}

	/**
	 * The day of the month the payments fall on.
	 *
	 * Read on a circle rather than averaged: a payment due on the 1st that is
	 * sometimes brought forward to the 30th or 31st averaged to mid-month.
	 * Days late in the month are also counted back from its end, and when
	 * that view clusters the payments more tightly it is the one used, so the
	 * 30th and the 1st sit side by side. The middle value is taken, so one
	 * odd date doesn't drag the day.
	 */
	private static function typicalDayOfMonth(array $dates): int {
		$plain = [];
		$wrapped = [];
		foreach ($dates as $date) {
			$d = new \DateTimeImmutable($date);
			$day = (int)$d->format('j');
			$plain[] = $day;
			// The last day of the month is 0, the day before it -1
			$wrapped[] = $day > 15 ? $day - (int)$d->format('t') : $day;
		}

		if (self::spread($wrapped) < self::spread($plain)) {
			$middle = self::median($wrapped);
			// 31 falls on the last day of every month, 30 on the day before
			// it in a long month
			return $middle <= 0 ? 31 + $middle : $middle;
		}
		return max(1, min(31, self::median($plain)));
	}

	/**
	 * The month of the payment last seen, taken from the occurrence nearest
	 * to it: a payment due on the 31st that went out on the 1st still
	 * belongs to the month before.
	 */
	private static function cycleMonth(string $lastSeen, int $day): int {
		$seen = new \DateTimeImmutable($lastSeen);
		$first = $seen->modify('first day of this month');
		$best = null;
		$bestDistance = PHP_INT_MAX;
		foreach ([-1, 0, 1] as $shift) {
			$month = $first->modify(($shift >= 0 ? '+' : '') . $shift . ' month');
			$nominal = $month->setDate(
				(int)$month->format('Y'),
				(int)$month->format('n'),
				min($day, (int)$month->format('t'))
			);
			$distance = abs((int)$seen->diff($nominal)->format('%r%a'));
			if ($distance < $bestDistance) {
				$best = $nominal;
				$bestDistance = $distance;
			}
		}
		return (int)$best->format('n');
	}

	private static function spread(array $values): int {
		return max($values) - min($values);
	}

	private static function median(array $values): int {
		sort($values);
		$count = count($values);
		$mid = intdiv($count, 2);
		if ($count % 2 === 1) {
			return $values[$mid];
		}
		return (int)floor(($values[$mid - 1] + $values[$mid]) / 2 + 0.5);
	}
}
