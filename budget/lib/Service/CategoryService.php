<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\CategoryMuteMapper;
use OCA\Budget\Db\BudgetSnapshot;
use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\TagSetMapper;
use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Exception\CategoryInUseException;
use OCP\AppFramework\Db\Entity;
use OCP\IL10N;

/**
 * @extends AbstractCrudService<Category>
 */
class CategoryService extends AbstractCrudService {
    private TransactionMapper $transactionMapper;
    private BudgetSnapshotMapper $budgetSnapshotMapper;
    private TagSetMapper $tagSetMapper;
    private TagMapper $tagMapper;
    private TransactionTagMapper $transactionTagMapper;
    private IL10N $l;

    public function __construct(
        CategoryMapper $mapper,
        TransactionMapper $transactionMapper,
        BudgetSnapshotMapper $budgetSnapshotMapper,
        TagSetMapper $tagSetMapper,
        TagMapper $tagMapper,
        TransactionTagMapper $transactionTagMapper,
        IL10N $l,
        private BudgetCarryoverService $carryoverService,
        private RecurringBudgetService $recurringBudgetService,
        private ?AutoShareService $autoShareService = null,
        private ?CategoryMuteMapper $categoryMuteMapper = null,
        private ?TransactionSplitMapper $splitMapper = null
    ) {
        $this->mapper = $mapper;
        $this->transactionMapper = $transactionMapper;
        $this->budgetSnapshotMapper = $budgetSnapshotMapper;
        $this->tagSetMapper = $tagSetMapper;
        $this->tagMapper = $tagMapper;
        $this->transactionTagMapper = $transactionTagMapper;
        $this->l = $l;
    }

    /**
     * Category updates with envelope-anchor handling: enabling rollover
     * stamps the anchor month the carryover chain starts from; re-enabling
     * resets it (history from a previous enablement never resurrects).
     */
    public function update(int $id, string $userId, array $updates): Entity {
        if (array_key_exists('budgetRollover', $updates)) {
            $entity = $this->find($id, $userId);
            $wasEnabled = $entity->getBudgetRollover() ?? false;
            if ($updates['budgetRollover'] && !$wasEnabled) {
                $updates['rolloverStart'] = date('Y-m');
            }
        }
        return parent::update($id, $userId, $updates);
    }

    /**
     * @return CategoryMapper
     */
    protected function getCategoryMapper(): CategoryMapper {
        /** @var CategoryMapper */
        return $this->mapper;
    }

    public function findByType(string $userId, string $type): array {
        return $this->getCategoryMapper()->findByType($userId, $type);
    }

    /**
     * Find an existing category by name/type or create it.
     */
    public function findOrCreate(string $userId, string $name, string $type): Category {
        $existing = $this->getCategoryMapper()->findByName($userId, $name, $type, null);
        if ($existing !== null) {
            return $existing;
        }

        $category = new Category();
        $category->setUserId($userId);
        $category->setName($name);
        $category->setType($type);
        $category->setColor($this->generateRandomColor());
        $category->setSortOrder(0);
        $this->setTimestamps($category, true);

        return $this->mapper->insert($category);
    }

    /**
     * Find an existing subcategory by name/type/parent or create it.
     */
    public function findOrCreateSubcategory(string $userId, string $name, string $type, int $parentId): Category {
        $existing = $this->getCategoryMapper()->findByName($userId, $name, $type, $parentId);
        if ($existing !== null) {
            return $existing;
        }

        $category = new Category();
        $category->setUserId($userId);
        $category->setName($name);
        $category->setType($type);
        $category->setParentId($parentId);
        $category->setSortOrder(0);
        $this->setTimestamps($category, true);

        return $this->mapper->insert($category);
    }

    public function create(
        string $userId,
        string $name,
        string $type,
        ?int $parentId = null,
        ?string $icon = null,
        ?string $color = null,
        ?float $budgetAmount = null,
        int $sortOrder = 0,
        bool $excludedFromReports = false,
        bool $excludedFromBudget = false
    ): Category {
        // Validate parent if provided
        if ($parentId !== null) {
            $this->find($parentId, $userId);
        }

        // Prevent duplicate categories (same name, type, and parent)
        if ($this->getCategoryMapper()->existsDuplicate($userId, $name, $type, $parentId)) {
            throw new \Exception($this->l->t('A category with this name already exists at this level'));
        }

        $category = new Category();
        $category->setUserId($userId);
        $category->setName($name);
        $category->setType($type);
        $category->setParentId($parentId);
        $category->setIcon($icon);
        $category->setColor($color ?: $this->generateRandomColor());
        $category->setBudgetAmount($budgetAmount);
        $category->setSortOrder($sortOrder);
        $category->setExcludedFromReports($excludedFromReports);
        $category->setExcludedFromBudget($excludedFromBudget);
        $this->setTimestamps($category, true);

        $category = $this->mapper->insert($category);
        if ($this->autoShareService !== null) {
            $this->autoShareService->autoShareNewEntity($userId, ShareItem::TYPE_CATEGORY, $category->getId());
        }
        return $category;
    }

