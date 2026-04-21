<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;

class BudgetAlertService {
    private CategoryMapper $categoryMapper;
    private BudgetSnapshotMapper $budgetSnapshotMapper;
    private TransactionMapper $transactionMapper;
    private TransactionSplitMapper $splitMapper;
    private SettingService $settingService;

    // Alert thresholds
    private const WARNING_THRESHOLD = 0.80;  // 80%
    private const DANGER_THRESHOLD = 1.00;   // 100%

    public function __construct(
        CategoryMapper $categoryMapper,
        BudgetSnapshotMapper $budgetSnapshotMapper,
        TransactionMapper $transactionMapper,
        TransactionSplitMapper $splitMapper,
        SettingService $settingService
    ) {
        $this->categoryMapper = $categoryMapper;
        $this->budgetSnapshotMapper = $budgetSnapshotMapper;
        $this->transactionMapper = $transactionMapper;
        $this->splitMapper = $splitMapper;
        $this->settingService = $settingService;
    }

    /**
     * Get all budget alerts for a user.
     *
     * @return array Array of alerts with category info, spent, budget, percentage, and severity
     */
    public function getAlerts(string $userId): array {
        $alerts = [];

        // Get all categories and resolve effective budgets for current month
        $categories = $this->categoryMapper->findAll($userId);
        $currentMonth = date('Y-m');
        $snapshotOverrides = $this->budgetSnapshotMapper->findEffectiveBatch($userId, $currentMonth);

        // Filter to categories with effective budgets > 0
        $categoriesWithBudgets = [];
        foreach ($categories as $category) {
            $catId = $category->getId();
            $amount = isset($snapshotOverrides[$catId])
                ? (float) ($snapshotOverrides[$catId]['amount'] ?? 0)
                : (float) ($category->getBudgetAmount() ?? 0);
            if ($amount > 0) {
                $categoriesWithBudgets[] = $category;
            }
        }

        if (empty($categoriesWithBudgets)) {
            return [];
        }

        // Calculate date ranges for each period type
        $startDay = $this->getBudgetStartDay($userId);
        $periodRanges = $this->calculatePeriodRanges($startDay);

        foreach ($categoriesWithBudgets as $category) {
            $catId = $category->getId();
            $period = isset($snapshotOverrides[$catId])
                ? ($snapshotOverrides[$catId]['period'] ?? 'monthly')
                : ($category->getBudgetPeriod() ?? 'monthly');
            $budget = isset($snapshotOverrides[$catId])
                ? (float) ($snapshotOverrides[$catId]['amount'] ?? 0)
                : (float) ($category->getBudgetAmount() ?? 0);

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
        $currentMonth = date('Y-m');
        $snapshotOverrides = $this->budgetSnapshotMapper->findEffectiveBatch($userId, $currentMonth);

        // Filter to categories with effective budgets > 0
        $categoriesWithBudgets = [];
        foreach ($categories as $category) {
            $catId = $category->getId();
            $amount = isset($snapshotOverrides[$catId])
                ? (float) ($snapshotOverrides[$catId]['amount'] ?? 0)
                : (float) ($category->getBudgetAmount() ?? 0);
            if ($amount > 0) {
                $categoriesWithBudgets[] = $category;
            }
        }

        if (empty($categoriesWithBudgets)) {
            return [];
        }

        $startDay = $this->getBudgetStartDay($userId);
        $periodRanges = $this->calculatePeriodRanges($startDay);

        foreach ($categoriesWithBudgets as $category) {
            $catId = $category->getId();
            $period = isset($snapshotOverrides[$catId])
                ? ($snapshotOverrides[$catId]['period'] ?? 'monthly')
                : ($category->getBudgetPeriod() ?? 'monthly');
            $budget = isset($snapshotOverrides[$catId])
                ? (float) ($snapshotOverrides[$catId]['amount'] ?? 0)
                : (float) ($category->getBudgetAmount() ?? 0);

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
     * Get the user's configured budget start day (1-31).
     */
    private function getBudgetStartDay(string $userId): int {
        $value = $this->settingService->get($userId, 'budget_start_day');
        $startDay = $value !== null ? (int) $value : 1;
        return max(1, min(31, $startDay));
    }

    /**
     * Get the current date. Overridable in tests.
     */
    protected function getNow(): \DateTime {
        return new \DateTime();
    }

    /**
     * Calculate period date ranges.
     */
    private function calculatePeriodRanges(int $startDay = 1): array {
        $now = $this->getNow();
        $ranges = [];

        // Monthly: custom start day support
        $monthlyRange = $this->calculateMonthlyRange($now, $startDay);
        $ranges['monthly'] = $monthlyRange;

        // Weekly: Monday to Sunday of current week
        $weekStart = clone $now;
        $weekStart->modify('monday this week');
        $weekEnd = clone $now;
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
     * Calculate the monthly period range given a start day.
     * Clamps start day to the number of days in the month.
     */
    private function calculateMonthlyRange(\DateTime $now, int $startDay): array {
        if ($startDay === 1) {
            // Default behavior: 1st to last day of month
            $monthStart = new \DateTime($now->format('Y-m-01'));
            $monthEnd = new \DateTime($now->format('Y-m-t'));
            return [
                'start' => $monthStart->format('Y-m-d'),
                'end' => $monthEnd->format('Y-m-d'),
                'label' => $now->format('F Y'),
            ];
        }

        $currentDay = (int) $now->format('j');
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');

        // Clamp start day to days in current month
        $daysInCurrentMonth = (int) $now->format('t');
        $effectiveStartDay = min($startDay, $daysInCurrentMonth);

        if ($currentDay >= $effectiveStartDay) {
            // Period started this month
            $periodStart = new \DateTime(sprintf('%04d-%02d-%02d', $year, $month, $effectiveStartDay));

            // End is day before start day next month
            $nextMonth = $month + 1;
            $nextYear = $year;
            if ($nextMonth > 12) {
                $nextMonth = 1;
                $nextYear++;
            }
            $daysInNextMonth = (int) (new \DateTime(sprintf('%04d-%02d-01', $nextYear, $nextMonth)))->format('t');
            $effectiveNextStartDay = min($startDay, $daysInNextMonth);
            $nextPeriodStart = new \DateTime(sprintf('%04d-%02d-%02d', $nextYear, $nextMonth, $effectiveNextStartDay));
            $periodEnd = clone $nextPeriodStart;
            $periodEnd->modify('-1 day');
        } else {
            // Period started last month
            $prevMonth = $month - 1;
            $prevYear = $year;
            if ($prevMonth < 1) {
                $prevMonth = 12;
                $prevYear--;
            }
            $daysInPrevMonth = (int) (new \DateTime(sprintf('%04d-%02d-01', $prevYear, $prevMonth)))->format('t');
            $effectivePrevStartDay = min($startDay, $daysInPrevMonth);
            $periodStart = new \DateTime(sprintf('%04d-%02d-%02d', $prevYear, $prevMonth, $effectivePrevStartDay));

            // End is day before start day this month
            $thisPeriodEnd = new \DateTime(sprintf('%04d-%02d-%02d', $year, $month, $effectiveStartDay));
            $periodEnd = clone $thisPeriodEnd;
            $periodEnd->modify('-1 day');
        }

        $label = $periodStart->format('M j') . ' – ' . $periodEnd->format('M j');

        return [
            'start' => $periodStart->format('Y-m-d'),
            'end' => $periodEnd->format('Y-m-d'),
            'label' => $label,
        ];
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
