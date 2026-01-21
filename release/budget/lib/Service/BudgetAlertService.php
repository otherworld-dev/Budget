<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;

class BudgetAlertService {
    private CategoryMapper $categoryMapper;
    private TransactionMapper $transactionMapper;
    private TransactionSplitMapper $splitMapper;

    // Alert thresholds
    private const WARNING_THRESHOLD = 0.80;  // 80%
    private const DANGER_THRESHOLD = 1.00;   // 100%

    public function __construct(
        CategoryMapper $categoryMapper,
        TransactionMapper $transactionMapper,
        TransactionSplitMapper $splitMapper
    ) {
        $this->categoryMapper = $categoryMapper;
        $this->transactionMapper = $transactionMapper;
        $this->splitMapper = $splitMapper;
    }

    /**
     * Get all budget alerts for a user.
     *
     * @return array Array of alerts with category info, spent, budget, percentage, and severity
     */
    public function getAlerts(string $userId): array {
        $alerts = [];

        // Get all categories with budgets
        $categories = $this->categoryMapper->findAll($userId);
        $categoriesWithBudgets = array_filter($categories, fn($c) => $c->getBudgetAmount() > 0);

        if (empty($categoriesWithBudgets)) {
            return [];
        }

        // Calculate date ranges for each period type
        $periodRanges = $this->calculatePeriodRanges();

        foreach ($categoriesWithBudgets as $category) {
            $period = $category->getBudgetPeriod() ?? 'monthly';
            $budget = (float) $category->getBudgetAmount();

            if (!isset($periodRanges[$period])) {
                continue;
            }

            $range = $periodRanges[$period];

            // Get spending for this category in the current period
            $spent = $this->getCategorySpending(
                $userId,
                $category->getId(),
                $range['start'],
                $range['end']
            );

            $percentage = $budget > 0 ? ($spent / $budget) : 0;

            // Only create alert if at warning threshold or above
            if ($percentage >= self::WARNING_THRESHOLD) {
                $severity = $percentage >= self::DANGER_THRESHOLD ? 'danger' : 'warning';

                $alerts[] = [
                    'categoryId' => $category->getId(),
                    'categoryName' => $category->getName(),
                    'categoryIcon' => $category->getIcon(),
                    'categoryColor' => $category->getColor(),
                    'budgetAmount' => $budget,
                    'budgetPeriod' => $period,
                    'spent' => round($spent, 2),
                    'remaining' => round(max(0, $budget - $spent), 2),
                    'percentage' => round($percentage * 100, 1),
                    'severity' => $severity,
                    'periodStart' => $range['start'],
                    'periodEnd' => $range['end'],
                    'periodLabel' => $range['label'],
                ];
            }
        }

        // Sort by severity (danger first) then by percentage (highest first)
        usort($alerts, function($a, $b) {
            if ($a['severity'] !== $b['severity']) {
                return $a['severity'] === 'danger' ? -1 : 1;
            }
            return $b['percentage'] <=> $a['percentage'];
        });

        return $alerts;
    }

    /**
     * Get budget status for all categories (not just alerts).
     *
     * @return array Array of all categories with budget status
     */
    public function getBudgetStatus(string $userId): array {
        $statuses = [];

        $categories = $this->categoryMapper->findAll($userId);
        $categoriesWithBudgets = array_filter($categories, fn($c) => $c->getBudgetAmount() > 0);

        if (empty($categoriesWithBudgets)) {
            return [];
        }

        $periodRanges = $this->calculatePeriodRanges();

        foreach ($categoriesWithBudgets as $category) {
            $period = $category->getBudgetPeriod() ?? 'monthly';
            $budget = (float) $category->getBudgetAmount();

            if (!isset($periodRanges[$period])) {
                continue;
            }

            $range = $periodRanges[$period];

            $spent = $this->getCategorySpending(
                $userId,
                $category->getId(),
                $range['start'],
                $range['end']
            );

            $percentage = $budget > 0 ? ($spent / $budget) : 0;

            $status = 'ok';
            if ($percentage >= self::DANGER_THRESHOLD) {
                $status = 'danger';
            } elseif ($percentage >= self::WARNING_THRESHOLD) {
                $status = 'warning';
            }

            $statuses[] = [
                'categoryId' => $category->getId(),
                'categoryName' => $category->getName(),
                'categoryIcon' => $category->getIcon(),
                'categoryColor' => $category->getColor(),
                'budgetAmount' => $budget,
                'budgetPeriod' => $period,
                'spent' => round($spent, 2),
                'remaining' => round($budget - $spent, 2),
                'percentage' => round($percentage * 100, 1),
                'status' => $status,
                'periodLabel' => $range['label'],
            ];
        }

        // Sort by percentage descending
        usort($statuses, fn($a, $b) => $b['percentage'] <=> $a['percentage']);

        return $statuses;
    }