    /**
     * @inheritDoc
     */
    protected function beforeUpdate(Entity $entity, array $updates, string $userId): void {
        // Validate parent if being updated
        if (isset($updates['parentId']) && $updates['parentId'] !== null) {
            if ($updates['parentId'] === $entity->getId()) {
                throw new \Exception($this->l->t('Category cannot be its own parent'));
            }
            $this->find($updates['parentId'], $userId);
        }

        // Prevent duplicate categories after update
        $name = $updates['name'] ?? $entity->getName();
        $type = $updates['type'] ?? $entity->getType();
        $parentId = array_key_exists('parentId', $updates) ? $updates['parentId'] : $entity->getParentId();
        if ($this->getCategoryMapper()->existsDuplicate($userId, $name, $type, $parentId, $entity->getId())) {
            throw new \Exception($this->l->t('A category with this name already exists at this level'));
        }
    }

    /**
     * @inheritDoc
     */
    protected function beforeDelete(Entity $entity, string $userId): void {
        // Cascade delete: Delete child categories first (recursively)
        $children = $this->getCategoryMapper()->findChildren($userId, $entity->getId());
        foreach ($children as $child) {
            // Recursively delete child and its descendants
            $this->delete($child->getId(), $userId);
        }

        // Check for transactions. Typed so the controller can offer to reassign
        // them to No Category and retry (#332).
        $transactions = $this->transactionMapper->findByCategory($entity->getId(), $userId, 1);
        if (!empty($transactions)) {
            throw new CategoryInUseException($this->l->t('Cannot delete this category because it has transactions assigned to it. Please reassign or delete them first.'));
        }

        // findByCategory() above is deliberately split-blind (a category used
        // only by split parts must stay deletable, never guarded), so a
        // category can reach here with budget_tx_splits rows still pointing at
        // it -- degrade those to uncategorized rather than leave them dangling
        // on a deleted category id (#360). This also covers deleteWithReassign()
        // below, which calls delete() per category through the cascade above.
        $this->splitMapper?->clearCategory([$entity->getId()]);

        // Cascade delete: Delete budget snapshots for this category
        $this->budgetSnapshotMapper->deleteByCategory($entity->getId(), $userId);

        // Cascade delete: Delete all tag sets for this category
        $tagSets = $this->tagSetMapper->findByCategory($entity->getId(), $userId);
        foreach ($tagSets as $tagSet) {
            // Delete tags in this tag set
            $tags = $this->tagMapper->findByTagSet($tagSet->getId());
            foreach ($tags as $tag) {
                // Delete transaction tags first
                $this->transactionTagMapper->deleteByTag($tag->getId());
                // Then delete the tag
                $this->tagMapper->delete($tag);
            }
            // Finally delete the tag set
            $this->tagSetMapper->delete($tagSet);
        }
    }

    /**
     * Delete a category after moving its transactions — and those of its
     * descendant categories — to No Category, so a category that still has
     * transactions can be removed without hand-recategorizing first (#332).
     * Owner-only, like delete(). Split-line category references are cleared
     * too, by beforeDelete() as the cascade deletes each category (#360).
     */
    public function deleteWithReassign(int $id, string $userId): void {
        // Ownership check (throws if not found / not the user's).
        $this->find($id, $userId);

        $categoryIds = $this->collectSelfAndDescendantIds($id, $userId);
        $this->transactionMapper->clearCategory($categoryIds);

        // Transactions are now uncategorized, so beforeDelete()'s guard passes.
        $this->delete($id, $userId);
    }

    /**
     * Reorder a category relative to a target sibling, renumbering the whole
     * destination sibling group sequentially. Setting a single sortOrder collides
     * with existing values and the sort tiebreak then silently ignores the move;
     * renumbering makes the result deterministic (#328). 'above'/'below' reorder
     * within the target's level; 'child' nests under the target.
     */
    public function reorderCategory(int $id, string $userId, int $targetId, string $position): Category {
        $category = $this->find($id, $userId);
        $target = $this->find($targetId, $userId);

        if ($position === 'child') {
            if (in_array($targetId, $this->collectSelfAndDescendantIds($id, $userId), true)) {
                throw new \InvalidArgumentException($this->l->t('A category cannot be nested inside itself'));
            }
            $newParentId = $targetId;
        } else {
            $newParentId = $target->getParentId();
        }

        // The owner's full sibling group at the destination level, excluding the
        // moved category. Using the owner's full set (not just the shared subset)
        // keeps ordering clean when only some categories are shared.
        $siblings = $newParentId === null
            ? $this->getCategoryMapper()->findRootCategories($userId)
            : $this->getCategoryMapper()->findChildren($userId, $newParentId);
        $siblings = array_values(array_filter($siblings, static fn($c) => $c->getId() !== $id));

        if ($position === 'child') {
            $insertIndex = 0;
        } else {
            $insertIndex = count($siblings);
            foreach ($siblings as $i => $c) {
                if ($c->getId() === $targetId) {
                    $insertIndex = $position === 'above' ? $i : $i + 1;
                    break;
                }
            }
        }
        array_splice($siblings, $insertIndex, 0, [$category]);

        // Renumber sequentially; persist only rows that actually change.
        foreach ($siblings as $i => $c) {
            $changed = false;
            if ($c->getSortOrder() !== $i) {
                $c->setSortOrder($i);
                $changed = true;
            }
            if ($c->getId() === $id && $c->getParentId() !== $newParentId) {
                $c->setParentId($newParentId);
                $changed = true;
            }
            if ($changed) {
                $this->getCategoryMapper()->update($c);
            }
        }

        return $this->find($id, $userId);
    }

