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
     * Find a category by ID
     *
     * @param int $id Category ID
     * @param string $userId Current user ID (for permission check)
     * @param array|null $accessibleUserIds Optional list of user IDs that current user can access (for shared budgets)
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId, ?array $accessibleUserIds = null): Category {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // If accessibleUserIds provided, check if category belongs to any accessible user
        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            // Default: only current user's categories
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        return $this->findEntity($qb);
    }

    /**
     * Find all categories for user(s)
     *
     * @param string $userId Current user ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch categories for (for shared budgets)
     * @return Category[]
     */
    public function findAll(string $userId, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        // If accessibleUserIds provided, get categories from all accessible users
        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            // Default: only current user's categories
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->orderBy('user_id', 'ASC')
            ->addOrderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find categories by type
     *
     * @param string $userId Current user ID
     * @param string $type Category type
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch categories for (for shared budgets)
     * @return Category[]
     */
    public function findByType(string $userId, string $type, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        // If accessibleUserIds provided, get categories from all accessible users
        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            // Default: only current user's categories
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find child categories of a parent
     *
     * @param string $userId Current user ID
     * @param int $parentId Parent category ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch categories for (for shared budgets)
     * @return Category[]
     */
    public function findChildren(string $userId, int $parentId, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        // If accessibleUserIds provided, get categories from all accessible users
        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            // Default: only current user's categories
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->andWhere($qb->expr()->eq('parent_id', $qb->createNamedParameter($parentId, IQueryBuilder::PARAM_INT)))
            ->orderBy('sort_order', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find root (top-level) categories
     *
     * @param string $userId Current user ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch categories for (for shared budgets)
     * @return Category[]
     */
    public function findRootCategories(string $userId, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        // If accessibleUserIds provided, get categories from all accessible users
        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            // Default: only current user's categories
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->andWhere($qb->expr()->isNull('parent_id'))
            ->orderBy('user_id', 'ASC')
            ->addOrderBy('sort_order', 'ASC')
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
     *
     * @param int[] $ids Category IDs
     * @param string $userId Current user ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch categories for (for shared budgets)
     * @return array<int, Category> categoryId => Category
     */
    public function findByIds(array $ids, string $userId, ?array $accessibleUserIds = null): array {
        if (empty($ids)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

        // If accessibleUserIds provided, check if categories belong to any accessible user
        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            // Default: only current user's categories
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

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