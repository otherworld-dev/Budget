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
		$bills = $this->references('bill_id', true);
		$cleared = $this->setNull('bill_id', array_keys(self::dangling($bills, $this->existingIds('budget_bills', $bills))));

		$links = $this->references('linked_transaction_id', false);
		$cleared += $this->setNull('linked_transaction_id', array_keys(self::dangling($links, $this->existingIds('budget_transactions', $links))));

		return $cleared;
	}

	/**
	 * The references whose target is not among the ids that exist.
	 *
	 * @param array<int, int> $references row id => the id it references
	 * @param int[] $existing the referenced ids that exist
	 * @return array<int, int> row id => the id it references, for the dangling ones
	 */
	public static function dangling(array $references, array $existing): array {
		$exists = array_flip($existing);
		return array_filter($references, fn (int $target): bool => !isset($exists[$target]));
	}

	/**
	 * @return array<int, int> transaction id => the id in $column
	 */
	private function references(string $column, bool $recordedOnly): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', $column)
			->from('budget_transactions')
			->where($qb->expr()->isNotNull($column));
		if ($recordedOnly) {
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('status'),
				$qb->expr()->neq('status', $qb->createNamedParameter('scheduled'))
			));
		}
		$result = $qb->executeQuery();
		$references = [];
		while ($row = $result->fetch()) {
			$references[(int)$row['id']] = (int)$row[$column];
		}
		$result->closeCursor();
		return $references;
	}

	/**
	 * Which of the ids the references name exist in $table.
	 *
	 * @param array<int, int> $references
	 * @return int[]
	 */
	private function existingIds(string $table, array $references): array {
		$existing = [];
		foreach (array_chunk(array_values(array_unique($references)), self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id')
				->from($table)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$existing[] = (int)$row['id'];
			}
			$result->closeCursor();
		}
		return $existing;
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