    /**
     * This category id plus every descendant category id (recursive).
     *
     * @return int[]
     */
    private function collectSelfAndDescendantIds(int $id, string $userId): array {
        $ids = [$id];
        foreach ($this->getCategoryMapper()->findChildren($userId, $id) as $child) {
            $ids = array_merge($ids, $this->collectSelfAndDescendantIds($child->getId(), $userId));
        }
        return $ids;
    }

    /**
     * The categories the Category Details panel reports on, resolved once so
     * its figures and its transaction list cannot disagree about what they
     * cover (#359).
     *
     * The whole subtree, not just the direct children the count used to roll up
     * while the list rolled up none — that mismatch made the panel report more
     * transactions than it could show, and left grandchildren out of both.
     * Descendants flagged excluded_from_reports drop out, matching the
     * row-level exclusion every report aggregate applies; the selected category
     * itself always stays, because the user asked for it.
     *
     * @return array{ids: int[], includesSubcategories: bool, type: string}
     */
    private function resolveDetailScope(Category $category, string $userId): array {
        // One fetch and an in-memory walk: the recursive mapper version costs a
        // query per level, and this needs each descendant's flag anyway.
        $childrenMap = [];
        foreach ($this->findAll($userId) as $candidate) {
            $childrenMap[$candidate->getParentId()][] = $candidate;
        }

        $ids = [$category->getId()];
        $queue = [$category->getId()];
        while ($queue !== []) {
            $parentId = array_shift($queue);
            foreach ($childrenMap[$parentId] ?? [] as $child) {
                if ($child->getExcludedFromReports()) {
                    continue;
                }
                $ids[] = $child->getId();
                $queue[] = $child->getId();
            }
        }

        return [
            'ids' => array_values(array_unique($ids)),
            'includesSubcategories' => count($ids) > 1,
            'type' => (string)($category->getType() ?? 'expense'),
        ];
    }

    public function getCategoryTree(string $userId): array {
        $categories = $this->findAll($userId);

        // Build parent->children map in O(n) single pass
        $childrenMap = [];
        foreach ($categories as $category) {
            $pid = $category->getParentId();
            if (!isset($childrenMap[$pid])) {
                $childrenMap[$pid] = [];
            }
            $childrenMap[$pid][] = $category;
        }

        return $this->buildTreeFromMap($childrenMap, null);
    }

    private function buildTreeFromMap(array $childrenMap, ?int $parentId): array {
        $tree = [];
        foreach ($childrenMap[$parentId] ?? [] as $category) {
            $categoryArray = $category->jsonSerialize();
            $categoryArray['children'] = $this->buildTreeFromMap($childrenMap, $category->getId());
            $tree[] = $categoryArray;
        }
        return $tree;
    }

