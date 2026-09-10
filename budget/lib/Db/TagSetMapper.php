<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TagSet>
 */
class TagSetMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_tag_sets', TagSet::class);
    }

    /**
     * Find a tag set by ID with user validation via category ownership
     *
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): TagSet {
        $qb = $this->db->getQueryBuilder();
        $qb->select('ts.*')
            ->from($this->getTableName(), 'ts')
            ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
            ->where($qb->expr()->eq('ts.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * Find a tag set by ID without user scoping (for shared-category owner
     * resolution — the caller checks access separately).
     *
     * @throws DoesNotExistException
     */
    public function findById(int $id): TagSet {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * Find all tag sets for a specific category
     *
     * @return TagSet[]
     */
    public function findByCategory(int $categoryId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('ts.*')
            ->from($this->getTableName(), 'ts')
            ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
            ->where($qb->expr()->eq('ts.category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('ts.sort_order', 'ASC')
            ->addOrderBy('ts.name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Tag sets for a set of categories, each carrying its category's name.
     *
     * The bulk tag picker groups by "Groceries -> Store", so it needs the
     * category name alongside the set; findByCategory returns entities, which
     * have no room for it. Categories are chunked at 500 for the same reason
     * as every other bulk query -- a selection can span many of them and old
     * SQLite builds cap bound variables at 999.
     *
     * @param int[] $categoryIds
     * @return array<int, array{id: int, name: string, categoryId: int, categoryName: string}>
     */
    public function findByCategoriesWithNames(array $categoryIds, string $userId): array {
        if (empty($categoryIds)) {
            return [];
        }

        $sets = [];
        foreach (array_chunk($categoryIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('ts.id', 'ts.name', 'ts.category_id')
                ->selectAlias('c.name', 'category_name')
                ->from($this->getTableName(), 'ts')
                ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
                ->where($qb->expr()->in('ts.category_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)))
                ->orderBy('c.name', 'ASC')
                ->addOrderBy('ts.sort_order', 'ASC')
                ->addOrderBy('ts.name', 'ASC');

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $sets[] = [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'categoryId' => (int)$row['category_id'],
                    'categoryName' => (string)$row['category_name'],
                ];
            }
            $result->closeCursor();
        }

        return $sets;
    }

    /**
     * Which category each of these tag sets belongs to.
     *
     * A tag set belongs to exactly one category -- there is no cascade to child
     * categories -- so this is what decides whether a category tag may be
     * applied to a given row.
     *
     * @param int[] $tagSetIds
     * @return array<int, int> tagSetId => categoryId, for sets this user owns
     */
    public function findCategoryIdsForTagSets(array $tagSetIds, string $userId): array {
        if (empty($tagSetIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('ts.id', 'ts.category_id')
            ->from($this->getTableName(), 'ts')
            ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
            ->where($qb->expr()->in('ts.id', $qb->createNamedParameter($tagSetIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $map = [];
        while ($row = $result->fetch()) {
            $map[(int)$row['id']] = (int)$row['category_id'];
        }
        $result->closeCursor();

        return $map;
    }

    /**
     * Find all tag sets for a user (across all their categories)
     *
     * @return TagSet[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('ts.*')
            ->from($this->getTableName(), 'ts')
            ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
            ->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('ts.sort_order', 'ASC')
            ->addOrderBy('ts.name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Check if a tag set name already exists for a category
     *
     * @param int|null $excludeId Tag set ID to exclude (for updates)
     */
    public function nameExists(int $categoryId, string $name, ?int $excludeId = null): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COUNT(*)'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq($qb->createFunction('LOWER(name)'), $qb->createNamedParameter(strtolower($name))));

        if ($excludeId !== null) {
            $qb->andWhere($qb->expr()->neq('id', $qb->createNamedParameter($excludeId, IQueryBuilder::PARAM_INT)));
        }

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count > 0;
    }

    /**
     * Find multiple tag sets by IDs in a single query (avoids N+1)
     *
     * @param int[] $ids
     * @return array<int, TagSet> tagSetId => TagSet
     */
    public function findByIds(array $ids, string $userId): array {
        if (empty($ids)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('ts.*')
            ->from($this->getTableName(), 'ts')
            ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
            ->where($qb->expr()->in('ts.id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)));

        $entities = $this->findEntities($qb);

        // Index by ID for quick lookup
        $result = [];
        foreach ($entities as $entity) {
            $result[$entity->getId()] = $entity;
        }

        return $result;
    }

    /**
     * Delete all tag sets for a user (cascades to tags and transaction_tags)
     *
     * @param string $userId
     * @return int Number of deleted rows
     */
    public function deleteAll(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        // Get all tag set IDs for this user first
        $qb->select('ts.id')
            ->from($this->getTableName(), 'ts')
            ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
            ->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $tagSetIds = $result->fetchAll(\PDO::FETCH_COLUMN);
        $result->closeCursor();

        if (empty($tagSetIds)) {
            return 0;
        }

        // Delete tag sets (cascade handles tags and transaction_tags)
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($tagSetIds, IQueryBuilder::PARAM_INT_ARRAY)));

        return $qb->executeStatement();
    }
}
