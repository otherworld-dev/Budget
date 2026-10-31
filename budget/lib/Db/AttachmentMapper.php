<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Attachment>
 */
class AttachmentMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_attachments', Attachment::class);
	}

	/**
	 * @throws \OCP\AppFramework\Db\DoesNotExistException
	 */
	public function find(int $id, string $userId): Attachment {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * @return Attachment[]
	 */
	public function findByTransaction(int $transactionId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('created_at', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Attachment counts per transaction for one user (for list badges).
	 *
	 * @return array<int, int> transactionId => count
	 */
	public function countsByUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('transaction_id')
			->selectAlias($qb->func()->count('id'), 'cnt')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->groupBy('transaction_id');

		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(int)$row['transaction_id']] = (int)$row['cnt'];
		}
		$result->closeCursor();

		return $counts;
	}

	/**
	 * Whether each of these transactions has a receipt, for the v1 API's
	 * has_receipt (#455). The same rule as GET /transactions/{id}/receipts,
	 * which lists findByTransaction() once find() has proved the caller owns
	 * the transaction's account: only transactions in the user's own
	 * accounts get a key, true when one of the user's attachment rows points
	 * at it. A transaction in an account shared with them gets none, as that
	 * endpoint is a 404 for them.
	 *
	 * One query for a page of rows, never one per row; ids are chunked at
	 * 500 because old SQLite builds cap bound variables at 999.
	 *
	 * @param int[] $transactionIds
	 * @return array<int, bool> transaction id => has a receipt
	 */
	public function receiptPresence(array $transactionIds, string $userId): array {
		$ids = array_values(array_unique(array_map('intval', $transactionIds)));
		$presence = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('t.id')
				->selectAlias($qb->func()->count('att.id'), 'cnt')
				->from('budget_transactions', 't')
				->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->andX(
					$qb->expr()->eq('t.account_id', 'a.id'),
					$qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId))
				))
				->leftJoin('t', $this->getTableName(), 'att', $qb->expr()->andX(
					$qb->expr()->eq('att.transaction_id', 't.id'),
					$qb->expr()->eq('att.user_id', $qb->createNamedParameter($userId))
				))
				->where($qb->expr()->in('t.id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
				->groupBy('t.id');

			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$presence[(int)$row['id']] = (int)$row['cnt'] > 0;
			}
			$result->closeCursor();
		}

		return $presence;
	}

	public function deleteByTransaction(int $transactionId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		$qb->executeStatement();
	}

	/**
	 * Move every attachment of one transaction, whoever added it, onto
	 * another: the bank's own row of a payment taking the place of the row
	 * the app booked for it, in the same account.
	 *
	 * @return int how many attachments moved
	 */
	public function moveToTransaction(int $fromTransactionId, int $toTransactionId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('transaction_id', $qb->createNamedParameter($toTransactionId, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($fromTransactionId, IQueryBuilder::PARAM_INT)));
		return $qb->executeStatement();
	}

	/**
	 * Delete all attachment rows for a user (factory reset).
	 */
	public function deleteAll(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $qb->executeStatement();
	}
}