    /**
     * Get full detail summary for a single category (analytics + monthly chart data)
     */
    public function getCategoryDetails(int $categoryId, string $userId, ?string $startDate = null, ?string $endDate = null, ?int $accountId = null): array {
        $category = $this->find($categoryId, $userId); // Verify ownership

        $scope = $this->resolveDetailScope($category, $userId);
        $categoryIds = $scope['ids'];

        $summary = $this->transactionMapper->getCategorySummary($userId, $categoryId, $categoryIds);
        $monthlySpending = $this->transactionMapper->getCategoryMonthlySpending($userId, $categoryId, 12, $categoryIds, $startDate, $endDate, $accountId, $category->getType());

        // Include budget amount for chart overlay
        $budget = (float)($category->getBudgetAmount() ?? 0);
        $budgetPeriod = $category->getBudgetPeriod() ?? 'monthly';

        $count = $summary['count'];
        $total = $summary['total'];
        $average = $count > 0 ? $total / $count : 0.0;

        // How much of the count arrived as split allocations, so the panel can
        // say so rather than leave the user to wonder (#359).
        $splitCount = 0;
        foreach ($monthlySpending as $entry) {
            $splitCount += (int)($entry['splitCount'] ?? 0);
        }

        // This month's total from monthly data
        $currentMonth = date('Y-m');
        $thisMonth = 0.0;
        foreach ($monthlySpending as $entry) {
            if ($entry['month'] === $currentMonth) {
                $thisMonth = $entry['total'];
                break;
            }
        }

        // Trend: compare current month vs average of previous 3 months
        $trend = 'stable';
        $previousMonths = [];
        foreach ($monthlySpending as $entry) {
            if ($entry['month'] !== $currentMonth) {
                $previousMonths[] = $entry['total'];
            }
        }
        // Take last 3 previous months
        $previousMonths = array_slice($previousMonths, -3);
        if (!empty($previousMonths)) {
            $prevAvg = array_sum($previousMonths) / count($previousMonths);
            if ($prevAvg > 0) {
                $change = ($thisMonth - $prevAvg) / $prevAvg;
                if ($change > 0.10) {
                    $trend = 'increasing';
                } elseif ($change < -0.10) {
                    $trend = 'decreasing';
                }
            } elseif ($thisMonth > 0) {
                $trend = 'increasing';
            }
        }

        return [
            'count' => $count,
            'total' => $total,
            'average' => round($average, 2),
            'thisMonth' => $thisMonth,
            'trend' => $trend,
            'monthlySpending' => $monthlySpending,
            'budget' => $budget,
            'budgetPeriod' => $budgetPeriod,
            'scope' => [
                'categoryIds' => $categoryIds,
                'includesSubcategories' => $scope['includesSubcategories'],
                'type' => $scope['type'],
                'splitCount' => $splitCount,
            ],
        ];
    }

    /**
     * Get recent transactions for a category (user-scoped).
     *
     * Covers the same categories as getCategoryDetails and counts split
     * allocations the same way, so the panel lists the transactions behind the
     * figures above it rather than a different set (#359). Each split row
     * carries the share belonging to this category, the whole transaction it
     * came from, and its parts.
     *
     * @return array<array<string, mixed>>
     */
    public function getCategoryTransactions(int $categoryId, string $userId, int $limit = 5): array {
        $category = $this->find($categoryId, $userId); // Verify ownership
        $scope = $this->resolveDetailScope($category, $userId);

        $rows = $this->transactionMapper->findCategoryTransactionRows($userId, $scope['ids'], $limit);

        $splitIds = [];
        foreach ($rows as $row) {
            if (!empty($row['isSplit'])) {
                $splitIds[] = (int)$row['id'];
            }
        }

        if (empty($splitIds) || $this->splitMapper === null) {
            return $rows;
        }

        $parts = $this->splitMapper->findByTransactionIds($splitIds);
        $inScope = array_flip($scope['ids']);

        foreach ($rows as &$row) {
            if (empty($row['isSplit']) || !isset($parts[$row['id']])) {
                continue;
            }
            $row['splitCategories'] = array_map(static function (array $part) use ($inScope): array {
                $part['inCategory'] = $part['categoryId'] !== null
                    && isset($inScope[(int)$part['categoryId']]);
                return $part;
            }, $parts[$row['id']]);
        }
        unset($row);

        return $rows;
    }

    /**
     * Get transaction counts per category for tree display
     * @return array<int, int> categoryId => count
     */
    public function getCategoryTransactionCounts(string $userId, ?array $visibleAccountIds = null): array {
        return $this->transactionMapper->getCategoryTransactionCounts($userId, $visibleAccountIds);
    }

    public function getCategorySpending(int $categoryId, string $userId, string $startDate, string $endDate): float {
        $this->find($categoryId, $userId); // Verify ownership
        return $this->getCategoryMapper()->getCategorySpending($categoryId, $startDate, $endDate);
    }

    /**
     * The Budget page's Spent figures. Totals are netted — a refund credit in
     * an expense category reduces its spent, matching Category Details and
     * every other budget surface (#361) — and the sign is preserved: a month
     * whose refunds exceed its spending is genuinely negative, and an abs()
     * here would flip it back into looking like money spent.
     *
     * @param int[]|null $visibleAccountIds If provided, scope by account IDs for cross-user aggregation
     */
    public function getAllCategorySpending(string $userId, string $startDate, string $endDate, ?array $visibleAccountIds = null, string $transactionType = 'debit'): array {
        $summary = $this->transactionMapper->getSpendingSummary(
            $userId, $startDate, $endDate,
            visibleAccountIds: $visibleAccountIds,
            transactionType: $transactionType,
            netOpposite: true
        );

        return array_map(fn($item) => [
            'categoryId' => (int)$item['id'],
            'spent' => (float)($item['total'] ?? 0),
            'name' => $item['name'] ?? '',
            'color' => $item['color'] ?? null,
            'count' => (int)($item['count'] ?? 0)
        ], $summary);
    }

