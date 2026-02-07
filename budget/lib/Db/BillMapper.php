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
     * @param int $id Bill ID
     * @param string $userId Current user ID (for permission check)
     * @param array|null $accessibleUserIds Optional list of user IDs that current user can access (for shared budgets)
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId, ?array $accessibleUserIds = null): Bill {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        return $this->findEntity($qb);
    }

    /**
     * @param string $userId Current user ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch bills for (for shared budgets)
     * @return Bill[]
     */
    public function findAll(string $userId, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->orderBy('next_due_date', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @param string $userId Current user ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch bills for (for shared budgets)
     * @return Bill[]
     */
    public function findActive(string $userId, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('next_due_date', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find bills due within a date range
     * @param string $userId Current user ID
     * @param string $startDate Start date
     * @param string $endDate End date
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch bills for (for shared budgets)
     * @return Bill[]
     */
    public function findDueInRange(string $userId, string $startDate, string $endDate, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->gte('next_due_date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('next_due_date', $qb->createNamedParameter($endDate)))
            ->orderBy('next_due_date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find bills by category
     * @param string $userId Current user ID
     * @param int $categoryId Category ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch bills for (for shared budgets)
     * @return Bill[]
     */
    public function findByCategory(string $userId, int $categoryId, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->andWhere($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find bills by frequency
     * @param string $userId Current user ID
     * @param string $frequency Frequency type
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch bills for (for shared budgets)
     * @return Bill[]
     */
    public function findByFrequency(string $userId, string $frequency, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->andWhere($qb->expr()->eq('frequency', $qb->createNamedParameter($frequency)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('next_due_date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find bills or transfers based on type
     * @param string $userId Current user ID
     * @param bool|null $isTransfer null = all, true = only transfers, false = only bills
     * @param bool|null $isActive null = all, true = only active, false = only inactive
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch bills for (for shared budgets)
     * @return Bill[]
     */
    public function findByType(string $userId, ?bool $isTransfer = null, ?bool $isActive = null, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        if ($isTransfer !== null) {
            $qb->andWhere($qb->expr()->eq('is_transfer', $qb->createNamedParameter($isTransfer, IQueryBuilder::PARAM_BOOL)));
        }

        if ($isActive !== null) {
            $qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter($isActive, IQueryBuilder::PARAM_BOOL)));
        }

        $qb->orderBy('next_due_date', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find overdue bills (next_due_date < today)
     * @param string $userId Current user ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch bills for (for shared budgets)
     * @return Bill[]
     */
    public function findOverdue(string $userId, ?array $accessibleUserIds = null): array {
        $today = date('Y-m-d');
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->lt('next_due_date', $qb->createNamedParameter($today)))
            ->orderBy('next_due_date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find bills that are due for auto-payment today or earlier.
     * Returns only active bills with auto-pay enabled, valid account,
     * not already paid this month, and not in failed state.
     *
     * @return Bill[]
     */
    public function findDueForAutoPay(string $userId): array {
        $today = date('Y-m-d');
        $startOfMonth = date('Y-m-01');

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->eq('auto_pay_enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->eq('auto_pay_failed', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->isNotNull('account_id'))
            ->andWhere($qb->expr()->isNotNull('next_due_date'))
            ->andWhere($qb->expr()->lte('next_due_date', $qb->createNamedParameter($today)))
            // Exclude bills already paid this month
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->isNull('last_paid_date'),
                    $qb->expr()->lt('last_paid_date', $qb->createNamedParameter($startOfMonth))
                )
            )
            ->orderBy('next_due_date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get total monthly bill amount
     * @param string $userId Current user ID
     * @param array|null $accessibleUserIds Optional list of user IDs to calculate total for (for shared budgets)
     */
    public function getMonthlyTotal(string $userId, ?array $accessibleUserIds = null): float {
        $bills = $this->findActive($userId, $accessibleUserIds);
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
     * Update specific fields directly using query builder.
     * This is useful for setting fields to null where Entity change detection may not work.
     *
     * @param int $id
     * @param string $userId
     * @param array $fields Associative array of column_name => value
     * @return void
     */
    public function updateFields(int $id, string $userId, array $fields): void {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        foreach ($fields as $column => $value) {
            if ($value === null) {
                $qb->set($column, $qb->createNamedParameter($value, IQueryBuilder::PARAM_NULL));
            } else {
                // Auto-detect parameter type
                $type = is_int($value) ? IQueryBuilder::PARAM_INT :
                       (is_bool($value) ? IQueryBuilder::PARAM_BOOL : IQueryBuilder::PARAM_STR);
                $qb->set($column, $qb->createNamedParameter($value, $type));
            }
        }

        $qb->executeStatement();
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
