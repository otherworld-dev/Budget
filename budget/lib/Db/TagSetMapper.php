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
