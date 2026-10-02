<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<PensionRecurringContribution>
 */
class PensionRecurringContributionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_pen_recur', PensionRecurringContribution::class);
	}

	/**
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): PensionRecurringContribution {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntity($qb);
	}

	/**
	 * All schedules for a pension, soonest-due first.
	 *
	 * @return PensionRecurringContribution[]
	 */
	public function findByPension(int $pensionId, string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('next_due_date', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * @return PensionRecurringContribution[]
	 */
	public function findActive(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->orderBy('next_due_date', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Active, auto-post-enabled schedules whose next due date has arrived.
	 *
	 * @param string|null $today the user's date (Y-m-d); the server's if not given
	 * @return PensionRecurringContribution[]
	 */
	public function findDueForAutoPost(string $userId, ?string $today = null): array {
		$today ??= date('Y-m-d');

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->eq('auto_post_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
			->andWhere($qb->expr()->lte('next_due_date', $qb->createNamedParameter($today)));

		return $this->findEntities($qb);
	}

	/**
	 * Take a deleted account off the schedules it funded, whoever's they are
	 * (a shared account can fund another user's schedule). Auto-post goes off
	 * with it: the schedule now posts with no bank leg, and only when its
	 * owner chooses to.
	 *
	 * @return int Number of schedules changed
	 */
	public function detachSourceAccount(int $accountId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('source_account_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
			->set('auto_post_enabled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
			->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
			->where($qb->expr()->eq('source_account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * Delete all schedules for a pension.
	 */
	public function deleteByPension(int $pensionId, string $userId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$qb->executeStatement();
	}

	/**
	 * Delete all schedules for a user.
	 *
	 * @return int Number of deleted rows
	 */
	public function deleteAll(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}
}
