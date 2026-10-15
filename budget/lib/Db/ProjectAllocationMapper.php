<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ProjectAllocation>
 */
class ProjectAllocationMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_project_allocs', ProjectAllocation::class);
	}

	/**
	 * @return ProjectAllocation[]
	 */
	public function findByProject(int $projectId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * The user's allocations on any of these categories. Guards category deletion.
	 *
	 * @param int[] $categoryIds
	 * @return ProjectAllocation[]
	 */
	public function findByCategoryIds(array $categoryIds, string $userId): array {
		if (empty($categoryIds)) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('category_id', $qb->createNamedParameter($categoryIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		return $this->findEntities($qb);
	}

	/**
	 * @return int Number of deleted rows
	 */
	public function deleteByProject(int $projectId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * @return int Number of deleted rows
	 */
	public function deleteAll(string $userId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}
}
