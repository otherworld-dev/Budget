<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Give every one-time bill created before 2.49.0 its due date (#333).
 *
 * A one-time bill's date lives in start_date since #375, and the form, the
 * list and the Bills Calendar read it from there. Bills created earlier only
 * ever had a day and a month; while unpaid their full date sat in
 * next_due_date, and marking one paid cleared that - so a paid invoice read
 * "No due date" in the list and opened with an empty Due Date.
 *
 * An unpaid bill's date is its next_due_date. For a paid one the day and
 * month are still there and the year is the one that puts the due date
 * nearest to the day it was paid: an invoice due on the 31st and paid on
 * the 30th is a day early, not a year late. markPaid() now keeps the date
 * itself, so this is a one-off for the rows it already cleared.
 */
class Version001000104Date20260916 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		return null;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_bills') || !$schema->getTable('budget_bills')->hasColumn('start_date')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'due_day', 'due_month', 'next_due_date', 'last_paid_date', 'start_date')
			->from('budget_bills')
			->where($qb->expr()->eq('frequency', $qb->createNamedParameter('one-time')));

		$result = $qb->executeQuery();
		$dates = [];
		while ($row = $result->fetch()) {
			if ($row['start_date'] !== null && $row['start_date'] !== '') {
				continue;
			}
			$date = self::dueDateFor(
				$row['due_day'] === null ? null : (int)$row['due_day'],
				$row['due_month'] === null ? null : (int)$row['due_month'],
				$row['next_due_date'] === null ? null : (string)$row['next_due_date'],
				$row['last_paid_date'] === null ? null : (string)$row['last_paid_date']
			);
			if ($date !== null) {
				$dates[(int)$row['id']] = $date;
			}
		}
		$result->closeCursor();

		foreach ($dates as $id => $date) {
			$update = $this->db->getQueryBuilder();
			$update->update('budget_bills')
				->set('start_date', $update->createNamedParameter($date))
				->where($update->expr()->eq('id', $update->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
			$update->executeStatement();
		}

		if (count($dates) > 0) {
			$output->info('Restored the due date of ' . count($dates) . ' one-time bill(s)');
		}
	}

	/**
	 * The date a one-time bill without a start_date was due, or null when
	 * nothing on the row says.
	 */
	public static function dueDateFor(?int $dueDay, ?int $dueMonth, ?string $nextDueDate, ?string $lastPaidDate): ?string {
		if ($nextDueDate !== null && $nextDueDate !== '') {
			return substr($nextDueDate, 0, 10);
		}
		if ($dueMonth === null || $dueMonth < 1 || $dueMonth > 12 || $lastPaidDate === null || $lastPaidDate === '') {
			return null;
		}

		$paidOn = new \DateTimeImmutable(substr($lastPaidDate, 0, 10));
		$paidYear = (int)$paidOn->format('Y');
		$best = null;
		foreach ([$paidYear - 1, $paidYear, $paidYear + 1] as $year) {
			$daysInMonth = (int)date('t', mktime(0, 0, 0, $dueMonth, 1, $year));
			$day = min(max($dueDay ?? 1, 1), $daysInMonth);
			$candidate = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $dueMonth, $day));
			$distance = $paidOn->diff($candidate)->days;
			if ($best === null || $distance < $best[0]) {
				$best = [$distance, $candidate->format('Y-m-d')];
			}
		}

		return $best[1];
	}
}
