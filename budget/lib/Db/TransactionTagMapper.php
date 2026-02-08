<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<TransactionTag>
 */
class TransactionTagMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_transaction_tags', TransactionTag::class);
    }

    /**
     * Find all transaction tags for a specific transaction
     *
     * @return TransactionTag[]
     */
    public function findByTransaction(int $transactionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)));

        return $this->findEntities($qb);
    }

    /**
     * Find all transaction IDs that have any of the specified tags
     * Used for filtering transactions by tags
     *
     * @param int[] $tagIds
     * @param string $userId
     * @return int[] Transaction IDs
     */
    public function findTransactionIdsByTags(array $tagIds, string $userId): array {
        if (empty($tagIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('DISTINCT tt.transaction_id')
            ->from($this->getTableName(), 'tt')
            ->innerJoin('tt', 'budget_transactions', 't', $qb->expr()->eq('tt.transaction_id', 't.id'))
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->in('tt.tag_id', $qb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $transactionIds = $result->fetchAll(\PDO::FETCH_COLUMN);
        $result->closeCursor();

        return array_map('intval', $transactionIds);
    }

    /**
     * Delete all tags for a specific transaction
     *
     * @param int $transactionId
     * @return int Number of deleted rows
     */
    public function deleteByTransaction(int $transactionId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    /**
     * Delete all transaction tags for a specific tag
     *
     * @param int $tagId
     * @return int Number of deleted rows
     */
    public function deleteByTag(int $tagId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('tag_id', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)));

        return $qb->executeStatement();
    }

    /**
     * Batch insert transaction tags
     *
     * @param TransactionTag[] $transactionTags
     * @return void
     */
    public function insertBatch(array $transactionTags): void {
        if (empty($transactionTags)) {
            return;
        }

        foreach ($transactionTags as $transactionTag) {
            $this->insert($transactionTag);
        }
    }

    /**
     * Get tag usage statistics (how many transactions use each tag)
     *
     * @param string $userId
     * @return array<int, int> tagId => count
     */
    public function getTagUsageStats(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('tt.tag_id', $qb->func()->count('tt.id', 'usage_count'))
            ->from($this->getTableName(), 'tt')
            ->innerJoin('tt', 'budget_transactions', 't', $qb->expr()->eq('tt.transaction_id', 't.id'))
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->groupBy('tt.tag_id');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $stats = [];
        foreach ($rows as $row) {
            $stats[(int)$row['tag_id']] = (int)$row['usage_count'];
        }

        return $stats;
    }

    /**
     * Delete all transaction tags for a user
     *
     * @param string $userId
     * @return int Number of deleted rows
     */
    public function deleteAll(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        // Get all transaction tag IDs for this user first
        $qb->select('tt.id')
            ->from($this->getTableName(), 'tt')
            ->innerJoin('tt', 'budget_transactions', 't', $qb->expr()->eq('tt.transaction_id', 't.id'))
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $ids = $result->fetchAll(\PDO::FETCH_COLUMN);
        $result->closeCursor();

        if (empty($ids)) {
            return 0;
        }

        // Delete transaction tags
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->in('id', $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)));

        return $qb->executeStatement();
    }

    /**
     * Calculate the sum of transaction amounts for a specific tag.
     *
     * @param int $tagId
     * @param string $userId
     * @return float
     */
    public function sumTransactionAmountsByTag(int $tagId, string $userId): float {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->createFunction('COALESCE(SUM(t.amount), 0)'))
            ->from($this->getTableName(), 'tt')
            ->innerJoin('tt', 'budget_transactions', 't', $qb->expr()->eq('tt.transaction_id', 't.id'))
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('tt.tag_id', $qb->createNamedParameter($tagId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $sum = $result->fetchOne();
        $result->closeCursor();

        return (float)($sum ?? 0.0);
    }

    /**
     * Calculate the sum of transaction amounts for multiple tags at once.
     *
     * @param int[] $tagIds
     * @param string $userId
     * @return array<int, float> tagId => sum
     */
    public function sumTransactionAmountsByTags(array $tagIds, string $userId): array {
        if (empty($tagIds)) {
            return [];
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('tt.tag_id')
            ->selectAlias($qb->createFunction('COALESCE(SUM(t.amount), 0)'), 'amount_sum')
            ->from($this->getTableName(), 'tt')
            ->innerJoin('tt', 'budget_transactions', 't', $qb->expr()->eq('tt.transaction_id', 't.id'))
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->in('tt.tag_id', $qb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
            ->groupBy('tt.tag_id');

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $sums = [];
        foreach ($rows as $row) {
            $sums[(int)$row['tag_id']] = (float)$row['amount_sum'];
        }

        return $sums;
    }
}
