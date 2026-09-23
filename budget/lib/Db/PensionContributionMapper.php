<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<PensionContribution>
 */
class PensionContributionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_pen_contribs', PensionContribution::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): PensionContribution {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * Get all contributions for a pension, ordered by date descending.
	 *
	 * @return PensionContribution[]
	 */
	public function findByPension(int $pensionId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('date', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Get contributions for a pension within a date range.
	 *
	 * @return PensionContribution[]
	 */
	public function findByPensionInRange(int $pensionId, string $userId, string $startDate, string $endDate): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($startDate)))
			->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($endDate)))
			->orderBy('date', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Contributions for a pension that are linked to a bank transaction (#304).
	 *
	 * @return PensionContribution[]
	 */
	public function findLinkedByPension(int $pensionId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNotNull('transaction_id'));

		return $this->findEntities($qb);
	}

	/**
	 * Break the link to a bank transaction (when that transaction is deleted),
	 * leaving the contribution as a plain manual record (#304 deletion safety).
	 */
	public function unlinkByTransaction(int $transactionId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('transaction_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('source_account_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)));

		$qb->executeStatement();
	}

	/**
	 * Get total contributions for a pension.
	 */
	public function getTotalByPension(int $pensionId, string $userId): float {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->sum('amount'))
			->from($this->getTableName())
			->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			// Exclude drawdowns (#304/withdrawals); pre-existing rows have NULL kind.
			->andWhere($qb->expr()->orX(
				$qb->expr()->isNull('kind'),
				$qb->expr()->neq('kind', $qb->createNamedParameter(PensionContribution::KIND_WITHDRAWAL))
			));

		$result = $qb->executeQuery();
		$sum = $result->fetchOne();
		$result->closeCursor();

		return (float)($sum ?? 0);
	}

	/**
	 * Delete all contributions for a pension.
	 */
	public function deleteByPension(int $pensionId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}

	/**
	 * Delete all pension contributions for a user
	 *
	 * @param string $userId
	 * @return int Number of deleted rows
	 */
	public function deleteAll(string $userId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}
}
