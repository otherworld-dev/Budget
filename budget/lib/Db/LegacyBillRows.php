<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Bill rows that 2.54.0 and older left in a state 3.0 repairs. The upgrade
 * repairs them once (migrations 109 and 114), and restoring a backup made by
 * those versions repairs the rows it brings back (MigrationService), so a
 * restored user ends up like an upgraded one. The rules live here so the two
 * can't drift apart.
 */
final class LegacyBillRows {
	private const CHUNK = 500;

	/**
	 * A bill's pre-booked row that is still the occurrence it owes, cleared
	 * by the scheduled job (migration 109).
	 *
	 * The job used to clear a bill's row on its due date while the bill
	 * stayed unpaid, so the money was booked and then booked again when the
	 * bill was paid. A row qualifies only when the app generated it for an
	 * active bill and it sits exactly on that bill's next due date, the
	 * occurrence the bill still owes, and its snapshot doesn't name it as the
	 * payment: a weekly bill paid a week late is paid on its next due date.
	 * Reconciled rows stay as they are.
	 *
	 * A row that is also on the bill's last paid date is a payment unless the
	 * snapshot names it as the pending row: 2.54.0 recorded late payments
	 * (the missed-payment row and Record transaction) on the paid date
	 * without ever naming them in the snapshot, and Skip wipes the snapshot
	 * altogether. When nothing vouches for it as a placeholder, it is left
	 * booked.
	 *
	 * @param int[]|null $accountIds only rows in these accounts; null for every account
	 * @return array<int, int> transaction id => account id
	 */
	public static function unpaidPlaceholders(IDBConnection $db, ?array $accountIds = null): array {
		if ($accountIds === []) {
			return [];
		}
		$payments = array_flip(BillPaymentRows::ids($db));
		$found = [];
		foreach ($accountIds === null ? [null] : array_chunk($accountIds, self::CHUNK) as $chunk) {
			$qb = $db->getQueryBuilder();
			$qb->select('t.id', 't.account_id', 't.date', 'b.last_paid_date', 'b.paid_undo_state')
				->from('budget_transactions', 't')
				->innerJoin('t', 'budget_bills', 'b', $qb->expr()->eq('b.id', 't.bill_id'))
				->where($qb->expr()->eq('t.status', $qb->createNamedParameter('cleared')))
				->andWhere($qb->expr()->eq('t.date', 'b.next_due_date'))
				->andWhere($qb->expr()->eq('b.is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
				->andWhere($qb->expr()->orX(
					$qb->expr()->isNull('t.reconciled'),
					$qb->expr()->eq('t.reconciled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
				))
				->andWhere($qb->expr()->orX(
					$qb->expr()->like('t.notes', $qb->createNamedParameter('Auto-generated from bill:%')),
					$qb->expr()->like('t.notes', $qb->createNamedParameter('Auto-generated transfer:%'))
				));
			if ($chunk !== null) {
				$qb->andWhere($qb->expr()->in('t.account_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			}
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				if (isset($payments[(int)$row['id']])) {
					continue;
				}
				if (self::onLastPaidDate($row) && !self::namedAsPending($row)) {
					continue;
				}
				$found[(int)$row['id']] = (int)$row['account_id'];
			}
			$result->closeCursor();
		}
		return $found;
	}

	/**
	 * A bill payment an old Repair run switched to scheduled (migration 114).
	 *
	 * Repair's "future cleared" fix switched any cleared row dated after the
	 * server's today to scheduled, bill payments included. A bill's scheduled
	 * row is its placeholder to everything else: the scheduled job never
	 * clears it, and the next Mark Paid, Skip or Mark Unpaid deleted it as
	 * one, taking a real payment with it. Only a row the bill's own Mark
	 * Unpaid snapshot names as its payment qualifies; one no snapshot vouches
	 * for could be a placeholder, and is left as it is.
	 *
	 * @param int[]|null $accountIds only rows in these accounts; null for every account
	 * @return array<int, int> transaction id => account id
	 */
	public static function rescheduledPayments(IDBConnection $db, ?array $accountIds = null): array {
		if ($accountIds === []) {
			return [];
		}
		$inScope = $accountIds === null ? null : array_flip($accountIds);
		$found = [];
		foreach (array_chunk(BillPaymentRows::ids($db), self::CHUNK) as $chunk) {
			$qb = $db->getQueryBuilder();
			$qb->select('id', 'account_id')
				->from('budget_transactions')
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->andWhere($qb->expr()->eq('status', $qb->createNamedParameter('scheduled')));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				if ($inScope === null || isset($inScope[(int)$row['account_id']])) {
					$found[(int)$row['id']] = (int)$row['account_id'];
				}
			}
			$result->closeCursor();
		}
		return $found;
	}

	/**
	 * @param int[] $ids transaction ids
	 */
	public static function setStatus(IDBConnection $db, array $ids, string $status): void {
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$update = $db->getQueryBuilder();
			$update->update('budget_transactions')
				->set('status', $update->createNamedParameter($status))
				->where($update->expr()->in('id', $update->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$update->executeStatement();
		}
	}

	private static function onLastPaidDate(array $row): bool {
		if ($row['last_paid_date'] === null || $row['last_paid_date'] === '') {
			return false;
		}
		return substr((string)$row['date'], 0, 10) === substr((string)$row['last_paid_date'], 0, 10);
	}

	private static function namedAsPending(array $row): bool {
		$snapshot = $row['paid_undo_state'] === null ? null : json_decode((string)$row['paid_undo_state'], true);
		if (!is_array($snapshot) || !is_array($snapshot['scheduledTransactionIds'] ?? null)) {
			return false;
		}
		foreach ($snapshot['scheduledTransactionIds'] as $id) {
			if (is_numeric($id) && (int)$id === (int)$row['id']) {
				return true;
			}
		}
		return false;
	}
}
