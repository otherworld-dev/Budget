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
     */
    public function getSpendingSummary(string $userId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('c.id', 'c.name', 'c.color', 'c.icon')
            ->selectAlias($qb->func()->sum('t.amount'), 'total')
            ->selectAlias($qb->func()->count('t.id'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->innerJoin('t', 'budget_categories', 'c', $qb->expr()->eq('t.category_id', 'c.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
            ->groupBy('c.id', 'c.name', 'c.color', 'c.icon')
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

        // Use SUBSTR for month extraction (compatible with SQLite, MySQL, PostgreSQL)
        $qb->select($qb->createFunction('SUBSTR(t.date, 1, 7) as month'))
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

        $qb->groupBy($qb->createFunction('SUBSTR(t.date, 1, 7)'))
            ->orderBy($qb->createFunction('SUBSTR(t.date, 1, 7)'), 'ASC');

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

        $qb->select($qb->createFunction('SUBSTR(t.date, 1, 7) as month'))
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

        $qb->groupBy($qb->createFunction('SUBSTR(t.date, 1, 7)'))
            ->orderBy($qb->createFunction('SUBSTR(t.date, 1, 7)'), 'ASC');

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
     */
    public function getCashFlowByMonth(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('SUBSTR(t.date, 1, 7) as month'))
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

        $qb->groupBy($qb->createFunction('SUBSTR(t.date, 1, 7)'))
            ->orderBy($qb->createFunction('SUBSTR(t.date, 1, 7)'), 'ASC');

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
     * @return array<int, array{income: float, expenses: float, count: int}>
     */
    public function getAccountSummaries(string $userId, string $startDate, string $endDate): array {
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
            ->selectAlias($qb->func()->count('t.id'), 'count')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)))
            ->groupBy('t.account_id');

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
     */
    public function getMonthlyTrendData(string $userId, ?int $accountId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();

        $qb->select($qb->createFunction('SUBSTR(t.date, 1, 7) as month'))
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

        $qb->groupBy($qb->createFunction('SUBSTR(t.date, 1, 7)'))
            ->orderBy($qb->createFunction('SUBSTR(t.date, 1, 7)'), 'ASC');

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
}