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
     * Add and/or remove GLOBAL tags across many transactions at once (#379).
     *
     * Global-only is deliberate. A category tag is validated against the
     * transaction's own category (see validateTagsForTransaction), so across a
     * mixed selection it would legitimately apply to only some rows -- and
     * half-applying that quietly is worse than refusing. Global tags carry no
     * such constraint, so the operation is unambiguous for every selected row.
     *
     * Both directions are idempotent: adding a tag a row already carries is a
     * no-op, and removing one it does not carry is too.
     *
     * @param int[] $transactionIds
     * @param int[] $addTagIds
     * @param int[] $removeTagIds
     * @return array{success: int, failed: int, errors: array} Same shape as TransactionService::bulkEdit
     * @throws \Exception If any tag is not a global tag owned by this user
     */
    public function bulkUpdateGlobalTags(string $userId, array $transactionIds, array $addTagIds, array $removeTagIds): array {
        $transactionIds = array_values(array_unique(array_map('intval', $transactionIds)));
        $addTagIds = array_values(array_unique(array_map('intval', $addTagIds)));
        $removeTagIds = array_values(array_unique(array_map('intval', $removeTagIds)));

        $contradictory = array_intersect($addTagIds, $removeTagIds);
        if (!empty($contradictory)) {
            throw new \Exception('A tag cannot be both added and removed');
        }

        $this->assertGlobalTags(array_merge($addTagIds, $removeTagIds), $userId);

        // The id list comes from the browser, so scope it before writing.
        $ownedIds = $this->transactionMapper->filterOwnedIds($transactionIds, $userId);
        $ownedLookup = array_flip($ownedIds);

        $results = ['success' => count($ownedIds), 'failed' => 0, 'errors' => []];
        foreach ($transactionIds as $id) {
            if (!isset($ownedLookup[$id])) {
                $results['failed']++;
                $results['errors'][] = ['id' => $id, 'message' => 'Transaction not found'];
            }
        }

        if (empty($ownedIds)) {
            return $results;
        }

        // Remove first. The two sets are disjoint (checked above), so this can
        // never undo an add made in the same call.
        if (!empty($removeTagIds)) {
            $this->transactionTagMapper->deleteByTransactionsAndTags($ownedIds, $removeTagIds);
        }

        if (!empty($addTagIds)) {
            $existing = $this->transactionTagMapper->findExistingTagIdsByTransaction($ownedIds, $addTagIds);
            $now = date('Y-m-d H:i:s');

            foreach ($ownedIds as $transactionId) {
                $alreadyTagged = $existing[$transactionId] ?? [];
                foreach ($addTagIds as $tagId) {
                    if (in_array($tagId, $alreadyTagged, true)) {
                        continue;
                    }
                    $transactionTag = new TransactionTag();
                    $transactionTag->setTransactionId($transactionId);
                    $transactionTag->setTagId($tagId);
                    $transactionTag->setCreatedAt($now);
                    $this->transactionTagMapper->insert($transactionTag);
                }
            }
        }

        return $results;
    }

    /**
     * Assert every id is a global tag (no tag set) owned by this user.
     *
     * @param int[] $tagIds
     * @throws \Exception
     */
    private function assertGlobalTags(array $tagIds, string $userId): void {
        $tagIds = array_values(array_unique($tagIds));
        if (empty($tagIds)) {
            return;
        }

        $tags = $this->tagMapper->findByIds($tagIds);
        if (count($tags) !== count($tagIds)) {
            throw new \Exception('One or more tags do not exist');
        }

        foreach ($tags as $tag) {
            if ($tag->getTagSetId() !== null || $tag->getUserId() !== $userId) {
                throw new \Exception('Only global tags can be applied in bulk');
            }
        }
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
     * Get tags for a transaction without user ownership check.
     * Caller must verify access separately (e.g. via visible account IDs).
     */
    public function getTransactionTagsUnscoped(int $transactionId): array {
        $transactionTags = $this->transactionTagMapper->findByTransaction($transactionId);
        if (empty($transactionTags)) {
            return [];
        }
        $tagIds = array_map(fn($tt) => $tt->getTagId(), $transactionTags);
        return array_values($this->tagMapper->findByIds($tagIds));
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
    private function validateTagsForTransaction(?int $categoryId, array $tagIds, string $userId): void {
        if (empty($tagIds)) {
            return;
        }

        // Get all tags
        $tags = $this->tagMapper->findByIds($tagIds);

        if (count($tags) !== count($tagIds)) {
            throw new \Exception('One or more tags do not exist');
        }

        foreach ($tags as $tag) {
            if ($tag->getTagSetId() === null) {
                // Global tag: verify user ownership
                if ($tag->getUserId() !== $userId) {
                    throw new \Exception('One or more tags are not available for this transaction');
                }
            } else {
                // Category tag: verify tag set belongs to the transaction's category
                if ($categoryId === null) {
                    throw new \Exception('Category tags cannot be applied to uncategorized transactions');
                }

                $qb = $this->db->getQueryBuilder();
                $qb->select('ts.id')
                    ->from('budget_tag_sets', 'ts')
                    ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
                    ->where($qb->expr()->eq('ts.id', $qb->createNamedParameter($tag->getTagSetId(), \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('ts.category_id', $qb->createNamedParameter($categoryId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)))
                    ->andWhere($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)));

                $result = $qb->executeQuery();
                $found = $result->fetch();
                $result->closeCursor();

                if (!$found) {
                    throw new \Exception('One or more tags are not available for this transaction');
                }
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
