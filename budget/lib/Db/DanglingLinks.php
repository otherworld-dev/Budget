<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Transactions still pointing at a bill or a transaction that no longer
 * exists (#399).
 *
 * Deleting a bill used to leave its payments carrying its id, and versions
 * before 2.41 deleted one side of a transfer without letting the other go.
 * Both are fixed (TransactionService::detachBillPayments(), and
 * deleteWithChildren() unlinking the other side), but rows they left still
 * name ids nothing can resolve: a payment that can't be matched to a bill
 * set up in its place, a transfer side that can't be linked again.
 */
class DanglingLinks {
	private const CHUNK = 500;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	/**
	 * Clear every such reference: bill_id where no bill has that id, and
	 * linked_transaction_id where no transaction has it.
	 *
	 * A pending row keeps its dead bill id. The scheduled job leaves a bill's
	 * rows to the bill, so a placeholder left behind by a deleted bill has
	 * never been booked; without the id the job would book it on its date.
	 *
	 * @return int how many references were cleared
	 */
	public function clear(): int {
		$cleared = $this->setNull('bill_id', $this->danglingRows('bill_id', 'budget_bills', true));
		$cleared += $this->setNull('linked_transaction_id', $this->danglingRows('linked_transaction_id', 'budget_transactions', false));

		return $cleared;
	}

	/**
	 * The transactions whose $column names a row $table doesn't have. The
	 * database matches each reference to the row it names, so only the few
	 * left dangling are read, not every reference on the server.
	 *
	 * @param bool $recordedOnly leave pending rows out
	 * @return int[] transaction ids
	 */
	private function danglingRows(string $column, string $table, bool $recordedOnly): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id')
			->from('budget_transactions', 't')
			->leftJoin('t', $table, 'r', $qb->expr()->eq('r.id', 't.' . $column))
			->where($qb->expr()->isNotNull('t.' . $column))
			->andWhere($qb->expr()->isNull('r.id'));
		if ($recordedOnly) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('t.status'),
				$qb->expr()->neq('t.status', $qb->createNamedParameter('scheduled'))
			));
		}
		$result = $qb->executeQuery();
		$ids = [];
		while ($row = $result->fetch()) {
			$ids[] = (int)$row['id'];
		}
		$result->closeCursor();
		return $ids;
	}

	/**
	 * @param int[] $ids transaction ids
	 */
	private function setNull(string $column, array $ids): int {
		$cleared = 0;
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('budget_transactions')
				->set($column, $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$cleared += $qb->executeStatement();
		}
		return $cleared;
	}
}
