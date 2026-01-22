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
        $qb = $this->db->getQueryBuilder();

        $qb->delete('s')
            ->from($this->getTableName(), 's')
            ->innerJoin('s', 'budget_transactions', 't', $qb->expr()->eq('s.transaction_id', 't.id'))
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        return $qb->executeStatement();
    }
}