    /**
     * Create a budget snapshot for the given month.
     * Copies all current category budgets (or latest effective values) as a new baseline.
     */
    public function createBudgetSnapshot(string $userId, string $month): array {
        // Don't create duplicate snapshots
        if ($this->budgetSnapshotMapper->hasSnapshot($userId, $month)) {
            throw new \Exception($this->l->t('A budget adjustment already exists for this month'));
        }

        $categories = $this->findAll($userId);
        $now = (new \DateTime())->format('Y-m-d H:i:s');

        // Get the effective budgets for this month (to snapshot from)
        $effectiveBudgets = $this->budgetSnapshotMapper->findEffectiveBatch($userId, $month);

        $snapshots = [];
        foreach ($categories as $category) {
            $catId = $category->getId();

            // Use effective snapshot value if one exists, otherwise use category default
            if (isset($effectiveBudgets[$catId])) {
                $amount = $effectiveBudgets[$catId]['amount'];
                $period = $effectiveBudgets[$catId]['period'];
            } else {
                $amount = $category->getBudgetAmount();
                $period = $category->getBudgetPeriod() ?? 'monthly';
            }

            $snapshot = new BudgetSnapshot();
            $snapshot->setUserId($userId);
            $snapshot->setCategoryId($catId);
            $snapshot->setEffectiveFrom($month);
            $snapshot->setAmount($amount);
            $snapshot->setPeriod($period);
            $snapshot->setCreatedAt($now);

            $snapshots[] = $this->budgetSnapshotMapper->insert($snapshot);
        }

        return $snapshots;
    }

    /**
     * Delete a budget snapshot for a given month.
     */
    public function deleteBudgetSnapshot(string $userId, string $month): void {
        $this->budgetSnapshotMapper->deleteByMonth($userId, $month);
    }

    /**
     * Get all snapshot months for a user.
     *
     * @return string[]
     */
    public function getSnapshotMonths(string $userId): array {
        return $this->budgetSnapshotMapper->getSnapshotMonths($userId);
    }

    /**
     * Check if a specific month has a snapshot.
     */
    public function hasSnapshot(string $userId, string $month): bool {
        return $this->budgetSnapshotMapper->hasSnapshot($userId, $month);
    }

    /**
     * Update a single category's budget within a snapshot month.
     */
    public function updateSnapshotBudget(string $userId, int $categoryId, string $month, ?float $amount, ?string $period = null): BudgetSnapshot {
        $snapshots = $this->budgetSnapshotMapper->findByMonth($userId, $month);
        foreach ($snapshots as $snapshot) {
            if ($snapshot->getCategoryId() === $categoryId) {
                if ($amount !== null) {
                    $snapshot->setAmount($amount);
                }
                if ($period !== null) {
                    $snapshot->setPeriod($period);
                }
                return $this->budgetSnapshotMapper->update($snapshot);
            }
        }

        throw new \Exception($this->l->t('No budget snapshot found for this category and month'));
    }

    /**
     * Resolve the effective budget for a category at a given month.
     * Returns snapshot value if one applies, otherwise category default.
     *
     * @return array{amount: float|null, period: string}
     */
    public function resolveEffectiveBudget(int $categoryId, string $userId, string $month): array {
        $snapshot = $this->budgetSnapshotMapper->findEffective($categoryId, $userId, $month);
        if ($snapshot !== null) {
            return [
                'amount' => $snapshot->getAmount(),
                'period' => $snapshot->getPeriod() ?? 'monthly',
            ];
        }

        $category = $this->find($categoryId, $userId);
        return [
            'amount' => $category->getBudgetAmount(),
            'period' => $category->getBudgetPeriod() ?? 'monthly',
        ];
    }

    /**
     * Batch-resolve effective budgets for all categories at a given month.
     *
     * Each entry composes the full envelope math server-side so every
     * surface agrees (#269 lesson):
     *   amount    — manual/snapshot value (raw, for the budget input box)
     *   period    — budget period
     *   rollover  — envelope flag (eligible categories only)
     *   carried   — carryover into this month (0 unless rollover)
     *   available — effective budget incl. recurring fallback + carryover
     *
     * Returns map of categoryId => array.
     */
    public function resolveEffectiveBudgets(string $userId, string $month, ?array $visibleAccountIds = null): array {
        $categories = $this->findAll($userId);
        $snapshotOverrides = $this->budgetSnapshotMapper->findEffectiveBatch($userId, $month);
        $carryovers = $this->carryoverService->getCarryovers($userId, $month, $categories, $visibleAccountIds);
        // Categories the user doesn't budget against get no entry at all
        $notBudgeted = BudgetScope::excludedCategoryIds($categories);

        // Recurring fallback only applies to current/future months (#269)
        $recurring = $month >= date('Y-m')
            ? $this->recurringBudgetService->getMonthlyBudgetsByCategory($userId)
            : [];

        $result = [];
        foreach ($categories as $category) {
            $catId = $category->getId();
            if (isset($notBudgeted[$catId])) {
                continue;
            }
            if (isset($snapshotOverrides[$catId])) {
                $amount = $snapshotOverrides[$catId]['amount'];
                $period = $snapshotOverrides[$catId]['period'];
            } else {
                $amount = $category->getBudgetAmount();
                $period = $category->getBudgetPeriod() ?? 'monthly';
            }

            $base = (float) ($amount ?? 0);
            if ($base <= 0 && isset($recurring[$catId])) {
                $base = $this->recurringBudgetService->convertMonthlyToPeriod(
                    (float) $recurring[$catId],
                    $period
                );
            }
            $carried = $carryovers[$catId] ?? 0.0;

            $result[$catId] = [
                'amount' => $amount,
                'period' => $period,
                'rollover' => ($category->getBudgetRollover() ?? false)
                    && ($category->getBudgetPeriod() ?? 'monthly') === 'monthly'
                    && $category->getType() === 'expense',
                'carried' => round($carried, 2),
                'available' => round($base + $carried, 2),
            ];
        }

        return $result;
    }

