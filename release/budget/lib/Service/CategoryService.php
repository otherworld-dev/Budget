<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCP\AppFramework\Db\Entity;

/**
 * @extends AbstractCrudService<Category>
 */
class CategoryService extends AbstractCrudService {
    private TransactionMapper $transactionMapper;

    public function __construct(
        CategoryMapper $mapper,
        TransactionMapper $transactionMapper
    ) {
        $this->mapper = $mapper;
        $this->transactionMapper = $transactionMapper;
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
                throw new \Exception('Category cannot be its own parent');
            }
            $this->find($updates['parentId'], $userId);
        }
    }

    /**
     * @inheritDoc
     */
    protected function beforeDelete(Entity $entity, string $userId): void {
        // Check for child categories
        $children = $this->getCategoryMapper()->findChildren($userId, $entity->getId());
        if (!empty($children)) {
            throw new \Exception('Cannot delete category with subcategories');
        }

        // Check for transactions
        $transactions = $this->transactionMapper->findByCategory($entity->getId(), 1);
        if (!empty($transactions)) {
            throw new \Exception('Cannot delete category with existing transactions');
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

    public function getCategorySpending(int $categoryId, string $userId, string $startDate, string $endDate): float {
        $this->find($categoryId, $userId); // Verify ownership
        return $this->getCategoryMapper()->getCategorySpending($categoryId, $startDate, $endDate);
    }

    public function getAllCategorySpending(string $userId, string $startDate, string $endDate): array {
        $summary = $this->transactionMapper->getSpendingSummary($userId, $startDate, $endDate);

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
                $transactions = $this->transactionMapper->findByCategory($category->getId(), 1);
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
            $transactions = $this->transactionMapper->findByCategory($category->getId(), 1);
            if (empty($transactions)) {
                $this->mapper->delete($category);
                $count++;
            }
        }

        // Then delete parents
        foreach ($parents as $category) {
            $remainingChildren = $this->getCategoryMapper()->findChildren($userId, $category->getId());
            $transactions = $this->transactionMapper->findByCategory($category->getId(), 1);
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
