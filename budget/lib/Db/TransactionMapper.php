<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Transaction>
 */
class TransactionMapper extends QBMapper {
    private QueryFilterBuilder $filterBuilder;

    public function __construct(IDBConnection $db, ?QueryFilterBuilder $filterBuilder = null) {
        parent::__construct($db, 'budget_transactions', Transaction::class);
        $this->filterBuilder = $filterBuilder ?? new QueryFilterBuilder();
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Transaction {
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.*')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('t.id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));
        
        return $this->findEntity($qb);
    }

    /**
     * @return Transaction[]
     */
    public function findByAccount(int $accountId, int $limit = 100, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->orderBy('date', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        
        return $this->findEntities($qb);
    }

    /**
     * @return Transaction[]
     */
    public function findByDateRange(int $accountId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($endDate)))
            ->orderBy('date', 'DESC')
            ->addOrderBy('id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find all transactions for a user (across all accounts)
     * @return Transaction[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.*')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find all transactions for a user within a date range (across all accounts)
     * @return Transaction[]
     */
    public function findAllByUserAndDateRange(string $userId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.*')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->orderBy('t.date', 'DESC')
            ->addOrderBy('t.id', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * @return Transaction[]
     */
    public function findByCategory(int $categoryId, int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->orderBy('date', 'DESC')
            ->setMaxResults($limit);
        
        return $this->findEntities($qb);
    }

    /**
     * Check if transaction with import ID already exists
     */
    public function existsByImportId(int $accountId, string $importId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('import_id', $qb->createNamedParameter($importId)));
        
        $result = $qb->executeQuery();
        $count = $result->fetchOne();
        $result->closeCursor();
        
        return $count > 0;
    }

    /**
     * @return Transaction[]
     */
    public function findUncategorized(string $userId, int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.*')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNull('t.category_id'))
            ->orderBy('t.date', 'DESC')
            ->setMaxResults($limit);
        
        return $this->findEntities($qb);
    }

    /**
     * Search transactions
     * @return Transaction[]
     */
    public function search(string $userId, string $query, int $limit = 100): array {
        $qb = $this->db->getQueryBuilder();
        $searchPattern = '%' . $qb->escapeLikeParameter($query) . '%';
        
        $qb->select('t.*')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('t.description', $qb->createNamedParameter($searchPattern)),
                    $qb->expr()->like('t.vendor', $qb->createNamedParameter($searchPattern)),
                    $qb->expr()->like('t.notes', $qb->createNamedParameter($searchPattern))
                )
            )
            ->orderBy('t.date', 'DESC')
            ->setMaxResults($limit);
        
        return $this->findEntities($qb);
    }

    /**
     * Find transactions with filters, pagination and sorting
     */
    public function findWithFilters(string $userId, array $filters, int $limit, int $offset): array {
        // Main query
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.*')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));

        // Apply filters using the filter builder
        $this->filterBuilder->applyTransactionFilters($qb, $filters, 't');

        // Count query - reuse filter builder for consistency
        $countQb = $this->db->getQueryBuilder();
        $countQb->select($countQb->func()->count('t.id'))
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $countQb->expr()->eq('t.account_id', 'a.id'))
            ->where($countQb->expr()->eq('a.user_id', $countQb->createNamedParameter($userId)));

        // Apply same filters to count query
        $this->filterBuilder->applyTransactionFilters($countQb, $filters, 't');

        $countResult = $countQb->executeQuery();
        $total = (int)$countResult->fetchOne();
        $countResult->closeCursor();

        // Apply sorting and pagination
        $this->filterBuilder->applySorting($qb, $filters['sort'] ?? null, $filters['direction'] ?? null, 't');
        $this->filterBuilder->applyPagination($qb, $limit, $offset);

        // Also select account name and currency
        $qb->addSelect('a.name as account_name', 'a.currency as account_currency');

        // Also join and select category name
        $qb->leftJoin('t', 'budget_categories', 'c', $qb->expr()->eq('t.category_id', 'c.id'));
        $qb->addSelect('c.name as category_name');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        // Convert to array format with extra fields
        $transactions = array_map(function ($row) {
            return [
                'id' => (int)$row['id'],
                'accountId' => (int)$row['account_id'],
                'categoryId' => $row['category_id'] ? (int)$row['category_id'] : null,
                'date' => $row['date'],
                'description' => $row['description'],
                'vendor' => $row['vendor'],
                'amount' => (float)$row['amount'],
                'type' => $row['type'],
                'reference' => $row['reference'],
                'notes' => $row['notes'],
                'importId' => $row['import_id'],
                'reconciled' => (bool)$row['reconciled'],
                'createdAt' => $row['created_at'],
                'updatedAt' => $row['updated_at'],
                'linkedTransactionId' => $row['linked_transaction_id'] ? (int)$row['linked_transaction_id'] : null,
                'isSplit' => (bool)($row['is_split'] ?? false),
                'accountName' => $row['account_name'],
                'accountCurrency' => $row['account_currency'] ?? 'USD',
                'categoryName' => $row['category_name'],
            ];
        }, $rows);

        return [
            'transactions' => $transactions,
            'total' => $total
        ];
    }

    /**
     * Get spending summary by category for a period
     * @param int[] $tagIds Optional tag filter (OR logic)
     * @param bool $includeUntagged Include untagged transactions when filtering by tags
     */
    public function getSpendingSummary(
        string $userId,
        string $startDate,
        string $endDate,
        array $tagIds = [],
        bool $includeUntagged = true
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.name', 'c.color', 'c.icon')
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->selectAlias($qb->createFunction('COUNT(DISTINCT t.id)'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->innerJoin('t', 'budget_categories', 'c', $qb->expr()->eq('t.category_id', 'c.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')));

        // Apply tag filtering if requested
        $this->applyTagFilter($qb, $tagIds, $includeUntagged);

        $qb->groupBy('c.id', 'c.name', 'c.color', 'c.icon')
            ->orderBy('total', 'DESC');

        $result = $qb->executeQuery();
        $summary = $result->fetchAll();
        $result->closeCursor();

        return $summary;
    }

    /**
     * Get spending grouped by month
     */
    public function getSpendingByMonth(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();

        // Use SUBSTR with CAST for month extraction (compatible with SQLite, MySQL, PostgreSQL)
        $qb->select($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7) as month'))
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->selectAlias($qb->func()->count('t.id'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        $qb->groupBy($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)'))
            ->orderBy($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)'), 'ASC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        return $data;
    }

    /**
     * Get spending grouped by vendor
     */
    public function getSpendingByVendor(string $userId, ?int $accountId, string $startDate, string $endDate, int $limit = 15): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('t.vendor')
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->selectAlias($qb->func()->count('t.id'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->isNotNull('t.vendor'))
            ->andWhere($qb->expr()->neq('t.vendor', $qb->createNamedParameter('')));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        $qb->groupBy('t.vendor')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        return array_map(fn($row) => [
            'name' => $row['vendor'] ?: 'Unknown',
            'total' => (float)$row['total'],
            'count' => (int)$row['count']
        ], $data);
    }

    /**
     * Get income grouped by month
     */
    public function getIncomeByMonth(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7) as month'))
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->selectAlias($qb->func()->count('t.id'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('credit')))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        $qb->groupBy($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)'))
            ->orderBy($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)'), 'ASC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        return $data;
    }

    /**
     * Get income grouped by source (vendor)
     */
    public function getIncomeBySource(string $userId, ?int $accountId, string $startDate, string $endDate, int $limit = 15): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('t.vendor')
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->selectAlias($qb->func()->count('t.id'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('credit')))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        $qb->groupBy('t.vendor')
            ->orderBy('total', 'DESC')
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        return array_map(fn($row) => [
            'name' => $row['vendor'] ?: 'Unknown Source',
            'total' => (float)$row['total'],
            'count' => (int)$row['count']
        ], $data);
    }

    /**
     * Get cash flow data by month (income and expenses combined) - OPTIMIZED single query
     * @param int[] $tagIds Optional tag filter (OR logic)
     * @param bool $includeUntagged Include untagged transactions when filtering by tags
     */
    public function getCashFlowByMonth(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate,
        array $tagIds = [],
        bool $includeUntagged = true
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7) as month'))
            ->selectAlias(
                $qb->createFunction('SUM(CASE WHEN t.type = \'credit\' THEN t.amount ELSE 0 END)'),
                'income'
            )
            ->selectAlias(
                $qb->createFunction('SUM(CASE WHEN t.type = \'debit\' THEN t.amount ELSE 0 END)'),
                'expenses'
            )
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        // Apply tag filtering if requested
        $this->applyTagFilter($qb, $tagIds, $includeUntagged);

        $qb->groupBy($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)'))
            ->orderBy($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)'), 'ASC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        return array_map(fn($row) => [
            'month' => $row['month'],
            'income' => (float)$row['income'],
            'expenses' => (float)$row['expenses'],
            'net' => (float)$row['income'] - (float)$row['expenses']
        ], $data);
    }

    /**
     * Get aggregated income/expenses per account for a date range (avoids N+1)
     * @param int[] $tagIds Optional tag filter (OR logic)
     * @param bool $includeUntagged Include untagged transactions when filtering by tags
     * @return array<int, array{income: float, expenses: float, count: int}>
     */
    public function getAccountSummaries(
        string $userId,
        string $startDate,
        string $endDate,
        array $tagIds = [],
        bool $includeUntagged = true
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('t.account_id')
            ->selectAlias(
                $qb->createFunction('SUM(CASE WHEN t.type = \'credit\' THEN t.amount ELSE 0 END)'),
                'income'
            )
            ->selectAlias(
                $qb->createFunction('SUM(CASE WHEN t.type = \'debit\' THEN t.amount ELSE 0 END)'),
                'expenses'
            )
            ->selectAlias($qb->createFunction('COUNT(DISTINCT t.id)'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        // Apply tag filtering if requested
        $this->applyTagFilter($qb, $tagIds, $includeUntagged);

        $qb->groupBy('t.account_id');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        $summaries = [];
        foreach ($data as $row) {
            $summaries[(int)$row['account_id']] = [
                'income' => (float)$row['income'],
                'expenses' => (float)$row['expenses'],
                'count' => (int)$row['count']
            ];
        }

        return $summaries;
    }

    /**
     * Get spending totals for multiple categories at once (avoids N+1)
     * @param int[] $categoryIds
     * @return array<int, float> categoryId => total spending
     */
    public function getCategorySpendingBatch(array $categoryIds, string $startDate, string $endDate): array {
        if (empty($categoryIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select('t.category_id')
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->from($this->getTableName(), 't')
            ->where($qb->expr()->in('t.category_id', $qb->createNamedParameter($categoryIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->groupBy('t.category_id');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        $spending = [];
        foreach ($data as $row) {
            $spending[(int)$row['category_id']] = (float)$row['total'];
        }

        return $spending;
    }

    /**
     * Get spending by account with aggregation in SQL (avoids N+1)
     */
    public function getSpendingByAccountAggregated(string $userId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('a.id', 'a.name')
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->selectAlias($qb->func()->count('t.id'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->groupBy('a.id', 'a.name')
            ->orderBy('total', 'DESC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        return array_map(fn($row) => [
            'name' => $row['name'],
            'total' => (float)$row['total'],
            'count' => (int)$row['count'],
            'average' => (int)$row['count'] > 0 ? (float)$row['total'] / (int)$row['count'] : 0
        ], $data);
    }

    /**
     * Calculate the net effect of transactions after a given date for an account.
     * Used to derive "balance as of date" by subtracting from stored balance.
     *
     * @param int $accountId
     * @param string $afterDate Transactions strictly after this date are summed
     * @return float Net effect (credits positive, debits negative)
     */
    public function getNetChangeAfterDate(int $accountId, string $afterDate): float {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias(
                $qb->createFunction('COALESCE(SUM(CASE WHEN t.type = \'credit\' THEN t.amount ELSE -t.amount END), 0)'),
                'net_change'
            )
            ->from($this->getTableName(), 't')
            ->where($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gt('t.date', $qb->createNamedParameter($afterDate)));

        $result = $qb->executeQuery();
        $netChange = (float)$result->fetchOne();
        $result->closeCursor();

        return $netChange;
    }

    /**
     * Calculate the net effect of future transactions for multiple accounts (batch version).
     * Used to derive "balance as of date" by subtracting from stored balances.
     *
     * @param string $userId
     * @param string $afterDate Transactions strictly after this date are summed
     * @return array<int, float> accountId => net change (credits positive, debits negative)
     */
    public function getNetChangeAfterDateBatch(string $userId, string $afterDate): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('t.account_id')
            ->selectAlias(
                $qb->createFunction('SUM(CASE WHEN t.type = \'credit\' THEN t.amount ELSE -t.amount END)'),
                'net_change'
            )
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gt('t.date', $qb->createNamedParameter($afterDate)))
            ->groupBy('t.account_id');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        $changes = [];
        foreach ($data as $row) {
            $changes[(int)$row['account_id']] = (float)$row['net_change'];
        }

        return $changes;
    }

    /**
     * Get daily balance changes for an account (for efficient balance history calculation)
     * @return array<string, float> date => net change (credits positive, debits negative)
     */
    public function getDailyBalanceChanges(int $accountId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('t.date')
            ->selectAlias(
                $qb->createFunction('SUM(CASE WHEN t.type = \'credit\' THEN t.amount ELSE -t.amount END)'),
                'net_change'
            )
            ->from($this->getTableName(), 't')
            ->where($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->groupBy('t.date')
            ->orderBy('t.date', 'DESC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        $changes = [];
        foreach ($data as $row) {
            $changes[$row['date']] = (float)$row['net_change'];
        }

        return $changes;
    }

    /**
     * Get monthly aggregates for trend data (single query for all months)
     * @param int[] $tagIds Optional tag filter (OR logic)
     * @param bool $includeUntagged Include untagged transactions when filtering by tags
     */
    public function getMonthlyTrendData(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate,
        array $tagIds = [],
        bool $includeUntagged = true
    ): array {
        $qb = $this->db->getQueryBuilder();

        // SQLite-compatible: dates stored as TEXT in YYYY-MM-DD format, no need for CAST
        $qb->select($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7) as month'))
            ->selectAlias(
                $qb->createFunction('SUM(CASE WHEN t.type = \'credit\' THEN t.amount ELSE 0 END)'),
                'income'
            )
            ->selectAlias(
                $qb->createFunction('SUM(CASE WHEN t.type = \'debit\' THEN t.amount ELSE 0 END)'),
                'expenses'
            )
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        // Apply tag filtering if requested
        $this->applyTagFilter($qb, $tagIds, $includeUntagged);

        $qb->groupBy($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)'))
            ->orderBy($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)'), 'ASC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        return array_map(fn($row) => [
            'month' => $row['month'],
            'income' => (float)$row['income'],
            'expenses' => (float)$row['expenses']
        ], $data);
    }

    /**
     * Find potential transfer matches for a transaction
     * Matches on: same amount, opposite type, different account, within date window
     *
     * @return Transaction[]
     */
    public function findPotentialMatches(
        string $userId,
        int $transactionId,
        int $accountId,
        float $amount,
        string $type,
        string $date,
        int $dateWindowDays = 3
    ): array {
        $qb = $this->db->getQueryBuilder();

        // Calculate date window
        $dateObj = new \DateTime($date);
        $startDate = (clone $dateObj)->modify("-{$dateWindowDays} days")->format('Y-m-d');
        $endDate = (clone $dateObj)->modify("+{$dateWindowDays} days")->format('Y-m-d');

        // Opposite type for transfer matching
        $oppositeType = $type === 'credit' ? 'debit' : 'credit';

        $qb->select('t.*')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            // Different account
            ->andWhere($qb->expr()->neq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            // Same amount
            ->andWhere($qb->expr()->eq('t.amount', $qb->createNamedParameter($amount)))
            // Opposite type (debit in one account, credit in another)
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter($oppositeType)))
            // Within date window
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            // Not already linked
            ->andWhere($qb->expr()->isNull('t.linked_transaction_id'))
            // Not the same transaction
            ->andWhere($qb->expr()->neq('t.id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)))
            ->orderBy('t.date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Link two transactions together
     */
    public function linkTransactions(int $transactionId1, int $transactionId2): void {
        // Update first transaction
        $qb1 = $this->db->getQueryBuilder();
        $qb1->update($this->getTableName())
            ->set('linked_transaction_id', $qb1->createNamedParameter($transactionId2, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb1->createNamedParameter(date('Y-m-d H:i:s')))
            ->where($qb1->expr()->eq('id', $qb1->createNamedParameter($transactionId1, IQueryBuilder::PARAM_INT)));
        $qb1->executeStatement();

        // Update second transaction
        $qb2 = $this->db->getQueryBuilder();
        $qb2->update($this->getTableName())
            ->set('linked_transaction_id', $qb2->createNamedParameter($transactionId1, IQueryBuilder::PARAM_INT))
            ->set('updated_at', $qb2->createNamedParameter(date('Y-m-d H:i:s')))
            ->where($qb2->expr()->eq('id', $qb2->createNamedParameter($transactionId2, IQueryBuilder::PARAM_INT)));
        $qb2->executeStatement();
    }

    /**
     * Unlink a transaction from its linked partner
     */
    public function unlinkTransaction(int $transactionId): ?int {
        // First get the linked transaction ID
        $qb = $this->db->getQueryBuilder();
        $qb->select('linked_transaction_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $linkedId = $result->fetchOne();
        $result->closeCursor();

        if (!$linkedId) {
            return null;
        }

        // Clear both links
        $qb1 = $this->db->getQueryBuilder();
        $qb1->update($this->getTableName())
            ->set('linked_transaction_id', $qb1->createNamedParameter(null))
            ->set('updated_at', $qb1->createNamedParameter(date('Y-m-d H:i:s')))
            ->where($qb1->expr()->eq('id', $qb1->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)));
        $qb1->executeStatement();

        $qb2 = $this->db->getQueryBuilder();
        $qb2->update($this->getTableName())
            ->set('linked_transaction_id', $qb2->createNamedParameter(null))
            ->set('updated_at', $qb2->createNamedParameter(date('Y-m-d H:i:s')))
            ->where($qb2->expr()->eq('id', $qb2->createNamedParameter((int)$linkedId, IQueryBuilder::PARAM_INT)));
        $qb2->executeStatement();

        return (int)$linkedId;
    }

    /**
     * Find all unlinked transactions for a user with their potential matches
     * Returns transactions grouped with match counts for bulk matching
     *
     * @param string $userId
     * @param int $dateWindowDays
     * @param int $limit Batch size limit
     * @param int $offset Batch offset
     * @return array Array with 'transactions' (unlinked transactions with matches) and 'total' count
     */
    public function findUnlinkedWithMatches(
        string $userId,
        int $dateWindowDays = 3,
        int $limit = 100,
        int $offset = 0
    ): array {
        // First, get count of all unlinked transactions
        $countQb = $this->db->getQueryBuilder();
        $countQb->select($countQb->createFunction('COUNT(DISTINCT t.id)'))
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $countQb->expr()->eq('t.account_id', 'a.id'))
            ->where($countQb->expr()->eq('a.user_id', $countQb->createNamedParameter($userId)))
            ->andWhere($countQb->expr()->isNull('t.linked_transaction_id'));

        $countResult = $countQb->executeQuery();
        $total = (int)$countResult->fetchOne();
        $countResult->closeCursor();

        if ($total === 0) {
            return ['transactions' => [], 'total' => 0];
        }

        // Get batch of unlinked transactions
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.*', 'a.name as account_name', 'a.currency as account_currency')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->isNull('t.linked_transaction_id'))
            ->orderBy('t.date', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit);

        $result = $qb->executeQuery();
        $unlinkedTransactions = $result->fetchAll();
        $result->closeCursor();

        // For each unlinked transaction, find potential matches
        $transactionsWithMatches = [];
        foreach ($unlinkedTransactions as $tx) {
            $matches = $this->findPotentialMatches(
                $userId,
                (int)$tx['id'],
                (int)$tx['account_id'],
                (float)$tx['amount'],
                $tx['type'],
                $tx['date'],
                $dateWindowDays
            );

            if (count($matches) > 0) {
                // Convert Transaction entities to arrays and add account info
                $matchArrays = [];
                foreach ($matches as $match) {
                    $matchArray = $match->jsonSerialize();
                    // Get account info for the match
                    $matchAccountQb = $this->db->getQueryBuilder();
                    $matchAccountQb->select('name', 'currency')
                        ->from('budget_accounts')
                        ->where($matchAccountQb->expr()->eq('id', $matchAccountQb->createNamedParameter($match->getAccountId(), IQueryBuilder::PARAM_INT)));
                    $matchAccountResult = $matchAccountQb->executeQuery();
                    $matchAccount = $matchAccountResult->fetch();
                    $matchAccountResult->closeCursor();

                    if ($matchAccount) {
                        $matchArray['accountName'] = $matchAccount['name'];
                        $matchArray['accountCurrency'] = $matchAccount['currency'];
                    }
                    $matchArrays[] = $matchArray;
                }

                $transactionsWithMatches[] = [
                    'transaction' => $tx,
                    'matches' => $matchArrays,
                    'matchCount' => count($matches)
                ];
            }
        }

        return [
            'transactions' => $transactionsWithMatches,
            'total' => $total
        ];
    }

    /**
     * Get spending for a single category within a date range for a user.
     * Only counts non-split debit transactions.
     */
    public function getCategorySpending(string $userId, int $categoryId, string $startDate, string $endDate): float {
        $qb = $this->db->getQueryBuilder();

        $qb->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('t.category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($qb->expr()->orX(
                $qb->expr()->eq('t.is_split', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
                $qb->expr()->isNull('t.is_split')
            ));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        return (float)($row['total'] ?? 0);
    }

    /**
     * Get IDs of split transactions within a date range for a user.
     * Used to calculate spending from splits.
     *
     * @return int[]
     */
    public function getSplitTransactionIds(string $userId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('t.id')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($qb->expr()->eq('t.is_split', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        return array_map(fn($row) => (int)$row['id'], $data);
    }

    /**
     * Delete all transactions for a user (via account ownership)
     *
     * @param string $userId
     * @return int Number of deleted rows
     */
    public function deleteAll(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->delete('t')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        return $qb->executeStatement();
    }

    // ==================== TAG-BASED REPORTING METHODS ====================

    /**
     * Apply tag filtering to a query builder
     * Joins through budget_transaction_tags and filters by tag IDs (OR logic)
     *
     * @param IQueryBuilder $qb Query builder to modify
     * @param int[] $tagIds Array of tag IDs to filter by
     * @param bool $includeUntagged Include transactions without tags
     */
    private function applyTagFilter(IQueryBuilder $qb, array $tagIds, bool $includeUntagged = true): void {
        if (empty($tagIds)) {
            return;
        }

        if ($includeUntagged) {
            // Include transactions with specified tags OR no tags
            $qb->leftJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
                ->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->in('tt.tag_id', $qb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)),
                        $qb->expr()->isNull('tt.tag_id')
                    )
                );
        } else {
            // Only transactions with specified tags
            $qb->innerJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
                ->andWhere($qb->expr()->in('tt.tag_id', $qb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)));
        }
    }

    /**
     * Get spending grouped by tags within a specific tag set
     *
     * @param string $userId
     * @param int $tagSetId Tag set to group by
     * @param string $startDate
     * @param string $endDate
     * @param int|null $accountId Optional account filter
     * @param int|null $categoryId Optional category filter
     * @return array Array of [tagId, tagName, color, total, count]
     */
    public function getSpendingByTag(
        string $userId,
        int $tagSetId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('tag.id', 'tag.name', 'tag.color')
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->selectAlias($qb->createFunction('COUNT(DISTINCT t.id)'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->innerJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
            ->innerJoin('tt', 'budget_tags', 'tag', $qb->expr()->eq('tt.tag_id', 'tag.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('tag.tag_set_id', $qb->createNamedParameter($tagSetId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        if ($categoryId !== null) {
            $qb->andWhere($qb->expr()->eq('t.category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
        }

        $qb->groupBy('tag.id', 'tag.name', 'tag.color')
            ->orderBy('total', 'DESC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        return array_map(fn($row) => [
            'tagId' => (int)$row['id'],
            'name' => $row['name'],
            'color' => $row['color'],
            'total' => (float)$row['total'],
            'count' => (int)$row['count']
        ], $data);
    }

    /**
     * Get income grouped by tags within a specific tag set
     *
     * @param string $userId
     * @param int $tagSetId Tag set to group by
     * @param string $startDate
     * @param string $endDate
     * @param int|null $accountId Optional account filter
     * @param int|null $categoryId Optional category filter
     * @return array Array of [tagId, tagName, color, total, count]
     */
    public function getIncomeByTag(
        string $userId,
        int $tagSetId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('tag.id', 'tag.name', 'tag.color')
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->selectAlias($qb->createFunction('COUNT(DISTINCT t.id)'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->innerJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
            ->innerJoin('tt', 'budget_tags', 'tag', $qb->expr()->eq('tt.tag_id', 'tag.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('tag.tag_set_id', $qb->createNamedParameter($tagSetId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('credit')))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        if ($categoryId !== null) {
            $qb->andWhere($qb->expr()->eq('t.category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
        }

        $qb->groupBy('tag.id', 'tag.name', 'tag.color')
            ->orderBy('total', 'DESC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        return array_map(fn($row) => [
            'tagId' => (int)$row['id'],
            'name' => $row['name'],
            'color' => $row['color'],
            'total' => (float)$row['total'],
            'count' => (int)$row['count']
        ], $data);
    }

    /**
     * Get tag dimensions breakdown for spending in a category
     * Returns spending grouped by each tag set associated with the category
     *
     * @param string $userId
     * @param int $categoryId
     * @param string $startDate
     * @param string $endDate
     * @param int|null $accountId Optional account filter
     * @return array Array indexed by tag set ID, containing tag breakdowns
     */
    public function getTagDimensionsForCategory(
        string $userId,
        int $categoryId,
        string $startDate,
        string $endDate,
        ?int $accountId = null
    ): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select('ts.id as tag_set_id', 'ts.name as tag_set_name', 'tag.id as tag_id', 'tag.name as tag_name', 'tag.color')
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->selectAlias($qb->createFunction('COUNT(DISTINCT t.id)'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->innerJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
            ->innerJoin('tt', 'budget_tags', 'tag', $qb->expr()->eq('tt.tag_id', 'tag.id'))
            ->innerJoin('tag', 'budget_tag_sets', 'ts', $qb->expr()->eq('tag.tag_set_id', 'ts.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('t.category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('ts.category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        $qb->groupBy('ts.id', 'ts.name', 'tag.id', 'tag.name', 'tag.color')
            ->orderBy('ts.id', 'ASC')
            ->addOrderBy('total', 'DESC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        // Group by tag set
        $dimensions = [];
        foreach ($data as $row) {
            $tagSetId = (int)$row['tag_set_id'];
            if (!isset($dimensions[$tagSetId])) {
                $dimensions[$tagSetId] = [
                    'tagSetId' => $tagSetId,
                    'tagSetName' => $row['tag_set_name'],
                    'tags' => []
                ];
            }
            $dimensions[$tagSetId]['tags'][] = [
                'tagId' => (int)$row['tag_id'],
                'name' => $row['tag_name'],
                'color' => $row['color'],
                'total' => (float)$row['total'],
                'count' => (int)$row['count']
            ];
        }

        return array_values($dimensions);
    }

    /**
     * Get spending by tag combinations (transactions with specific sets of tags)
     *
     * @param string $userId
     * @param string $startDate
     * @param string $endDate
     * @param int|null $accountId Optional account filter
     * @param int|null $categoryId Optional category filter
     * @param int $minCombinationSize Minimum number of tags in combination (default 2)
     * @param int $limit Maximum number of combinations to return
     * @return array Array of [tagIds => int[], tagNames => string[], total, count]
     */
    public function getSpendingByTagCombination(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null,
        int $minCombinationSize = 2,
        int $limit = 50
    ): array {
        // This requires aggregating transaction IDs with their tag sets
        // Step 1: Get all transactions with their tags
        $qb = $this->db->getQueryBuilder();

        $qb->select('t.id', 't.amount', 'tag.id as tag_id', 'tag.name as tag_name')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->innerJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
            ->innerJoin('tt', 'budget_tags', 'tag', $qb->expr()->eq('tt.tag_id', 'tag.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        if ($categoryId !== null) {
            $qb->andWhere($qb->expr()->eq('t.category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('t.id', 'ASC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        // Group tags by transaction
        $transactionTags = [];
        $transactionAmounts = [];
        foreach ($data as $row) {
            $txId = (int)$row['id'];
            if (!isset($transactionTags[$txId])) {
                $transactionTags[$txId] = [];
                $transactionAmounts[$txId] = (float)$row['amount'];
            }
            $transactionTags[$txId][] = [
                'id' => (int)$row['tag_id'],
                'name' => $row['tag_name']
            ];
        }

        // Group by tag combination
        $combinations = [];
        foreach ($transactionTags as $txId => $tags) {
            if (count($tags) < $minCombinationSize) {
                continue;
            }

            // Sort tags by ID for consistent combination key
            usort($tags, fn($a, $b) => $a['id'] <=> $b['id']);
            $tagIds = array_column($tags, 'id');
            $tagNames = array_column($tags, 'name');
            $key = implode(',', $tagIds);

            if (!isset($combinations[$key])) {
                $combinations[$key] = [
                    'tagIds' => $tagIds,
                    'tagNames' => $tagNames,
                    'total' => 0,
                    'count' => 0
                ];
            }

            $combinations[$key]['total'] += $transactionAmounts[$txId];
            $combinations[$key]['count']++;
        }

        // Sort by total descending
        usort($combinations, fn($a, $b) => $b['total'] <=> $a['total']);

        return array_slice($combinations, 0, $limit);
    }

    /**
     * Get cross-tabulation (pivot table) of spending by two tag sets
     * Returns a matrix where rows are tags from tagSet1 and columns are tags from tagSet2
     *
     * @param string $userId
     * @param int $tagSetId1 First tag set (rows)
     * @param int $tagSetId2 Second tag set (columns)
     * @param string $startDate
     * @param string $endDate
     * @param int|null $accountId Optional account filter
     * @param int|null $categoryId Optional category filter
     * @return array ['rows' => tags from set 1, 'columns' => tags from set 2, 'data' => matrix]
     */
    public function getTagCrossTabulation(
        string $userId,
        int $tagSetId1,
        int $tagSetId2,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null
    ): array {
        // Get all transactions with tags from both sets
        $qb = $this->db->getQueryBuilder();

        $qb->select('t.id', 't.amount', 'tag.id as tag_id', 'tag.name as tag_name', 'tag.tag_set_id', 'tag.color')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->innerJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
            ->innerJoin('tt', 'budget_tags', 'tag', $qb->expr()->eq('tt.tag_id', 'tag.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('tag.tag_set_id', $qb->createNamedParameter([$tagSetId1, $tagSetId2], IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        if ($categoryId !== null) {
            $qb->andWhere($qb->expr()->eq('t.category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('t.id', 'ASC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        // Organize data by transaction
        $transactionData = [];
        $rowTags = []; // Tags from tagSet1
        $colTags = []; // Tags from tagSet2

        foreach ($data as $row) {
            $txId = (int)$row['id'];
            $tagId = (int)$row['tag_id'];
            $tagSetId = (int)$row['tag_set_id'];

            if (!isset($transactionData[$txId])) {
                $transactionData[$txId] = [
                    'amount' => (float)$row['amount'],
                    'tag1' => null,
                    'tag2' => null
                ];
            }

            if ($tagSetId === $tagSetId1) {
                $transactionData[$txId]['tag1'] = $tagId;
                if (!isset($rowTags[$tagId])) {
                    $rowTags[$tagId] = [
                        'id' => $tagId,
                        'name' => $row['tag_name'],
                        'color' => $row['color']
                    ];
                }
            } elseif ($tagSetId === $tagSetId2) {
                $transactionData[$txId]['tag2'] = $tagId;
                if (!isset($colTags[$tagId])) {
                    $colTags[$tagId] = [
                        'id' => $tagId,
                        'name' => $row['tag_name'],
                        'color' => $row['color']
                    ];
                }
            }
        }

        // Build the matrix
        $matrix = [];
        foreach ($transactionData as $tx) {
            if ($tx['tag1'] !== null && $tx['tag2'] !== null) {
                $key = $tx['tag1'] . '_' . $tx['tag2'];
                if (!isset($matrix[$key])) {
                    $matrix[$key] = [
                        'rowTagId' => $tx['tag1'],
                        'colTagId' => $tx['tag2'],
                        'total' => 0,
                        'count' => 0
                    ];
                }
                $matrix[$key]['total'] += $tx['amount'];
                $matrix[$key]['count']++;
            }
        }

        return [
            'rows' => array_values($rowTags),
            'columns' => array_values($colTags),
            'data' => array_values($matrix)
        ];
    }

    /**
     * Get monthly trend data for specific tags
     *
     * @param string $userId
     * @param int[] $tagIds Tags to track
     * @param string $startDate
     * @param string $endDate
     * @param int|null $accountId Optional account filter
     * @return array Array of [month, tagId, tagName, amount]
     */
    public function getTagTrendByMonth(
        string $userId,
        array $tagIds,
        string $startDate,
        string $endDate,
        ?int $accountId = null
    ): array {
        if (empty($tagIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7) as month'))
            ->addSelect('tag.id as tag_id', 'tag.name as tag_name', 'tag.color')
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->innerJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
            ->innerJoin('tt', 'budget_tags', 'tag', $qb->expr()->eq('tt.tag_id', 'tag.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('tag.id', $qb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        $qb->groupBy($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)'), 'tag.id', 'tag.name', 'tag.color')
            ->orderBy($qb->createFunction('SUBSTR(CAST(t.date AS CHAR(10)), 1, 7)'), 'ASC')
            ->addOrderBy('tag.id', 'ASC');

        $result = $qb->executeQuery();
        $data = $result->fetchAll();
        $result->closeCursor();

        return array_map(fn($row) => [
            'month' => $row['month'],
            'tagId' => (int)$row['tag_id'],
            'tagName' => $row['tag_name'],
            'color' => $row['color'],
            'total' => (float)$row['total']
        ], $data);
    }
}