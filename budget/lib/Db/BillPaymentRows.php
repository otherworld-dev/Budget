<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\IDBConnection;

/**
 * The rows each bill's Mark Unpaid snapshot names as its last payment: the
 * rows the payment created and the bank row it linked. The pending row for
 * the next occurrence is listed apart (scheduledTransactionIds), so it is
 * never among them.
 *
 * For one-off data repairs that must tell a payment from a placeholder:
 * both carry the bill's id, and their dates can coincide (a weekly bill
 * paid a week late is paid on its next due date).
 */
final class BillPaymentRows {
	/**
	 * @return int[] transaction ids
	 */
	public static function ids(IDBConnection $db): array {
		$qb = $db->getQueryBuilder();
		$qb->select('paid_undo_state')
			->from('budget_bills')
			->where($qb->expr()->isNotNull('paid_undo_state'));
		$result = $qb->executeQuery();

		$ids = [];
		while ($row = $result->fetch()) {
			$snapshot = json_decode((string)$row['paid_undo_state'], true);
			if (!is_array($snapshot)) {
				continue;
			}
			$named = is_array($snapshot['createdTransactionIds'] ?? null) ? array_values($snapshot['createdTransactionIds']) : [];
			$named[] = $snapshot['linkedTransactionId'] ?? null;
			foreach ($named as $id) {
				if (is_numeric($id) && (int)$id > 0) {
					$ids[(int)$id] = true;
				}
			}
		}
		$result->closeCursor();

		return array_keys($ids);
	}
}