    public function getBudgetAnalysis(string $userId, ?string $month = null, ?array $visibleAccountIds = null): array {
        if (!$month) {
            $month = date('Y-m');
        }

        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        $categories = $this->findAll($userId);

        // Resolve effective budgets for this month (snapshot-aware)
        $effectiveBudgets = $this->resolveEffectiveBudgets($userId, $month, $visibleAccountIds);

        // Categories excluded from reports, or not budgeted against
        $notBudgeted = BudgetScope::excludedCategoryIds($categories);

        // Split categories by type for correct transaction type queries
        $expenseCategoryIds = [];
        $incomeCategoryIds = [];
        foreach ($categories as $category) {
            if ($category->getExcludedFromReports() || isset($notBudgeted[$category->getId()])) {
                continue;
            }
            $catId = $category->getId();
            $budget = $effectiveBudgets[$catId]['amount'] ?? 0;
            if ($budget > 0) {
                if ($category->getType() === 'income') {
                    $incomeCategoryIds[] = $catId;
                } else {
                    $expenseCategoryIds[] = $catId;
                }
            }
        }

        // Batch queries: debit for expenses, credit for income
        $spendingMap = $this->transactionMapper->getCategorySpendingBatch(
            $expenseCategoryIds, $startDate, $endDate, 'debit'
        );
        $incomeMap = $this->transactionMapper->getCategorySpendingBatch(
            $incomeCategoryIds, $startDate, $endDate, 'credit'
        );
        $spendingMap = $spendingMap + $incomeMap;

        $analysis = [];
        foreach ($categories as $category) {
            if ($category->getExcludedFromReports() || isset($notBudgeted[$category->getId()])) {
                continue;
            }
            $catId = $category->getId();
            $budget = (float) ($effectiveBudgets[$catId]['amount'] ?? 0);
            if ($budget > 0) {
                $spent = $spendingMap[$catId] ?? 0.0;
                $remaining = $budget - $spent;
                $percentage = $budget > 0 ? ($spent / $budget) * 100 : 0;

                $analysis[] = [
                    'category' => $category,
                    'budget' => $budget,
                    'spent' => $spent,
                    'remaining' => $remaining,
                    'percentage' => $percentage,
                    'status' => $this->getBudgetStatus($percentage)
                ];
            }
        }

        return $analysis;
    }

    private function getBudgetStatus(float $percentage): string {
        if ($percentage <= 50) {
            return 'good';
        } elseif ($percentage <= 80) {
            return 'warning';
        } elseif ($percentage <= 100) {
            return 'danger';
        } else {
            return 'over';
        }
    }

