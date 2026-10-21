<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

/**
 * Skips that set a payment's undo snapshot aside (#399).
 *
 * Mark Unpaid (Mark Unreceived on income) restores the dates the snapshot
 * holds, so after a skip it would bring back a date from before the skip,
 * and a skip used to spend the snapshot. Undoing the skip then put the
 * skipped date back, but the payment could no longer be taken back. A skip
 * now leaves the snapshot in place and lists the date it skipped in it:
 * while any is listed the snapshot can't be used, and undoing the skip
 * takes the date off the list again. The snapshot keeps its other keys
 * where they were, so whatever keeps its transaction ids right (a backup
 * restore, a bank row taking a booked row's place) keeps them right while
 * it is set aside.
 *
 * The list is also how the page tells a skipped date from a payment made
 * early (lastOccurrenceSkipped): the dates alone look the same. So a skip
 * with no snapshot to set aside leaves one holding only the dates, which
 * nothing can revert from: it has no previousState (nextExpectedDate on
 * income), names no transaction and isn't carried by a backup.
 */
final class UndoSnapshotSkips {
	/** The dates skipped since the payment, oldest first */
	public const KEY = 'skippedDates';

	/** Whether a skip has set the snapshot aside: the occurrence before the next one was skipped */
	public static function setAside(?string $raw): bool {
		return self::skipped(self::decode($raw)) !== [];
	}

	/** The snapshot with $date skipped; one holding only the date when there was none */
	public static function skip(?string $raw, string $date): string {
		$snapshot = self::decode($raw) ?? [];
		$skipped = self::skipped($snapshot);
		$skipped[] = $date;
		$snapshot[self::KEY] = $skipped;
		return (string)json_encode($snapshot);
	}

	/**
	 * The snapshot once the skip of $date is undone. Putting the bill back on
	 * $date undoes every skip from it on, so the dates from $date on come off
	 * the list, whichever toast's Undo came first. Back in use once none is
	 * left, and null when the dates were all it held. Unchanged when no date
	 * listed is $date or later.
	 */
	public static function unskip(?string $raw, string $date): ?string {
		$snapshot = self::decode($raw);
		$skipped = self::skipped($snapshot);
		$kept = array_values(array_filter($skipped, fn (string $day): bool => $day < $date));
		if ($snapshot === null || count($kept) === count($skipped)) {
			return $raw;
		}
		if ($kept === []) {
			unset($snapshot[self::KEY]);
		} else {
			$snapshot[self::KEY] = $kept;
		}
		return $snapshot === [] ? null : (string)json_encode($snapshot);
	}

	/** @return list<string> */
	private static function skipped(?array $snapshot): array {
		$listed = $snapshot[self::KEY] ?? null;
		return is_array($listed) ? array_values(array_filter($listed, 'is_string')) : [];
	}

	private static function decode(?string $raw): ?array {
		$decoded = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
		return is_array($decoded) ? $decoded : null;
	}
}
