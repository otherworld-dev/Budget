<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TagSetMapper;
use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Db\TransactionMapper;
use OCP\AppFramework\Db\Entity;
use OCP\IL10N;

/**
 * @extends AbstractCrudService<Category>
 */
class CategoryService extends AbstractCrudService {
    private TransactionMapper $transactionMapper;
    private TagSetMapper $tagSetMapper;
    private TagMapper $tagMapper;
    private TransactionTagMapper $transactionTagMapper;
    private IL10N $l;

    public function __construct(
        CategoryMapper $mapper,
        TransactionMapper $transactionMapper,
        TagSetMapper $tagSetMapper,
        TagMapper $tagMapper,
        TransactionTagMapper $transactionTagMapper,
        IL10N $l
    ) {
        $this->mapper = $mapper;
        $this->transactionMapper = $transactionMapper;
        $this->tagSetMapper = $tagSetMapper;
        $this->tagMapper = $tagMapper;
        $this->transactionTagMapper = $transactionTagMapper;
        $this->l = $l;
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

    public function create(
        string $userId,
        string $name,
        string $type,
        ?int $parentId = null,
        ?string $icon = null,
        ?string $color = null,
        ?float $budgetAmount = null,
        int $sortOrder = 0
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
        $this->setTimestamps($category, true);

        return $this->mapper->insert($category);
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

        // Check for transactions
        $transactions = $this->transactionMapper->findByCategory($entity->getId(), $userId, 1);
        if (!empty($transactions)) {
            throw new \Exception($this->l->t('Cannot delete category with existing transactions'));
        }

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
    public function getCategoryDetails(int $categoryId, string $userId): array {
        $this->find($categoryId, $userId); // Verify ownership

        // Collect this category + all child category IDs for aggregation
        $categoryIds = [$categoryId];
        $children = $this->getCategoryMapper()->findChildren($userId, $categoryId);
        foreach ($children as $child) {
            $categoryIds[] = $child->getId();
        }

        $summary = $this->transactionMapper->getCategorySummary($userId, $categoryId, $categoryIds);
        $monthlySpending = $this->transactionMapper->getCategoryMonthlySpending($userId, $categoryId, 6, $categoryIds);

        $count = $summary['count'];
        $total = $summary['total'];
        $average = $count > 0 ? $total / $count : 0.0;

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
        ];
    }

    /**
     * Get recent transactions for a category (user-scoped)
     * @return Transaction[]
     */
    public function getCategoryTransactions(int $categoryId, string $userId, int $limit = 5): array {
        $this->find($categoryId, $userId); // Verify ownership
        return $this->transactionMapper->findByCategory($categoryId, $userId, $limit);
    }

    /**
     * Get transaction counts per category for tree display
     * @return array<int, int> categoryId => count
     */
    public function getCategoryTransactionCounts(string $userId): array {
        return $this->transactionMapper->getCategoryTransactionCounts($userId);
    }

    public function getCategorySpending(int $categoryId, string $userId, string $startDate, string $endDate): float {
        $this->find($categoryId, $userId); // Verify ownership
        return $this->getCategoryMapper()->getCategorySpending($categoryId, $startDate, $endDate);
    }

    /**
     * @param int[]|null $visibleAccountIds If provided, scope by account IDs for cross-user aggregation
     */
    public function getAllCategorySpending(string $userId, string $startDate, string $endDate, ?array $visibleAccountIds = null): array {
        $summary = $this->transactionMapper->getSpendingSummary($userId, $startDate, $endDate, null, [], true, false, $visibleAccountIds);

        return array_map(fn($item) => [
            'categoryId' => (int)$item['id'],
            'spent' => abs((float)($item['total'] ?? 0)),
            'name' => $item['name'] ?? '',
            'color' => $item['color'] ?? null,
            'count' => (int)($item['count'] ?? 0)
        ], $summary);
    }

    public function getBudgetAnalysis(string $userId, string $month = null): array {
        if (!$month) {
            $month = date('Y-m');
        }

        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));

        $categories = $this->findAll($userId);

        // Collect category IDs with budgets for batch query
        $categoryIds = [];
        foreach ($categories as $category) {
            if ($category->getBudgetAmount() > 0) {
                $categoryIds[] = $category->getId();
            }
        }

        // Single batch query for all spending (avoids N+1)
        $spendingMap = $this->transactionMapper->getCategorySpendingBatch(
            $categoryIds, $startDate, $endDate
        );

        $analysis = [];
        foreach ($categories as $category) {
            if ($category->getBudgetAmount() > 0) {
                $spent = $spendingMap[$category->getId()] ?? 0.0;
                $budget = $category->getBudgetAmount();
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

    public function createDefaultCategories(string $userId, ?float $monthlyIncome = null): array {
        // Default budget percentages based on 50/30/20 rule
        $defaultCategories = [
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

        $created = [];
        foreach ($defaultCategories as $categoryData) {
            $budgetAmount = null;
            if ($monthlyIncome && isset($categoryData['budgetPercent'])) {
                $budgetAmount = ($monthlyIncome * $categoryData['budgetPercent']) / 100;
            }

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

            if (isset($categoryData['children'])) {
                foreach ($categoryData['children'] as $childData) {
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