    /**
     * The built-in default category catalog, with suggested budget
     * percentages based on the 50/30/20 rule.
     */
    public function getDefaultCategoryDefinitions(): array {
        return [
            // Income categories
            [
                'name' => 'Income',
                'type' => 'income',
                'icon' => 'icon-plus',
                'color' => '#4ade80',
                'children' => [
                    ['name' => 'Salary', 'icon' => 'icon-user'],
                    ['name' => 'Freelance', 'icon' => 'icon-briefcase'],
                    ['name' => 'Investment', 'icon' => 'icon-chart-line'],
                    ['name' => 'Other Income', 'icon' => 'icon-plus']
                ]
            ],
            // Expense categories with suggested budget percentages
            [
                'name' => 'Housing',
                'type' => 'expense',
                'icon' => 'icon-home',
                'color' => '#3b82f6',
                'budgetPercent' => 30,
                'children' => [
                    ['name' => 'Rent/Mortgage', 'icon' => 'icon-home', 'budgetPercent' => 25],
                    ['name' => 'Utilities', 'icon' => 'icon-flash', 'budgetPercent' => 3],
                    ['name' => 'Insurance', 'icon' => 'icon-shield', 'budgetPercent' => 1, 'period' => 'yearly'],
                    ['name' => 'Maintenance', 'icon' => 'icon-settings', 'budgetPercent' => 1]
                ]
            ],
            [
                'name' => 'Food',
                'type' => 'expense',
                'icon' => 'icon-food',
                'color' => '#f59e0b',
                'budgetPercent' => 15,
                'children' => [
                    ['name' => 'Groceries', 'icon' => 'icon-shopping-cart', 'budgetPercent' => 10],
                    ['name' => 'Dining Out', 'icon' => 'icon-restaurant', 'budgetPercent' => 4],
                    ['name' => 'Coffee/Tea', 'icon' => 'icon-coffee', 'budgetPercent' => 1]
                ]
            ],
            [
                'name' => 'Transportation',
                'type' => 'expense',
                'icon' => 'icon-car',
                'color' => '#8b5cf6',
                'budgetPercent' => 10,
                'children' => [
                    ['name' => 'Gas', 'icon' => 'icon-gas-station', 'budgetPercent' => 4],
                    ['name' => 'Car Payment', 'icon' => 'icon-car', 'budgetPercent' => 4],
                    ['name' => 'Public Transit', 'icon' => 'icon-bus', 'budgetPercent' => 1],
                    ['name' => 'Ride Share', 'icon' => 'icon-phone', 'budgetPercent' => 1]
                ]
            ],
            [
                'name' => 'Entertainment',
                'type' => 'expense',
                'icon' => 'icon-play',
                'color' => '#ec4899',
                'budgetPercent' => 5,
                'children' => [
                    ['name' => 'Movies/Shows', 'icon' => 'icon-video', 'budgetPercent' => 1],
                    ['name' => 'Music/Streaming', 'icon' => 'icon-music', 'budgetPercent' => 1],
                    ['name' => 'Games', 'icon' => 'icon-game', 'budgetPercent' => 1],
                    ['name' => 'Hobbies', 'icon' => 'icon-heart', 'budgetPercent' => 2]
                ]
            ],
            [
                'name' => 'Healthcare',
                'type' => 'expense',
                'icon' => 'icon-medical',
                'color' => '#ef4444',
                'budgetPercent' => 5,
                'children' => [
                    ['name' => 'Doctor Visits', 'icon' => 'icon-medical', 'budgetPercent' => 2],
                    ['name' => 'Prescriptions', 'icon' => 'icon-pill', 'budgetPercent' => 1],
                    ['name' => 'Insurance', 'icon' => 'icon-shield', 'budgetPercent' => 2]
                ]
            ],
            [
                'name' => 'Shopping',
                'type' => 'expense',
                'icon' => 'icon-shopping-bag',
                'color' => '#06b6d4',
                'budgetPercent' => 5,
                'children' => [
                    ['name' => 'Clothing', 'icon' => 'icon-shirt', 'budgetPercent' => 2],
                    ['name' => 'Electronics', 'icon' => 'icon-laptop', 'budgetPercent' => 2],
                    ['name' => 'Home Goods', 'icon' => 'icon-home', 'budgetPercent' => 1]
                ]
            ],
            [
                'name' => 'Savings',
                'type' => 'expense',
                'icon' => 'icon-piggy-bank',
                'color' => '#22c55e',
                'budgetPercent' => 20,
                'children' => [
                    ['name' => 'Emergency Fund', 'icon' => 'icon-shield', 'budgetPercent' => 10],
                    ['name' => 'Retirement', 'icon' => 'icon-clock', 'budgetPercent' => 5],
                    ['name' => 'Goals', 'icon' => 'icon-target', 'budgetPercent' => 5]
                ]
            ],
            [
                'name' => 'Subscriptions',
                'type' => 'expense',
                'icon' => 'icon-refresh',
                'color' => '#a855f7',
                'budgetPercent' => 3,
                'children' => [
                    ['name' => 'Streaming Services', 'icon' => 'icon-play', 'budgetPercent' => 1],
                    ['name' => 'Software', 'icon' => 'icon-code', 'budgetPercent' => 1],
                    ['name' => 'Memberships', 'icon' => 'icon-card', 'budgetPercent' => 1]
                ]
            ],
            [
                'name' => 'Personal',
                'type' => 'expense',
                'icon' => 'icon-user',
                'color' => '#f97316',
                'budgetPercent' => 5,
                'children' => [
                    ['name' => 'Grooming', 'icon' => 'icon-scissors', 'budgetPercent' => 1],
                    ['name' => 'Gifts', 'icon' => 'icon-gift', 'budgetPercent' => 2],
                    ['name' => 'Education', 'icon' => 'icon-book', 'budgetPercent' => 2]
                ]
            ]
        ];
    }

