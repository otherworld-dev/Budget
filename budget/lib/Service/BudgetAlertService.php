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
    // Spending must exceed the budget by more than this (half a cent) to count
    // as over budget — spending that exactly meets the budget is "fully used",
    // not exceeded (issue #293).
    private const OVER_BUDGET_EPSILON = 0.005;

    public function __construct(
        CategoryMapper $categoryMapper,
        BudgetSnapshotMapper $budgetSnapshotMapper,
        TransactionMapper $transactionMapper,
        TransactionSplitMapper $splitMapper,
        SettingService $settingService,
        private RecurringBudgetService $recurringBudgetService,
        private BudgetCarryoverService $carryoverService
    ) {
        $this->categoryMapper = $categoryMapper;
        $this->budgetSnapshotMapper = $budgetSnapshotMapper;
        $this->transactionMapper = $transactionMapper;
        $this->splitMapper = $splitMapper;
        $this->settingService = $settingService;
    }

    /**
     * Resolve a category's effective budget for "now": snapshot override,
     * else the category's own budget, else the auto-derived recurring budget
     * (#269) converted to the category's period — plus envelope carryover —
     * so alerts and budget status agree with what the Budget view shows.
     *
     * 'amount' is the spendable total (base + carried); 'carried' may be
     * negative (overspend pulled forward), so 'amount' can be <= 0 for a
     * depleted envelope — those must still alert, not vanish.
     *
     * @return array{amount: float, period: string, base: float, carried: float}
     */
    private function resolveEffectiveBudget($category, array $snapshotOverrides, array $recurringBudgets, array $carryovers = []): array {
        $catId = $category->getId();
        $period = isset($snapshotOverrides[$catId])
            ? ($snapshotOverrides[$catId]['period'] ?? 'monthly')
            : ($category->getBudgetPeriod() ?? 'monthly');
        $amount = isset($snapshotOverrides[$catId])
            ? (float) ($snapshotOverrides[$catId]['amount'] ?? 0)
            : (float) ($category->getBudgetAmount() ?? 0);

        if ($amount <= 0 && isset($recurringBudgets[$catId])) {
            $amount = $this->recurringBudgetService->convertMonthlyToPeriod(
                (float) $recurringBudgets[$catId],
                $period
            );
        }

        $carried = (float) ($carryovers[$catId] ?? 0);

        return [
            'amount' => round($amount + $carried, 2),
            'period' => $period,
            'base' => $amount,
            'carried' => $carried,
        ];
    }

    /**
     * Classify spending against a resolved budget amount.
     *
     * Spending that strictly exceeds a positive budget is 'danger' (over
     * budget); a depleted envelope (no positive budget remaining) with any
     * spending is likewise over budget. Spending that merely meets the budget
     * (100% used) is 'warning', not exceeded — a full budget hasn't been
     * overspent (issue #293). Spending at or above the warning threshold but
     * within budget is 'warning'; anything below it is 'ok'.
     *
     * @return array{percentage: float, severity: string} percentage is a ratio (1.0 = 100%)
     */
    private function classifySpending(float $spent, float $budget): array {
        if ($budget > 0) {
            $percentage = $spent / $budget;
            $overBudget = $spent > $budget + self::OVER_BUDGET_EPSILON;
        } else {
            // Depleted envelope: any spending is over the (zero/negative) budget.
            $overBudget = $spent > 0;
            $percentage = $overBudget ? self::DANGER_THRESHOLD : 0.0;
        }

        if ($overBudget) {
            $severity = 'danger';
        } elseif ($percentage >= self::WARNING_THRESHOLD) {
            $severity = 'warning';
        } else {
            $severity = 'ok';
        }

        return ['percentage' => $percentage, 'severity' => $severity];
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
        $recurringBudgets = $this->recurringBudgetService->getMonthlyBudgetsByCategory($userId);
        $carryovers = $this->carryoverService->getCarryovers($userId, $currentMonth, $categories);

        // Categories with a budget in play: base > 0, or a non-zero envelope
        // carryover (a fully depleted envelope must still alert)
        $categoriesWithBudgets = [];
        foreach ($categories as $category) {
            if ($category->getExcludedFromReports()) {
                continue;
            }
            $resolved = $this->resolveEffectiveBudget($category, $snapshotOverrides, $recurringBudgets, $carryovers);
            if ($resolved['base'] > 0 || abs($resolved['carried']) >= 0.005) {
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
            $resolved = $this->resolveEffectiveBudget($category, $snapshotOverrides, $recurringBudgets, $carryovers);
            $period = $resolved['period'];
            $budget = $resolved['amount'];

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

            // Classify: exactly meeting the budget is "fully used", not over (#293).
            $classified = $this->classifySpending($spent, $budget);
            $percentage = $classified['percentage'];

            // Only create alert if at warning threshold or above
            if ($classified['severity'] !== 'ok') {
                $severity = $classified['severity'];

                $alerts[] = [
                    'categoryId' => $category->getId(),
                    'categoryName' => $category->getName(),
                    'categoryIcon' => $category->getIcon(),
                    'categoryColor' => $category->getColor(),
                    'budgetAmount' => $budget,
                    'budgetPeriod' => $period,
                    'carried' => round($resolved['carried'], 2),
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
        $recurringBudgets = $this->recurringBudgetService->getMonthlyBudgetsByCategory($userId);
        $carryovers = $this->carryoverService->getCarryovers($userId, $currentMonth, $categories);

        // Base budget > 0, or a non-zero envelope carryover (see getAlerts)
        $categoriesWithBudgets = [];
        foreach ($categories as $category) {
            if ($category->getExcludedFromReports()) {
                continue;
            }
            $resolved = $this->resolveEffectiveBudget($category, $snapshotOverrides, $recurringBudgets, $carryovers);
            if ($resolved['base'] > 0 || abs($resolved['carried']) >= 0.005) {
                $categoriesWithBudgets[] = $category;
            }
        }

        if (empty($categoriesWithBudgets)) {
            return [];
        }

        $startDay = $this->getBudgetStartDay($userId);
        $periodRanges = $this->calculatePeriodRanges($startDay);

        foreach ($categoriesWithBudgets as $category) {
            $resolved = $this->resolveEffectiveBudget($category, $snapshotOverrides, $recurringBudgets, $carryovers);
            $period = $resolved['period'];
            $budget = $resolved['amount'];

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

            // Classify: exactly meeting the budget is "fully used", not over (#293).
            $classified = $this->classifySpending($spent, $budget);
            $percentage = $classified['percentage'];
            $status = $classified['severity'];

            $statuses[] = [
                'categoryId' => $category->getId(),
                'categoryName' => $category->getName(),
                'categoryIcon' => $category->getIcon(),
                'categoryColor' => $category->getColor(),
                'budgetAmount' => $budget,
                'budgetPeriod' => $period,
                'carried' => round($resolved['carried'], 2),
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
