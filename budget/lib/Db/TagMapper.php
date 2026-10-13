<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Tag>
 */
class TagMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_tags', Tag::class);
	}

	/**
	 * Find a tag by ID with user validation.
	 * Handles both category tags (via tag_set -> category) and global tags (via user_id).
	 *
	 * @throws DoesNotExistException
	 */
	public function find(int $id, string $userId): Tag {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.*')
			->from($this->getTableName(), 't')
			->leftJoin('t', 'budget_tag_sets', 'ts', 't.tag_set_id = ts.id')
			->leftJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
			->where($qb->expr()->eq('t.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere(
				$qb->expr()->orX(
					// Category tag: validate via category ownership
					$qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)),
					// Global tag: validate via direct user_id
					$qb->expr()->andX(
						$qb->expr()->isNull('t.tag_set_id'),
						$qb->expr()->eq('t.user_id', $qb->createNamedParameter($userId))
					)
				)
			);

		return $this->findEntity($qb);
	}

	/**
	 * Find all global tags for a user (tags without a tag set)
	 *
	 * @return Tag[]
	 */
	public function findGlobal(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->isNull('tag_set_id'))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Check if a global tag name already exists for this user
	 */
	public function globalNameExists(string $userId, string $name, ?int $excludeId = null): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->isNull('tag_set_id'))
			->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq($qb->createFunction('LOWER(name)'), $qb->createNamedParameter(strtolower($name))));

		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	/**
	 * Find all tags for a specific tag set
	 *
	 * @return Tag[]
	 */
	public function findByTagSet(int $tagSetId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('tag_set_id', $qb->createNamedParameter($tagSetId, IQueryBuilder::PARAM_INT)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('name', 'ASC');

		return $this->findEntities($qb);
	}

	/**
	 * Find all tags for multiple tag sets in a single query (batch, avoids N+1)
	 *
	 * @param int[] $tagSetIds
	 * @return array<int, Tag[]> tagSetId => Tag[]
	 */
	public function findByTagSets(array $tagSetIds): array {
		if (empty($tagSetIds)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('tag_set_id', $qb->createNamedParameter($tagSetIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('sort_order', 'ASC')
			->addOrderBy('name', 'ASC');

		$tags = $this->findEntities($qb);

		// Group tags by tag set ID
		$result = [];
		foreach ($tags as $tag) {
			$tagSetId = $tag->getTagSetId();
			if (!isset($result[$tagSetId])) {
				$result[$tagSetId] = [];
			}
			$result[$tagSetId][] = $tag;
		}

		return $result;
	}

	/**
	 * Check if a tag name already exists within a tag set
	 *
	 * @param int|null $excludeId Tag ID to exclude (for updates)
	 */
	public function nameExists(int $tagSetId, string $name, ?int $excludeId = null): bool {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->createFunction('COUNT(*)'))
			->from($this->getTableName())
			->where($qb->expr()->eq('tag_set_id', $qb->createNamedParameter($tagSetId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq($qb->createFunction('LOWER(name)'), $qb->createNamedParameter(strtolower($name))));

		if ($excludeId !== null) {
			$qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
		}

		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();

		return $count > 0;
	}

	/**
	 * Find multiple tags by IDs in a single query
	 *
	 * @param int[] $ids
	 * @return array<int, Tag> tagId => Tag
	 */
	public function findByIds(array $ids): array {
		if (empty($ids)) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

		$entities = $this->findEntities($qb);

		// Index by ID for quick lookup
		$result = [];
		foreach ($entities as $entity) {
			$result[$entity->getId()] = $entity;
		}

		return $result;
	}

	/**
	 * Tags with what the public API says about each (discussion 420): its tag
	 * set's name, the category that tag set belongs to, and its owner.
	 *
	 * Not scoped to a user: the caller already knows the tags may be seen,
	 * because they sit on transactions it may see. A tag on a transaction in
	 * a shared account is its owner's, and no user-scoped lookup finds it.
	 *
	 * @param int[] $ids
	 * @return array<int, array<string, mixed>> tag id => detail row
	 */
	public function findDetailedByIds(array $ids): array {
		$ids = array_values(array_unique(array_map('intval', $ids)));
		$rows = [];
		foreach (array_chunk($ids, 500) as $chunk) {
			$qb = $this->detailQuery();
			$qb->where($qb->expr()->in('t.id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->detailRows($qb) as $row) {
				$rows[$row['id']] = $row;
			}
		}

		return $rows;
	}

	/**
	 * Every tag a user owns, global and on their categories, in the order
	 * compareDetails() gives.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findDetailedForUser(string $userId): array {
		$qb = $this->detailQuery();
		$qb->where($qb->expr()->orX(
			$qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)),
			$qb->expr()->andX(
				$qb->expr()->isNull('t.tag_set_id'),
				$qb->expr()->eq('t.user_id', $qb->createNamedParameter($userId))
			)
		));

		$rows = $this->detailRows($qb);
		usort($rows, [self::class, 'compareDetails']);

		return $rows;
	}

	/**
	 * The order tags are listed in: global tags first, then each tag set in
	 * its own order, and within either the order set in the web UI, then by
	 * name.
	 *
	 * @param array<string, mixed> $a
	 * @param array<string, mixed> $b
	 */
	public static function compareDetails(array $a, array $b): int {
		$key = static fn (array $row): array => [
			$row['tagSetId'] !== null,
			(int)$row['tagSetSortOrder'],
			mb_strtolower((string)$row['tagSetName']),
			(int)$row['tagSetId'],
			(int)$row['sortOrder'],
			mb_strtolower((string)$row['name']),
			(int)$row['id'],
		];

		return $key($a) <=> $key($b);
	}

	private function detailQuery(): IQueryBuilder {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.name', 't.color', 't.hidden', 't.sort_order', 't.tag_set_id', 't.user_id', 'ts.category_id')
			->selectAlias('ts.name', 'tag_set_name')
			->selectAlias('ts.sort_order', 'tag_set_sort_order')
			->selectAlias('c.user_id', 'category_user_id')
			->from($this->getTableName(), 't')
			->leftJoin('t', 'budget_tag_sets', 'ts', $qb->expr()->eq('t.tag_set_id', 'ts.id'))
			->leftJoin('ts', 'budget_categories', 'c', $qb->expr()->eq('ts.category_id', 'c.id'));

		return $qb;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function detailRows(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$tagSetId = $row['tag_set_id'] !== null ? (int)$row['tag_set_id'] : null;
			$rows[] = [
				'id' => (int)$row['id'],
				'name' => (string)$row['name'],
				'color' => ($row['color'] ?? '') !== '' ? (string)$row['color'] : null,
				'hidden' => (bool)$row['hidden'],
				'sortOrder' => (int)$row['sort_order'],
				'tagSetId' => $tagSetId,
				'tagSetName' => $tagSetId !== null ? $row['tag_set_name'] : null,
				'tagSetSortOrder' => (int)($row['tag_set_sort_order'] ?? 0),
				'categoryId' => $tagSetId !== null && $row['category_id'] !== null ? (int)$row['category_id'] : null,
				// A category tag has no user of its own: it is its category's
				// owner's. A global tag carries its user.
				'userId' => $tagSetId !== null ? ($row['category_user_id'] ?? $row['user_id']) : $row['user_id'],
			];
		}
		$result->closeCursor();

		return $rows;
	}

	/**
	 * Delete all tags for a user (via tag sets and global tags)
	 *
	 * @param string $userId
	 * @return int Number of deleted rows
	 */
	public function deleteAll(string $userId): int {
		$qb = $this->db->getQueryBuilder();

		// Get all tag IDs for this user (both category and global tags)
		$qb->select('t.id')
			->from($this->getTableName(), 't')
			->leftJoin('t', 'budget_tag_sets', 'ts', 't.tag_set_id = ts.id')
			->leftJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
			->where(
				$qb->expr()->orX(
					$qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)),
					$qb->expr()->andX(
						$qb->expr()->isNull('t.tag_set_id'),
						$qb->expr()->eq('t.user_id', $qb->createNamedParameter($userId))
					)
				)
			);

		$result = $qb->executeQuery();
		$tagIds = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		if (empty($tagIds)) {
			return 0;
		}

		// Delete tags (cascade handles transaction_tags)
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->in('id', $qb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)));

		return $qb->executeStatement();
	}
}