    public function createDefaultCategories(string $userId, ?float $monthlyIncome = null): array {
        $created = [];
        foreach ($this->getDefaultCategoryDefinitions() as $categoryData) {
            $budgetAmount = null;
            if ($monthlyIncome && isset($categoryData['budgetPercent'])) {
                $budgetAmount = ($monthlyIncome * $categoryData['budgetPercent']) / 100;
            }

            // Reuse an existing root instead of failing on it: the seed button
            // must fill in whatever is missing, never abort because part of the
            // tree still exists — e.g. the income side after the user emptied
            // the expense side (#348).
            $parent = $this->getCategoryMapper()->findByName($userId, $categoryData['name'], $categoryData['type'], null);
            if ($parent === null) {
                $parent = $this->create(
                    $userId,
                    $categoryData['name'],
                    $categoryData['type'],
                    null,
                    $categoryData['icon'],
                    $categoryData['color'],
                    $budgetAmount
                );

                $created[] = $parent;
            }

            foreach ($categoryData['children'] ?? [] as $childData) {
                if ($this->getCategoryMapper()->findByName($userId, $childData['name'], $categoryData['type'], $parent->getId()) !== null) {
                    continue;
                }

                $childBudget = null;
                if ($monthlyIncome && isset($childData['budgetPercent'])) {
                    $childBudget = ($monthlyIncome * $childData['budgetPercent']) / 100;
                }

                $child = $this->create(
                    $userId,
                    $childData['name'],
                    $categoryData['type'],
                    $parent->getId(),
                    $childData['icon'],
                    $parent->getColor(),
                    $childBudget
                );

                // Set budget period if specified (e.g., yearly for insurance)
                if (isset($childData['period'])) {
                    $child->setBudgetPeriod($childData['period']);
                    $this->mapper->update($child);
                }

                $created[] = $child;
            }
        }

        return $created;
    }

    /**
     * Remove duplicate categories, keeping only the first occurrence of each name/type/parent combination
     */
    public function removeDuplicates(string $userId): array {
        $categories = $this->findAll($userId);
        $seen = [];
        $deleted = [];

        foreach ($categories as $category) {
            // Create a unique key based on name, type, and parent
            $key = $category->getName() . '|' . $category->getType() . '|' . ($category->getParentId() ?? 'null');

            if (isset($seen[$key])) {
                // This is a duplicate - check if it has transactions
                $transactions = $this->transactionMapper->findByCategory($category->getId(), $userId, 1);
                if (empty($transactions)) {
                    // Check for children
                    $children = $this->getCategoryMapper()->findChildren($userId, $category->getId());
                    if (empty($children)) {
                        // Safe to delete
                        $this->mapper->delete($category);
                        $deleted[] = $category->getName();
                    }
                }
            } else {
                $seen[$key] = $category->getId();
            }
        }

        return $deleted;
    }

    /**
     * Delete all categories for a user
     */
    /**
     * Per-viewer report mute (card: write-shared excludedFromReports).
     * Hides a category from THIS user's reports only — the owner's
     * excluded_from_reports flag (which affects every viewer) is untouched.
     */
    public function setReportMute(int $categoryId, string $userId, bool $muted): void {
        if ($this->categoryMuteMapper === null) {
            return;
        }
        if ($muted) {
            $this->categoryMuteMapper->addMute($userId, $categoryId);
        } else {
            $this->categoryMuteMapper->removeMute($userId, $categoryId);
        }
    }

    /** @return int[] Category ids this user muted from their own reports */
    public function getMutedCategoryIds(string $userId): array {
        return $this->categoryMuteMapper?->findMutedCategoryIds($userId) ?? [];
    }

    public function deleteAll(string $userId): int {
        $categories = $this->findAll($userId);
        $count = 0;

        // Delete children first, then parents
        $parents = [];
        $children = [];

        foreach ($categories as $category) {
            if ($category->getParentId() === null) {
                $parents[] = $category;
            } else {
                $children[] = $category;
            }
        }

        // Delete children first
        foreach ($children as $category) {
            $transactions = $this->transactionMapper->findByCategory($category->getId(), $userId, 1);
            if (empty($transactions)) {
                $this->mapper->delete($category);
                $count++;
            }
        }

        // Then delete parents
        foreach ($parents as $category) {
            $remainingChildren = $this->getCategoryMapper()->findChildren($userId, $category->getId());
            $transactions = $this->transactionMapper->findByCategory($category->getId(), $userId, 1);
            if (empty($remainingChildren) && empty($transactions)) {
                $this->mapper->delete($category);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get suggested budget percentages
     */
    public function getSuggestedBudgetPercentages(): array {
        return [
            'Housing' => 30,
            'Food' => 15,
            'Transportation' => 10,
            'Healthcare' => 5,
            'Entertainment' => 5,
            'Shopping' => 5,
            'Savings' => 20,
            'Subscriptions' => 3,
            'Personal' => 5,
        ];
    }

    private function generateRandomColor(): string {
        $colors = [
            '#ef4444', '#f97316', '#f59e0b', '#eab308',
            '#84cc16', '#22c55e', '#10b981', '#14b8a6',
            '#06b6d4', '#0ea5e9', '#3b82f6', '#6366f1',
            '#8b5cf6', '#a855f7', '#d946ef', '#ec4899'
        ];

        return $colors[array_rand($colors)];
    }
}
