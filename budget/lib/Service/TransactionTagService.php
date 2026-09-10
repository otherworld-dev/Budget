<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TagSetMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTag;
use OCA\Budget\Db\TransactionTagMapper;
use OCP\IDBConnection;

class TransactionTagService {
    private TransactionTagMapper $transactionTagMapper;
    private TagMapper $tagMapper;
    private TagSetMapper $tagSetMapper;
    private TransactionMapper $transactionMapper;
    private IDBConnection $db;

    public function __construct(
        TransactionTagMapper $transactionTagMapper,
        TagMapper $tagMapper,
        TagSetMapper $tagSetMapper,
        TransactionMapper $transactionMapper,
        IDBConnection $db
    ) {
        $this->transactionTagMapper = $transactionTagMapper;
        $this->tagMapper = $tagMapper;
        $this->tagSetMapper = $tagSetMapper;
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
     * What the bulk tag picker may offer for a given selection (#379).
     *
     * Global tags always apply. A category tag only applies to rows in its tag
     * set's own category, so only tag sets whose category actually appears in
     * the selection are offered, each with the number of selected rows it
     * covers -- the picker says "applies to 12 of 50" rather than leaving the
     * user to guess. This has to be resolved server-side: a cross-page "select
     * all matching" selection exists in the browser as ids and nothing else.
     *
     * @param int[] $transactionIds
     * @return array{totalSelected: int, globalTags: array, tagSets: array, unaffectedCount: int}
     */
    public function getBulkTagOptions(string $userId, array $transactionIds): array {
        $transactionIds = array_values(array_unique(array_map('intval', $transactionIds)));
        $categoryByTransaction = $this->transactionMapper->findOwnedCategoryIds($transactionIds, $userId);

        $countByCategory = [];
        foreach ($categoryByTransaction as $categoryId) {
            if ($categoryId !== null) {
                $countByCategory[$categoryId] = ($countByCategory[$categoryId] ?? 0) + 1;
            }
        }

        $globalTags = $this->tagMapper->findGlobal($userId);

        $sets = empty($countByCategory)
            ? []
            : $this->tagSetMapper->findByCategoriesWithNames(array_keys($countByCategory), $userId);

        $tagsBySet = empty($sets)
            ? []
            : $this->tagMapper->findByTagSets(array_map(static fn(array $s): int => $s['id'], $sets));

        $tagSets = [];
        $coveredCategories = [];
        foreach ($sets as $set) {
            $coveredCategories[$set['categoryId']] = true;
            $tagSets[] = $set + [
                'matchingCount' => $countByCategory[$set['categoryId']] ?? 0,
                'tags' => array_values($tagsBySet[$set['id']] ?? []),
            ];
        }

        // Rows no offered tag set covers: a category with no tag sets, or no
        // category at all (uncategorised, or a split parent whose category is
        // nulled when it is split).
        $unaffected = 0;
        foreach ($categoryByTransaction as $categoryId) {
            if ($categoryId === null || !isset($coveredCategories[$categoryId])) {
                $unaffected++;
            }
        }

        return [
            'totalSelected' => count($categoryByTransaction),
            'globalTags' => array_values($globalTags),
            'tagSets' => $tagSets,
            'unaffectedCount' => $unaffected,
        ];
    }

    /**
     * Add and/or remove tags across many transactions at once (#379).
     *
     * Global tags apply to every selected row. A category tag applies only to
     * the rows in its tag set's own category -- a tag set belongs to exactly
     * one category, with no cascade to child categories -- so across a mixed
     * selection it lands on a subset, and 'applied' reports how many rows each
     * tag actually reached so the caller can say so rather than overclaim.
     *
     * A tag for a category NOT represented in the selection is refused
     * outright: ticking it could only ever have been a mistake.
     *
     * Removal is deliberately NOT category-gated. Re-categorising a transaction
     * leaves its old category tag on the row, and a gated removal would make
     * that orphaned tag impossible to clear in bulk.
     *
     * Both directions are idempotent: adding a tag a row already carries is a
     * no-op, and removing one it does not carry is too.
     *
     * @param int[] $transactionIds
     * @param int[] $addTagIds
     * @param int[] $removeTagIds
     * @return array{success: int, failed: int, errors: array, applied: array<int, int>}
     * @throws \Exception If a tag is unknown, not the user's, or not available here
     */
    public function bulkUpdateTags(string $userId, array $transactionIds, array $addTagIds, array $removeTagIds): array {
        $transactionIds = array_values(array_unique(array_map('intval', $transactionIds)));
        $addTagIds = array_values(array_unique(array_map('intval', $addTagIds)));
        $removeTagIds = array_values(array_unique(array_map('intval', $removeTagIds)));

        if (!empty(array_intersect($addTagIds, $removeTagIds))) {
            throw new \Exception('A tag cannot be both added and removed');
        }

        // The id list comes from the browser, so scope it before writing.
        $categoryByTransaction = $this->transactionMapper->findOwnedCategoryIds($transactionIds, $userId);
        $ownedIds = array_keys($categoryByTransaction);

        $results = ['success' => count($ownedIds), 'failed' => 0, 'errors' => [], 'applied' => []];
        foreach ($transactionIds as $id) {
            if (!array_key_exists($id, $categoryByTransaction)) {
                $results['failed']++;
                $results['errors'][] = ['id' => $id, 'message' => 'Transaction not found'];
            }
        }

        // Resolve each tag to the category it requires, or null for a global
        // tag. Validation needs the selection's categories, so it runs even
        // when nothing is owned -- a bad tag id is still a bad request.
        $categoryByTag = $this->resolveTagCategories(
            array_merge($addTagIds, $removeTagIds),
            $userId,
            array_values(array_unique(array_filter($categoryByTransaction, static fn($c) => $c !== null)))
        );

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

            foreach ($addTagIds as $tagId) {
                $results['applied'][$tagId] = 0;
                $requiredCategory = $categoryByTag[$tagId];

                foreach ($ownedIds as $transactionId) {
                    if ($requiredCategory !== null && $categoryByTransaction[$transactionId] !== $requiredCategory) {
                        continue;
                    }
                    $results['applied'][$tagId]++;

                    if (in_array($tagId, $existing[$transactionId] ?? [], true)) {
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
     * Map each tag id to the category a row must be in for it to apply, or
     * null when the tag is global and applies anywhere.
     *
     * @param int[] $tagIds
     * @param int[] $selectionCategoryIds Categories present in the selection
     * @return array<int, int|null>
     * @throws \Exception
     */
    private function resolveTagCategories(array $tagIds, string $userId, array $selectionCategoryIds): array {
        $tagIds = array_values(array_unique($tagIds));
        if (empty($tagIds)) {
            return [];
        }

        $tags = $this->tagMapper->findByIds($tagIds);
        if (count($tags) !== count($tagIds)) {
            throw new \Exception('One or more tags do not exist');
        }

        $tagSetIds = [];
        foreach ($tags as $tag) {
            if ($tag->getTagSetId() !== null) {
                $tagSetIds[] = $tag->getTagSetId();
            }
        }

        $categoryByTagSet = empty($tagSetIds)
            ? []
            : $this->tagSetMapper->findCategoryIdsForTagSets(array_values(array_unique($tagSetIds)), $userId);

        $inSelection = array_flip($selectionCategoryIds);
        $categoryByTag = [];

        foreach ($tags as $tag) {
            $tagSetId = $tag->getTagSetId();

            if ($tagSetId === null) {
                // Global tag: the user must own it, and it applies anywhere.
                if ($tag->getUserId() !== $userId) {
                    throw new \Exception('One or more tags are not available for this selection');
                }
                $categoryByTag[$tag->getId()] = null;
                continue;
            }

            // Category tag: the set must be the user's, and its category must
            // actually appear in the selection.
            if (!isset($categoryByTagSet[$tagSetId]) || !isset($inSelection[$categoryByTagSet[$tagSetId]])) {
                throw new \Exception('One or more tags are not available for this selection');
            }
            $categoryByTag[$tag->getId()] = $categoryByTagSet[$tagSetId];
        }

        return $categoryByTag;
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
