<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Category>
 */
class CategoryMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_categories', Category::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Category {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        
        return $this->findEntity($qb);
    }

    /**
     * @return Category[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');
        
        return $this->findEntities($qb);
    }

    /**
     * @return Category[]
     */
    public function findByType(string $userId, string $type): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');
        
        return $this->findEntities($qb);
    }

    /**
     * @return Category[]
     */
    public function findChildren(string $userId, int $parentId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('parent_id', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');
        
        return $this->findEntities($qb);
    }

    /**
     * @return Category[]
     */
    public function findRootCategories(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNull('parent_id'))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');
        
        return $this->findEntities($qb);
    }

    /**
     * Get category spending for a specific period
     */
    public function getCategorySpending(int $categoryId, string $startDate, string $endDate): float {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->sum('t.amount'))
            ->from('budget_transactions', 't')
            ->where($qb->expr()->eq('t.category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')));

        $result = $qb->executeQuery();
        $sum = $result->fetchOne();
        $result->closeCursor();

        return (float) ($sum ?? 0);
    }

    /**
     * Find multiple categories by IDs in a single query (avoids N+1)
     * @param int[] $ids
     * @return array<int, Category> categoryId => Category
     */
    public function findByIds(array $ids, string $userId): array {
        if (empty($ids)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $entities = $this->findEntities($qb);

        // Index by ID for quick lookup
        $result = [];
        foreach ($entities as $entity) {
            $result[$entity->getId()] = $entity;
        }

        return $result;
    }

    /**
     * Delete all categories for a user
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