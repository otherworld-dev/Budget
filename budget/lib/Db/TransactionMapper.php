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
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_transactions', Transaction::class);
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
        $qb = $this->db->getQueryBuilder();

        // Base query
        $qb->select('t.*')
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));

        // Apply filters
        if (!empty($filters['accountId'])) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($filters['accountId'], IQueryBuilder::PARAM_INT)));
        }

        if (!empty($filters['category'])) {
            if ($filters['category'] === 'uncategorized') {
                $qb->andWhere($qb->expr()->isNull('t.category_id'));
            } else {
                $qb->andWhere($qb->expr()->eq('t.category_id', $qb->createNamedParameter($filters['category'], IQueryBuilder::PARAM_INT)));
            }
        }

        if (!empty($filters['type'])) {
            $qb->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter($filters['type'])));
        }

        if (!empty($filters['dateFrom'])) {
            $qb->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($filters['dateFrom'])));
        }

        if (!empty($filters['dateTo'])) {
            $qb->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($filters['dateTo'])));
        }

        if (!empty($filters['amountMin'])) {
            $qb->andWhere($qb->expr()->gte('t.amount', $qb->createNamedParameter($filters['amountMin'])));
        }

        if (!empty($filters['amountMax'])) {
            $qb->andWhere($qb->expr()->lte('t.amount', $qb->createNamedParameter($filters['amountMax'])));
        }

        if (!empty($filters['search'])) {
            $searchPattern = '%' . $qb->escapeLikeParameter($filters['search']) . '%';
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like('t.description', $qb->createNamedParameter($searchPattern)),
                    $qb->expr()->like('t.vendor', $qb->createNamedParameter($searchPattern)),
                    $qb->expr()->like('t.reference', $qb->createNamedParameter($searchPattern)),
                    $qb->expr()->like('t.notes', $qb->createNamedParameter($searchPattern))
                )
            );
        }

        // Count total records for pagination - create a fresh query builder
        $countQb = $this->db->getQueryBuilder();
        $countQb->select($countQb->func()->count('t.id'))
            ->from($this->getTableName(), 't')
            ->innerJoin('t', 'budget_accounts', 'a', $countQb->expr()->eq('t.account_id', 'a.id'))
            ->where($countQb->expr()->eq('a.user_id', $countQb->createNamedParameter($userId)));

        // Apply the same filters to count query
        if (!empty($filters['accountId'])) {
            $countQb->andWhere($countQb->expr()->eq('t.account_id', $countQb->createNamedParameter($filters['accountId'], IQueryBuilder::PARAM_INT)));
        }
        if (!empty($filters['category'])) {
            if ($filters['category'] === 'uncategorized') {
                $countQb->andWhere($countQb->expr()->isNull('t.category_id'));
            } else {
                $countQb->andWhere($countQb->expr()->eq('t.category_id', $countQb->createNamedParameter($filters['category'], IQueryBuilder::PARAM_INT)));
            }
        }
        if (!empty($filters['type'])) {
            $countQb->andWhere($countQb->expr()->eq('t.type', $countQb->createNamedParameter($filters['type'])));
        }
        if (!empty($filters['dateFrom'])) {
            $countQb->andWhere($countQb->expr()->gte('t.date', $countQb->createNamedParameter($filters['dateFrom'])));
        }
        if (!empty($filters['dateTo'])) {
            $countQb->andWhere($countQb->expr()->lte('t.date', $countQb->createNamedParameter($filters['dateTo'])));
        }
        if (!empty($filters['amountMin'])) {
            $countQb->andWhere($countQb->expr()->gte('t.amount', $countQb->createNamedParameter($filters['amountMin'])));
        }
        if (!empty($filters['amountMax'])) {
            $countQb->andWhere($countQb->expr()->lte('t.amount', $countQb->createNamedParameter($filters['amountMax'])));
        }
        if (!empty($filters['search'])) {
            $searchPattern = '%' . $countQb->escapeLikeParameter($filters['search']) . '%';
            $countQb->andWhere(
                $countQb->expr()->orX(
                    $countQb->expr()->like('t.description', $countQb->createNamedParameter($searchPattern)),
                    $countQb->expr()->like('t.vendor', $countQb->createNamedParameter($searchPattern)),
                    $countQb->expr()->like('t.reference', $countQb->createNamedParameter($searchPattern)),
                    $countQb->expr()->like('t.notes', $countQb->createNamedParameter($searchPattern))
                )
            );
        }

        $countResult = $countQb->executeQuery();
        $total = (int)$countResult->fetchOne();
        $countResult->closeCursor();

        // Apply sorting
        $sortField = $filters['sort'] ?? 'date';
        $sortDirection = strtoupper($filters['direction'] ?? 'DESC');

        // Map frontend sort fields to database fields
        $sortFieldMap = [
            'date' => 't.date',
            'description' => 't.description',
            'amount' => 't.amount',
            'type' => 't.type',
            'category' => 't.category_id',
            'account' => 't.account_id'
        ];

        $dbSortField = $sortFieldMap[$sortField] ?? 't.date';
        $qb->orderBy($dbSortField, $sortDirection);

        // Add secondary sort by ID for consistency
        $qb->addOrderBy('t.id', 'DESC');

        // Apply pagination
        $qb->setMaxResults($limit);
        $qb->setFirstResult($offset);

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
}