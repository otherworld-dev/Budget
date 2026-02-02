<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<RecurringIncome>
 */
class RecurringIncomeMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_recurring_income', RecurringIncome::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): RecurringIncome {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * @return RecurringIncome[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('next_expected_date', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @return RecurringIncome[]
     */
    public function findActive(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('next_expected_date', 'ASC')
            ->addOrderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find income expected within a date range
     * @return RecurringIncome[]
     */
    public function findExpectedInRange(string $userId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->gte('next_expected_date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('next_expected_date', $qb->createNamedParameter($endDate)))
            ->orderBy('next_expected_date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find income by category
     * @return RecurringIncome[]
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
     * Find income by frequency
     * @return RecurringIncome[]
     */
    public function findByFrequency(string $userId, string $frequency): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('frequency', $qb->createNamedParameter($frequency)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('next_expected_date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find upcoming income (within next N days)
     * @return RecurringIncome[]
     */
    public function findUpcoming(string $userId, int $days = 30): array {
        $today = date('Y-m-d');
        $endDate = date('Y-m-d', strtotime("+{$days} days"));

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_active', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->gte('next_expected_date', $qb->createNamedParameter($today)))
            ->andWhere($qb->expr()->lte('next_expected_date', $qb->createNamedParameter($endDate)))
            ->orderBy('next_expected_date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get total monthly income amount (normalized from all frequencies)
     */
    public function getMonthlyTotal(string $userId): float {
        $incomes = $this->findActive($userId);
        $total = 0.0;

        foreach ($incomes as $income) {
            $total += $this->getMonthlyEquivalent($income);
        }

        return $total;
    }

    /**
     * Convert any income frequency to monthly equivalent
     */
    private function getMonthlyEquivalent(RecurringIncome $income): float {
        $amount = $income->getAmount();

        return match ($income->getFrequency()) {
            'daily' => $amount * 30,
            'weekly' => $amount * 52 / 12,
            'biweekly' => $amount * 26 / 12,
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
     * Delete all recurring income for a user
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
