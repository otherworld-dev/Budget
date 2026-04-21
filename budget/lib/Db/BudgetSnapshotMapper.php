<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<BudgetSnapshot>
 */
class BudgetSnapshotMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_budget_snapshots', BudgetSnapshot::class);
    }

    /**
     * Get all snapshots for a user, ordered by effective_from DESC.
     *
     * @return BudgetSnapshot[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('effective_from', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Get all snapshots for a specific month (exact match).
     *
     * @return BudgetSnapshot[]
     */
    public function findByMonth(string $userId, string $month): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('effective_from', $qb->createNamedParameter($month)))
            ->orderBy('category_id', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Check if a snapshot exists for a specific month.
     */
    public function hasSnapshot(string $userId, string $month): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->selectAlias($qb->createFunction('COUNT(*)'), 'count')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('effective_from', $qb->createNamedParameter($month)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();
        return $count > 0;
    }

    /**
     * Get the effective budget for a category in a given month.
     * Finds the most recent snapshot with effective_from <= month.
     */
    public function findEffective(int $categoryId, string $userId, string $month): ?BudgetSnapshot {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($month)))
            ->orderBy('effective_from', 'DESC')
            ->setMaxResults(1);

        $entities = $this->findEntities($qb);
        return $entities[0] ?? null;
    }

    /**
     * Batch-resolve effective budgets for all categories at a given month.
     * Returns map of categoryId => ['amount' => float, 'period' => string, 'effectiveFrom' => string].
     */
    public function findEffectiveBatch(string $userId, string $month): array {
        // Get all snapshots for this user at or before the given month
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->lte('effective_from', $qb->createNamedParameter($month)))
            ->orderBy('effective_from', 'DESC');

        $snapshots = $this->findEntities($qb);

        // Keep only the most recent snapshot per category
        $result = [];
        foreach ($snapshots as $snapshot) {
            $catId = $snapshot->getCategoryId();
            if (!isset($result[$catId])) {
                $result[$catId] = [
                    'amount' => $snapshot->getAmount(),
                    'period' => $snapshot->getPeriod() ?? 'monthly',
                    'effectiveFrom' => $snapshot->getEffectiveFrom(),
                ];
            }
        }

        return $result;
    }

    /**
     * Get all distinct snapshot months for a user, ordered DESC.
     *
     * @return string[]
     */
    public function getSnapshotMonths(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->selectDistinct('effective_from')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('effective_from', 'DESC');

        $result = $qb->executeQuery();
        $months = [];
        while ($row = $result->fetch()) {
            $months[] = $row['effective_from'];
        }
        $result->closeCursor();
        return $months;
    }

    /**
     * Delete all snapshots for a given month.
     */
    public function deleteByMonth(string $userId, string $month): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('effective_from', $qb->createNamedParameter($month)));
        $qb->executeStatement();
    }

    /**
     * Delete all snapshots for a user (factory reset).
     */
    public function deleteAll(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    /**
     * Delete all snapshots for a specific category.
     */
    public function deleteByCategory(int $categoryId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }
}
