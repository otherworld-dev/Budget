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
     * The split side of the direct/split partition: a parent counts through
     * its parts when its flag says split OR was never written (is_split
     * predates its own default). A part whose parent is explicitly unsplit
     * (is_split = false) is stray — the parent's own amount counts and the
     * leftover parts do not (the policy TransactionMapper::splitParentPredicate
     * and its directRowPredicate complement state, #356/#360).
     */
    private function splitParentPredicate(IQueryBuilder $qb, string $alias = 't'): \OCP\DB\QueryBuilder\ICompositeExpression {
        return $qb->expr()->orX(
            $qb->expr()->eq("{$alias}.is_split", $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
            $qb->expr()->isNull("{$alias}.is_split")
        );
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
     * @return array<int, array<string, float>> categoryId => bucket => total
     */
    public function getCategoryTotalsByBucket(string $userId, string $startDate, string $endDate, bool $byDay = false, ?array $visibleAccountIds = null): array {
        $qb = $this->db->getQueryBuilder();

        $bucketExpr = $byDay ? 'CAST(t.date AS CHAR(10))' : 'SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)';
        $today = date('Y-m-d');

        $qb->select('s.category_id')
            ->selectAlias($qb->createFunction($bucketExpr), 'bucket')
            ->selectAlias($qb->func()->sum('s.amount'), 'total')
            ->from($this->getTableName(), 's')
            ->innerJoin('s', 'budget_transactions', 't', $qb->expr()->eq('s.transaction_id', 't.id'))
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            // Scope by the accounts the user can see rather than only the ones
            // they own — a split booked in a shared account belongs in their
            // envelope like any other spending (#341).
            ->where($visibleAccountIds !== null && !empty($visibleAccountIds)
                ? $qb->expr()->in('a.id', $qb->createNamedParameter($visibleAccountIds, IQueryBuilder::PARAM_INT_ARRAY))
                : $qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            // Exclude accounts flagged out of reports/budgets (#286)
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('a.excluded_from_reports'),
                $qb->expr()->eq('a.excluded_from_reports', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ))
            ->andWhere($qb->expr()->isNotNull('s.category_id'))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($this->splitParentPredicate($qb))
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
     * Split allocations as signed-net per category per month over a date range:
     * a split contributes positively when its parent transaction is a credit and
     * negatively when a debit (splits inherit the parent's direction). Mirrors
     * getCategoryTotalsByBucket but signed, for the Category-by-Month report (#288).
     * Scheduled-future transactions and accounts flagged out of reports (#286) are excluded.
     *
     * @param int[]|null $visibleAccountIds If provided, scope by account IDs instead of userId
     * @return array<int, array<string, float>> categoryId => 'YYYY-MM' => signed net from splits
     */
    public function getCategoryNetByMonthBatch(string $userId, string $startDate, string $endDate, ?int $accountId = null, ?array $visibleAccountIds = null): array {
        $qb = $this->db->getQueryBuilder();

        $bucketExpr = 'SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)';
        $today = date('Y-m-d');

        if ($visibleAccountIds !== null && !empty($visibleAccountIds)) {
            $scopeWhere = $qb->expr()->in('a.id', $qb->createNamedParameter($visibleAccountIds, IQueryBuilder::PARAM_INT_ARRAY));
        } else {
            $scopeWhere = $qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId));
        }

        $qb->select('s.category_id')
            ->selectAlias($qb->createFunction($bucketExpr), 'bucket')
            ->selectAlias($qb->createFunction(
                "SUM(CASE WHEN t.type = 'credit' THEN s.amount ELSE -s.amount END)"
            ), 'net_total')
            ->from($this->getTableName(), 's')
            ->innerJoin('s', 'budget_transactions', 't', $qb->expr()->eq('s.transaction_id', 't.id'))
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($scopeWhere)
            // Exclude accounts flagged out of reports (#286)
            ->andWhere($qb->expr()->orX(
                $qb->expr()->isNull('a.excluded_from_reports'),
                $qb->expr()->eq('a.excluded_from_reports', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
            ))
            ->andWhere($qb->expr()->isNotNull('s.category_id'))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->andWhere($this->splitParentPredicate($qb))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->neq('t.status', $qb->createNamedParameter('scheduled')),
                $qb->expr()->isNull('t.status'),
                $qb->expr()->lte('t.date', $qb->createNamedParameter($today))
            ))
            ->groupBy('s.category_id')
            ->addGroupBy($qb->createFunction($bucketExpr));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        $result = $qb->executeQuery();
        $totals = [];
        while ($row = $result->fetch()) {
            $totals[(int) $row['category_id']][substr((string) $row['bucket'], 0, 7)] = (float) $row['net_total'];
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
