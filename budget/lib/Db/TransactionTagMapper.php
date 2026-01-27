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
            ->innerJoin('tt', 'budget_transactions', 't', 'tt.transaction_id = t.id')
            ->where($qb->expr()->in('tt.tag_id', $qb->createNamedParameter($tagIds, IQueryBuilder::PARAM_INT_ARRAY)))
            ->andWhere($qb->expr()->eq('t.user_id', $qb->createNamedParameter($userId)));

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
            ->innerJoin('tt', 'budget_transactions', 't', 'tt.transaction_id = t.id')
            ->where($qb->expr()->eq('t.user_id', $qb->createNamedParameter($userId)))
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
            ->innerJoin('tt', 'budget_transactions', 't', 'tt.transaction_id = t.id')
            ->where($qb->expr()->eq('t.user_id', $qb->createNamedParameter($userId)));

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
}
