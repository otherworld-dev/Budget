<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCA\Budget\Service\MoneyCalculator;
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
     * One-row probe: does this transaction have any split parts? For write
     * paths that must not trust is_split alone — restores from archives that
     * predate this table joining the backup registry (#351) left flags
     * claiming split with no parts behind them (#360).
     */
    public function hasParts(int $transactionId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(1);

        $result = $qb->executeQuery();
        $row = $result->fetchOne();
        $result->closeCursor();

        return $row !== false;
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
     * categoryId is part of the shape because a consumer filtering by category
     * has to be able to tell which part it matched (#359).
     *
     * $transactionIds is chunked at 500: YearOverYearService can pass a full
     * year of split ids and PatternAnalyzer a whole forecast window, and old
     * SQLite builds cap bound variables at 999 -- one unchunked IN() over a
     * year's worth of ids would exceed that. Each chunk is a separate query;
     * the per-transaction groups never span a chunk boundary, so accumulating
     * into one $grouped array as chunks come back is a plain merge.
     *
     * @param int[] $transactionIds
     * @return array<int, array> Map of transactionId => [{categoryId, categoryName, amount}, ...]
     */
    public function findByTransactionIds(array $transactionIds): array {
        if (empty($transactionIds)) return [];

        $grouped = [];
        foreach (array_chunk($transactionIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('s.transaction_id', 's.category_id', 's.amount', 'c.name as category_name')
                ->from($this->getTableName(), 's')
                ->leftJoin('s', 'budget_categories', 'c', 'c.id = s.category_id')
                ->where($qb->expr()->in('s.transaction_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->orderBy('s.transaction_id', 'ASC')
                ->addOrderBy('s.amount', 'DESC');

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $txId = (int)$row['transaction_id'];
                if (!isset($grouped[$txId])) $grouped[$txId] = [];
                $grouped[$txId][] = [
                    'categoryId' => isset($row['category_id']) ? (int)$row['category_id'] : null,
                    'categoryName' => $row['category_name'] ?? null,
                    'amount' => (float)$row['amount'],
                ];
            }
            $result->closeCursor();
        }

        return $grouped;
    }

    /**
     * Null out category_id on every split part pointing at one of these
     * category ids -- a part with no category degrades to uncategorized,
     * the same fate as a direct transaction whose category is reassigned
     * (CategoryService::deleteWithReassign() / beforeDelete(), #360).
     */
    public function clearCategory(array $categoryIds): int {
        if (empty($categoryIds)) {
            return 0;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('category_id', $qb->createNamedParameter(null, IQueryBuilder::PARAM_NULL))
            ->where($qb->expr()->in('category_id', $qb->createNamedParameter($categoryIds, IQueryBuilder::PARAM_INT_ARRAY)));

        return $qb->executeStatement();
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
     * $transactionIds is chunked at 500 for the same reason as
     * findByTransactionIds() above -- YearOverYearService can hand this a
     * full year of split ids, which old SQLite builds' 999 bound-variable
     * cap can't take in one IN(). Unlike that method, the same category can
     * legitimately appear in more than one chunk, so per-chunk totals are
     * summed rather than merged.
     *
     * @return array Array of [categoryId => totalAmount]
     */
    public function getCategoryTotals(array $transactionIds): array {
        if (empty($transactionIds)) {
            return [];
        }

        $totals = [];
        foreach (array_chunk($transactionIds, 500) as $chunk) {
            $qb = $this->db->getQueryBuilder();
            $qb->select('s.category_id')
                ->selectAlias($qb->func()->sum('s.amount'), 'total')
                ->from($this->getTableName(), 's')
                ->where($qb->expr()->in('s.transaction_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)))
                ->groupBy('s.category_id');

            $result = $qb->executeQuery();
            while ($row = $result->fetch()) {
                $categoryId = $row['category_id'] ? (int) $row['category_id'] : null;
                // Cross-chunk money accumulates through MoneyCalculator,
                // never `+` on floats (#274) — these totals leave here
                // unrounded, so any drift went straight to the callers.
                $totals[$categoryId] = MoneyCalculator::add($totals[$categoryId] ?? '0', (string) $row['total']);
            }
            $result->closeCursor();
        }

        return array_map([MoneyCalculator::class, 'toFloat'], $totals);
    }

    /**
     * Split allocations per category per bucket over a date range, in one
     * query. Mirrors the semantics of getSplitTransactionIds + getCategoryTotals
     * (debit split parents, scheduled-future excluded) but adds the time
     * dimension for the budget carryover chain. Bucket is the calendar month
     * (YYYY-MM) by default, or the exact date with $byDay.
     *
     * The companion of TransactionMapper::getCategorySpendingByBucketBatch(),
     * scoped through the same ReportScope predicates so the two halves of the
     * partition agree: the accounts the user can see, minus those flagged out
     * of reports (#286, #341), split parents per splitParentPredicate()
     * (#360), and no future scheduled rows or pension-funding legs (#304).
     * Category exclusion is the budget's own business (BudgetScope), so it is
     * not applied here.
     *
     * @return array<int, array<string, float>> categoryId => bucket => total
     */
    public function getCategoryTotalsByBucket(string $userId, string $startDate, string $endDate, bool $byDay = false, ?array $visibleAccountIds = null): array {
        $qb = $this->db->getQueryBuilder();

        $bucketExpr = $byDay ? 'CAST(t.date AS CHAR(10))' : ReportScope::monthExpr();

        $qb->select('s.category_id')
            ->selectAlias($qb->createFunction($bucketExpr), 'bucket')
            ->selectAlias($qb->func()->sum('s.amount'), 'total')
            ->from($this->getTableName(), 's')
            ->innerJoin('s', 'budget_transactions', 't', $qb->expr()->eq('s.transaction_id', 't.id'))
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'));
        ReportScope::applyUserScope($qb, $userId, $visibleAccountIds);
        $qb->andWhere($qb->expr()->isNotNull('s.category_id'))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere(ReportScope::splitParentPredicate($qb))
            ->groupBy('s.category_id')
            ->addGroupBy($qb->createFunction($bucketExpr));
        ReportScope::excludeScheduledFuture($qb);

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
