<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Bill>
 */
class BillMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_bills', Bill::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Bill {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * @return Bill[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('next_due_date', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @return Bill[]
     */
    public function findActive(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('next_due_date', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find bills due within a date range
     * @return Bill[]
     */
    public function findDueInRange(string $userId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->gte('next_due_date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('next_due_date', $qb->createNamedParameter($endDate)))
            ->orderBy('next_due_date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find bills by category
     * @return Bill[]
     */
    public function findByCategory(string $userId, int $categoryId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find bills by frequency
     * @return Bill[]
     */
    public function findByFrequency(string $userId, string $frequency): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('frequency', $qb->createNamedParameter($frequency)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('next_due_date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find overdue bills (next_due_date < today)
     * @return Bill[]
     */
    public function findOverdue(string $userId): array {
        $today = date('Y-m-d');
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->lt('next_due_date', $qb->createNamedParameter($today)))
            ->orderBy('next_due_date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get total monthly bill amount
     */
    public function getMonthlyTotal(string $userId): float {
        $bills = $this->findActive($userId);
        $total = 0.0;

        foreach ($bills as $bill) {
            $total += $this->getMonthlyEquivalent($bill);
        }

        return $total;
    }

    /**
     * Convert any bill frequency to monthly equivalent
     */
    private function getMonthlyEquivalent(Bill $bill): float {
        $amount = $bill->getAmount();

        return match ($bill->getFrequency()) {
            'weekly' => $amount * 52 / 12,    // ~4.33 weeks per month
            'monthly' => $amount,
            'quarterly' => $amount / 3,
            'yearly' => $amount / 12,
            default => $amount,
        };
    }

    /**
     * Delete all bills for a user
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
