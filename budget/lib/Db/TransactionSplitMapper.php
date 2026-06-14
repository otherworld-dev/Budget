<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @extends QBMapper<TransactionSplit>
 */
class TransactionSplitMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_tx_splits', TransactionSplit::class);
    }

    /**
     * Find a split by ID.
     *
     * @throws DoesNotExistException
     */
    public function find(int $id): TransactionSplit {
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.*', 'c.name as category_name')
            ->from($this->getTableName(), 's')
            ->leftJoin('s', 'budget_categories', 'c', 'c.id = s.category_id')
            ->where($qb->expr()->eq('s.id', $qb->createNamedParameter($id)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        if ($row === false) {
            throw new DoesNotExistException('TransactionSplit not found');
        }

        return $this->mapRowToEntityWithCategory($row);
    }

    /**
     * Find all splits for a transaction.
     *
     * @return TransactionSplit[]
     */
    public function findByTransaction(int $transactionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('s.*', 'c.name as category_name')
            ->from($this->getTableName(), 's')
            ->leftJoin('s', 'budget_categories', 'c', 'c.id = s.category_id')
            ->where($qb->expr()->eq('s.transaction_id', $qb->createNamedParameter($transactionId)))
            ->orderBy('s.id', 'ASC');

        $result = $qb->executeQuery();
        $splits = [];
        while ($row = $result->fetch()) {
            $splits[] = $this->mapRowToEntityWithCategory($row);
        }
        $result->closeCursor();

        return $splits;
    }

    /**
     * Find splits for multiple transactions at once (batch).
     *
     * @param int[] $transactionIds
     * @return array<int, array> Map of transactionId => [{categoryName, amount}, ...]
     */
    public function findByTransactionIds(array $transactionIds): array {
        if (empty($transactionIds)) return [];

        $qb = $this->db->getQueryBuilder();
        $qb->select('s.transaction_id', 's.amount', 'c.name as category_name')
            ->from($this->getTableName(), 's')
            ->leftJoin('s', 'budget_categories', 'c', 'c.id = s.category_id')
            ->where($qb->expr()->in('s.transaction_id', $qb->createNamedParameter($transactionIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->orderBy('s.transaction_id', 'ASC')
            ->addOrderBy('s.amount', 'DESC');

        $result = $qb->executeQuery();
        $grouped = [];
        while ($row = $result->fetch()) {
            $txId = (int)$row['transaction_id'];
            if (!isset($grouped[$txId])) $grouped[$txId] = [];
            $grouped[$txId][] = [
                'categoryName' => $row['category_name'] ?? null,
                'amount' => (float)$row['amount'],
            ];
        }
        $result->closeCursor();

        return $grouped;
    }

    /**
     * Delete all splits for a transaction.
     */
    public function deleteByTransaction(int $transactionId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId)));
        $qb->executeStatement();
    }

    /**
     * Get category totals from splits for reporting.
     *
     * @return array Array of [categoryId => totalAmount]
     */
    public function getCategoryTotals(array $transactionIds): array {
        if (empty($transactionIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('s.category_id')
            ->selectAlias($qb->func()->sum('s.amount'), 'total')
            ->from($this->getTableName(), 's')
            ->where($qb->expr()->in('s.transaction_id', $qb->createNamedParameter($transactionIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->groupBy('s.category_id');

        $result = $qb->executeQuery();
        $totals = [];
        while ($row = $result->fetch()) {
            $categoryId = $row['category_id'] ? (int) $row['category_id'] : null;
            $totals[$categoryId] = (float) $row['total'];
        }
        $result->closeCursor();

        return $totals;
    }

    /**
     * Split allocations per category per bucket over a date range, in one
     * query. Mirrors the semantics of getSplitTransactionIds + getCategoryTotals
     * (debit split parents, scheduled-future excluded) but adds the time
     * dimension for the budget carryover chain. Bucket is the calendar month
     * (YYYY-MM) by default, or the exact date with $byDay.
     *
     * @return array<int, array<string, float>> categoryId => bucket => total
     */
    public function getCategoryTotalsByBucket(string $userId, string $startDate, string $endDate, bool $byDay = false): array {
        $qb = $this->db->getQueryBuilder();

        $bucketExpr = $byDay ? 'CAST(t.date AS CHAR(10))' : 'SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)';
        $today = date('Y-m-d');

        $qb->select('s.category_id')
            ->selectAlias($qb->createFunction($bucketExpr), 'bucket')
            ->selectAlias($qb->func()->sum('s.amount'), 'total')
            ->from($this->getTableName(), 's')
            ->innerJoin('s', 'budget_transactions', 't', $qb->expr()->eq('s.transaction_id', 't.id'))
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            // Exclude accounts flagged out of reports/budgets (#286)
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('a.excluded_from_reports'),
                $qb->expr()->eq('a.excluded_from_reports', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ))
            ->andWhere($qb->expr()->isNotNull('s.category_id'))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->neq('t.status', $qb->createNamedParameter('scheduled')),
                $qb->expr()->isNull('t.status'),
                $qb->expr()->lte('t.date', $qb->createNamedParameter($today))
            ))
            ->groupBy('s.category_id')
            ->addGroupBy($qb->createFunction($bucketExpr));

        $result = $qb->executeQuery();
        $totals = [];
        while ($row = $result->fetch()) {
            $totals[(int) $row['category_id']][substr((string) $row['bucket'], 0, $byDay ? 10 : 7)] = (float) $row['total'];
        }
        $result->closeCursor();

        return $totals;
    }

    /**
     * Map a database row to entity with category name.
     */
    private function mapRowToEntityWithCategory(array $row): TransactionSplit {
        $split = new TransactionSplit();
        $split->setId((int) $row['id']);
        $split->setTransactionId((int) $row['transaction_id']);
        $split->setCategoryId($row['category_id'] ? (int) $row['category_id'] : null);
        $split->setAmount($row['amount']);
        $split->setDescription($row['description']);
        $split->setCreatedAt($row['created_at']);
        $split->setCategoryName($row['category_name'] ?? null);
        $split->resetUpdatedFields();

        return $split;
    }

    /**
     * Delete all transaction splits for a user (via transaction ownership)
     *
     * @param string $userId
     * @return int Number of deleted rows
     */
    public function deleteAll(string $userId): int {
        // DELETE doesn't support JOINs — use subquery to find IDs first
        $sub = $this->db->getQueryBuilder();
        $sub->select('s.id')
            ->from($this->getTableName(), 's')
            ->innerJoin('s', 'budget_transactions', 't', $sub->expr()->eq('s.transaction_id', 't.id'))
            ->innerJoin('t', 'budget_accounts', 'a', $sub->expr()->eq('t.account_id', 'a.id'))
            ->where($sub->expr()->eq('a.user_id', $sub->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        $result = $sub->executeQuery();
        $ids = array_column($result->fetchAll(), 'id');
        $result->closeCursor();

        if (empty($ids)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

        return $qb->executeStatement();
    }
}
