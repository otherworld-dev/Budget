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
 * while any is listed the snapshot can't be used, and undoing the last skip
 * takes its date off the list again. The snapshot keeps its other keys
 * where they were, so whatever keeps its transaction ids right (a backup
 * restore, a bank row taking a booked row's place) keeps them right while
 * it is set aside.
 */
final class UndoSnapshotSkips {
	/** The dates skipped since the payment, oldest first */
	public const KEY = 'skippedDates';

	/** Whether a skip has set the snapshot aside */
	public static function setAside(?string $raw): bool {
		$snapshot = self::decode($raw);
		return $snapshot !== null && is_array($snapshot[self::KEY] ?? null) && $snapshot[self::KEY] !== [];
	}

	/**
	 * The snapshot with $date skipped, or null when there is no snapshot to
	 * keep
	 */
	public static function skip(?string $raw, string $date): ?string {
		$snapshot = self::decode($raw);
		if ($snapshot === null) {
			return null;
		}
		$skipped = is_array($snapshot[self::KEY] ?? null) ? array_values($snapshot[self::KEY]) : [];
		$skipped[] = $date;
		$snapshot[self::KEY] = $skipped;
		return json_encode($snapshot);
	}

	/**
	 * The snapshot once the skip of $date is undone: back in use when it was
	 * the only one. Unchanged when $date wasn't the last date skipped.
	 */
	public static function unskip(?string $raw, string $date): ?string {
		$snapshot = self::decode($raw);
		$skipped = $snapshot !== null && is_array($snapshot[self::KEY] ?? null) ? array_values($snapshot[self::KEY]) : [];
		if ($skipped === [] || end($skipped) !== $date) {
			return $raw;
		}
		array_pop($skipped);
		if ($skipped === []) {
			unset($snapshot[self::KEY]);
		} else {
			$snapshot[self::KEY] = $skipped;
		}
		return json_encode($snapshot);
	}

	private static function decode(?string $raw): ?array {
		$decoded = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
		return is_array($decoded) ? $decoded : null;
	}
}