    /**
     * Get summary statistics for budget alerts.
     */
    public function getSummary(string $userId): array {
        $statuses = $this->getBudgetStatus($userId);

        $totalBudget = 0;
        $totalSpent = 0;
        $overBudgetCount = 0;
        $warningCount = 0;
        $onTrackCount = 0;

        foreach ($statuses as $s) {
            $totalBudget += $s['budgetAmount'];
            $totalSpent += $s['spent'];

            if ($s['status'] === 'danger') {
                $overBudgetCount++;
            } elseif ($s['status'] === 'warning') {
                $warningCount++;
            } else {
                $onTrackCount++;
            }
        }

        return [
            'totalCategories' => count($statuses),
            'totalBudget' => round($totalBudget, 2),
            'totalSpent' => round($totalSpent, 2),
            'totalRemaining' => round($totalBudget - $totalSpent, 2),
            'overallPercentage' => $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100, 1) : 0,
            'overBudgetCount' => $overBudgetCount,
            'warningCount' => $warningCount,
            'onTrackCount' => $onTrackCount,
        ];
    }

    /**
     * Calculate period date ranges.
     */
    private function calculatePeriodRanges(): array {
        $now = new \DateTime();
        $ranges = [];

        // Monthly: 1st to last day of current month
        $monthStart = new \DateTime($now->format('Y-m-01'));
        $monthEnd = new \DateTime($now->format('Y-m-t'));
        $ranges['monthly'] = [
            'start' => $monthStart->format('Y-m-d'),
            'end' => $monthEnd->format('Y-m-d'),
            'label' => $now->format('F Y'),
        ];

        // Weekly: Monday to Sunday of current week
        $weekStart = new \DateTime();
        $weekStart->modify('monday this week');
        $weekEnd = new \DateTime();
        $weekEnd->modify('sunday this week');
        $ranges['weekly'] = [
            'start' => $weekStart->format('Y-m-d'),
            'end' => $weekEnd->format('Y-m-d'),
            'label' => 'Week of ' . $weekStart->format('M j'),
        ];

        // Quarterly: First day of quarter to last day of quarter
        $quarter = ceil((int)$now->format('n') / 3);
        $quarterStart = new \DateTime($now->format('Y') . '-' . (($quarter - 1) * 3 + 1) . '-01');
        $quarterEnd = clone $quarterStart;
        $quarterEnd->modify('+3 months -1 day');
        $ranges['quarterly'] = [
            'start' => $quarterStart->format('Y-m-d'),
            'end' => $quarterEnd->format('Y-m-d'),
            'label' => 'Q' . $quarter . ' ' . $now->format('Y'),
        ];

        // Yearly: Jan 1 to Dec 31
        $yearStart = new \DateTime($now->format('Y-01-01'));
        $yearEnd = new \DateTime($now->format('Y-12-31'));
        $ranges['yearly'] = [
            'start' => $yearStart->format('Y-m-d'),
            'end' => $yearEnd->format('Y-m-d'),
            'label' => $now->format('Y'),
        ];

        return $ranges;
    }

    /**
     * Get total spending for a category within a date range.
     * Includes both direct transactions and split allocations.
     */
    private function getCategorySpending(string $userId, int $categoryId, string $startDate, string $endDate): float {
        // Get direct spending (non-split transactions)
        $directSpending = $this->transactionMapper->getCategorySpending(
            $userId,
            $categoryId,
            $startDate,
            $endDate
        );

        // Get spending from splits
        $splitSpending = $this->getSplitCategorySpending(
            $userId,
            $categoryId,
            $startDate,
            $endDate
        );

        return $directSpending + $splitSpending;
    }

    /**
     * Get spending from transaction splits for a category.
     */
    private function getSplitCategorySpending(string $userId, int $categoryId, string $startDate, string $endDate): float {
        // Get split transactions in date range
        $splitTransactionIds = $this->transactionMapper->getSplitTransactionIds($userId, $startDate, $endDate);

        if (empty($splitTransactionIds)) {
            return 0.0;
        }

        // Get category totals from those splits
        $categoryTotals = $this->splitMapper->getCategoryTotals($splitTransactionIds);

        return $categoryTotals[$categoryId] ?? 0.0;
    }
}
