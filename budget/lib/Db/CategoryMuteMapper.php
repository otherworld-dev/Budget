<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<CategoryMute>
 */
class CategoryMuteMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_cat_mutes', CategoryMute::class);
	}

	/** @return int[] Category ids the user muted from their reports */
	public function findMutedCategoryIds(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('category_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

		$result = $qb->executeQuery();
		$ids = array_map(static fn ($row) => (int)$row['category_id'], $result->fetchAll());
		$result->closeCursor();
		return $ids;
	}

	/** Idempotent: muting an already-muted category is a no-op */
	public function addMute(string $userId, int $categoryId): void {
		if ($this->isMuted($userId, $categoryId)) {
			return;
		}
		$mute = new CategoryMute();
		$mute->setUserId($userId);
		$mute->setCategoryId($categoryId);
		$mute->setCreatedAt(date('Y-m-d H:i:s'));
		$this->insert($mute);
	}

	public function removeMute(string $userId, int $categoryId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	public function isMuted(string $userId, int $categoryId): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$row = $result->fetch();
		$result->closeCursor();
		return $row !== false;
	}

	/** Cleanup when a category is deleted */
	public function deleteByCategoryId(int $categoryId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
