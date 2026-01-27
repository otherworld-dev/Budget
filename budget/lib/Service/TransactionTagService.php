<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTag;
use OCA\Budget\Db\TransactionTagMapper;
use OCP\IDBConnection;

class TransactionTagService {
    private TransactionTagMapper $transactionTagMapper;
    private TagMapper $tagMapper;
    private TransactionMapper $transactionMapper;
    private IDBConnection $db;

    public function __construct(
        TransactionTagMapper $transactionTagMapper,
        TagMapper $tagMapper,
        TransactionMapper $transactionMapper,
        IDBConnection $db
    ) {
        $this->transactionTagMapper = $transactionTagMapper;
        $this->tagMapper = $tagMapper;
        $this->transactionMapper = $transactionMapper;
        $this->db = $db;
    }

    /**
     * Set tags for a transaction (replaces existing tags)
     *
     * @param int $transactionId
     * @param string $userId
     * @param int[] $tagIds
     * @return TransactionTag[] The created transaction tags
     */
    public function setTransactionTags(int $transactionId, string $userId, array $tagIds): array {
        // Validate transaction belongs to user
        $transaction = $this->transactionMapper->find($transactionId, $userId);

        // Validate all tags belong to the transaction's category
        if (!empty($tagIds)) {
            $this->validateTagsForTransaction($transaction->getCategoryId(), $tagIds, $userId);
        }

        // Remove existing tags
        $this->transactionTagMapper->deleteByTransaction($transactionId);

        if (empty($tagIds)) {
            return [];
        }

        // Create new transaction tags
        $transactionTags = [];
        $now = date('Y-m-d H:i:s');

        foreach ($tagIds as $tagId) {
            $transactionTag = new TransactionTag();
            $transactionTag->setTransactionId($transactionId);
            $transactionTag->setTagId($tagId);
            $transactionTag->setCreatedAt($now);

            $inserted = $this->transactionTagMapper->insert($transactionTag);
            $transactionTags[] = $inserted;
        }

        return $transactionTags;
    }

    /**
     * Get tags for a transaction with full tag details
     *
     * @param int $transactionId
     * @param string $userId
     * @return array Array of tags with tag set information
     */
    public function getTransactionTags(int $transactionId, string $userId): array {
        // Validate transaction belongs to user
        $this->transactionMapper->find($transactionId, $userId);

        // Get transaction tag records
        $transactionTags = $this->transactionTagMapper->findByTransaction($transactionId);

        if (empty($transactionTags)) {
            return [];
        }

        // Batch load tag details
        $tagIds = array_map(fn($tt) => $tt->getTagId(), $transactionTags);
        $tags = $this->tagMapper->findByIds($tagIds);

        // Return tag entities
        return array_values($tags);
    }

    /**
     * Clear all tags from a transaction
     *
     * @param int $transactionId
     * @param string $userId
     */
    public function clearTransactionTags(int $transactionId, string $userId): void {
        // Validate transaction belongs to user
        $this->transactionMapper->find($transactionId, $userId);

        // Remove all tags
        $this->transactionTagMapper->deleteByTransaction($transactionId);
    }

    /**
     * Validate that tag IDs belong to tag sets of the given category
     *
     * @param int $categoryId
     * @param int[] $tagIds
     * @param string $userId
     * @throws \Exception If any tag doesn't belong to the category's tag sets
     */
    private function validateTagsForTransaction(int $categoryId, array $tagIds, string $userId): void {
        if (empty($tagIds)) {
            return;
        }

        // Get all tags
        $tags = $this->tagMapper->findByIds($tagIds);

        if (count($tags) !== count($tagIds)) {
            throw new \Exception('One or more tags do not exist');
        }

        // Get tag set IDs for these tags
        $tagSetIds = array_unique(array_map(fn($tag) => $tag->getTagSetId(), $tags));

        // Verify all tag sets belong to the category
        // This query joins tag_sets -> categories to ensure user ownership
        foreach ($tagSetIds as $tagSetId) {
            // Use a query to verify tag set belongs to category
            $qb = $this->db->getQueryBuilder();
            $qb->select('ts.id')
                ->from('budget_tag_sets', 'ts')
                ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
                ->where($qb->expr()->eq('ts.id', $qb->createNamedParameter($tagSetId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('ts.category_id', $qb->createNamedParameter($categoryId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)));

            $result = $qb->executeQuery();
            $found = $result->fetch();
            $result->closeCursor();

            if (!$found) {
                throw new \Exception('One or more tags do not belong to the transaction\'s category');
            }
        }
    }

    /**
     * Get tag usage statistics for a user
     *
     * @param string $userId
     * @return array<int, int> tagId => usage count
     */
    public function getTagUsageStats(string $userId): array {
        return $this->transactionTagMapper->getTagUsageStats($userId);
    }
}
