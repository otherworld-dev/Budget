<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\CategoryMapper;

/**
 * Service for year-over-year comparison calculations.
 */
class YearOverYearService {
    private TransactionMapper $transactionMapper;
    private CategoryMapper $categoryMapper;

    public function __construct(
        TransactionMapper $transactionMapper,
        CategoryMapper $categoryMapper
    ) {
        $this->transactionMapper = $transactionMapper;
        $this->categoryMapper = $categoryMapper;
    }

    /**
     * Compare the same month across multiple years.
     *
     * @param string $userId User ID
     * @param int $month Month number (1-12)
     * @param int $years Number of years to compare (default 3)
     * @return array Year comparison data
     */
    public function compareMonth(string $userId, int $month, int $years = 3): array {
        $currentYear = (int) date('Y');
        $results = [];

        for ($i = 0; $i < $years; $i++) {
            $year = $currentYear - $i;
            $startDate = sprintf('%04d-%02d-01', $year, $month);
            $endDate = date('Y-m-t', strtotime($startDate));

            $monthData = $this->getMonthSummary($userId, $startDate, $endDate);
            $monthData['year'] = $year;
            $monthData['month'] = $month;
            $monthData['monthName'] = date('F', strtotime($startDate));

            $results[] = $monthData;
        }

        // Calculate changes from previous year
        for ($i = 0; $i < count($results) - 1; $i++) {
            $current = &$results[$i];
            $previous = $results[$i + 1];

            $current['incomeChange'] = $this->calculatePercentChange($current['income'], $previous['income']);
            $current['expenseChange'] = $this->calculatePercentChange($current['expenses'], $previous['expenses']);
            $current['savingsChange'] = $current['savings'] - $previous['savings'];
        }

        return [
            'type' => 'month',
            'month' => $month,
            'monthName' => date('F', mktime(0, 0, 0, $month, 1)),
            'years' => $results,
        ];
    }

    /**
     * Compare full years.
     *
     * @param string $userId User ID
     * @param int $years Number of years to compare
     * @return array Year comparison data
     */
    public function compareYears(string $userId, int $years = 3): array {
        $currentYear = (int) date('Y');
        $results = [];

        for ($i = 0; $i < $years; $i++) {
            $year = $currentYear - $i;
            $startDate = sprintf('%04d-01-01', $year);
            $endDate = sprintf('%04d-12-31', $year);

            // For current year, only include up to current date
            if ($year === $currentYear) {
                $endDate = date('Y-m-d');
            }

            $yearData = $this->getYearSummary($userId, $year, $startDate, $endDate);
            $yearData['year'] = $year;
            $yearData['isCurrent'] = ($year === $currentYear);

            $results[] = $yearData;
        }

        // Calculate changes from previous year
        for ($i = 0; $i < count($results) - 1; $i++) {
            $current = &$results[$i];
            $previous = $results[$i + 1];

            $current['incomeChange'] = $this->calculatePercentChange($current['income'], $previous['income']);
            $current['expenseChange'] = $this->calculatePercentChange($current['expenses'], $previous['expenses']);
            $current['savingsChange'] = $current['savings'] - $previous['savings'];
        }

        return [
            'type' => 'year',
            'years' => $results,
        ];
    }

    /**
     * Compare spending by category across years.
     *
     * @param string $userId User ID
     * @param int $years Number of years to compare
     * @return array Category comparison data
     */
    public function compareCategorySpending(string $userId, int $years = 2): array {
        $currentYear = (int) date('Y');
        $categories = $this->categoryMapper->findAll($userId);
        $categoryData = [];

        // Get spending for each category per year
        foreach ($categories as $category) {
            if ($category->getType() !== 'expense') {
                continue;
            }

            $categoryYears = [];
            for ($i = 0; $i < $years; $i++) {
                $year = $currentYear - $i;
                $startDate = sprintf('%04d-01-01', $year);
                $endDate = sprintf('%04d-12-31', $year);

                if ($year === $currentYear) {
                    $endDate = date('Y-m-d');
                }

                $spending = $this->transactionMapper->getCategorySpending(
                    $userId,
                    $category->getId(),
                    $startDate,
                    $endDate
                );

                $categoryYears[] = [
                    'year' => $year,
                    'spending' => round($spending, 2),
                ];
            }

            // Calculate change
            $change = null;
            if (count($categoryYears) >= 2 && $categoryYears[1]['spending'] > 0) {
                $change = $this->calculatePercentChange(
                    $categoryYears[0]['spending'],
                    $categoryYears[1]['spending']
                );
            }

            $categoryData[] = [
                'id' => $category->getId(),
                'name' => $category->getName(),
                'years' => $categoryYears,
                'change' => $change,
            ];
        }

        // Sort by current year spending (descending)
        usort($categoryData, function ($a, $b) {
            $aSpending = $a['years'][0]['spending'] ?? 0;
            $bSpending = $b['years'][0]['spending'] ?? 0;
            return $bSpending <=> $aSpending;
        });

        return [
            'type' => 'category',
            'categories' => $categoryData,
        ];
    }

    /**
     * Get monthly breakdown for year comparison.
     *
     * @param string $userId User ID
     * @param int $years Number of years to compare
     * @return array Monthly data for each year
     */
    public function getMonthlyTrends(string $userId, int $years = 2): array {
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');
        $result = [];

        for ($i = 0; $i < $years; $i++) {
            $year = $currentYear - $i;
            $yearData = [
                'year' => $year,
                'months' => [],
            ];

            $maxMonth = ($year === $currentYear) ? $currentMonth : 12;

            for ($month = 1; $month <= $maxMonth; $month++) {
                $startDate = sprintf('%04d-%02d-01', $year, $month);
                $endDate = date('Y-m-t', strtotime($startDate));

                $monthSummary = $this->getMonthSummary($userId, $startDate, $endDate);
                $monthSummary['month'] = $month;
                $monthSummary['monthName'] = date('M', mktime(0, 0, 0, $month, 1));

                $yearData['months'][] = $monthSummary;
            }

            // Calculate totals
            $yearData['totalIncome'] = array_sum(array_column($yearData['months'], 'income'));
            $yearData['totalExpenses'] = array_sum(array_column($yearData['months'], 'expenses'));
            $yearData['totalSavings'] = $yearData['totalIncome'] - $yearData['totalExpenses'];
            $yearData['avgMonthlyIncome'] = $maxMonth > 0 ? round($yearData['totalIncome'] / $maxMonth, 2) : 0;
            $yearData['avgMonthlyExpenses'] = $maxMonth > 0 ? round($yearData['totalExpenses'] / $maxMonth, 2) : 0;

            $result[] = $yearData;
        }

        return [
            'type' => 'monthly_trends',
            'years' => $result,
        ];
    }

    /**
     * Get month summary data.
     */
    private function getMonthSummary(string $userId, string $startDate, string $endDate): array {
        $transactions = $this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate);

        $income = 0.0;
        $expenses = 0.0;

        foreach ($transactions as $tx) {
            $amount = abs((float) $tx->getAmount());
            if ($tx->getType() === 'credit') {
                $income += $amount;
            } else {
                $expenses += $amount;
            }
        }

        return [
            'income' => round($income, 2),
            'expenses' => round($expenses, 2),
            'savings' => round($income - $expenses, 2),
            'transactionCount' => count($transactions),
        ];
    }

    /**
     * Get year summary data with monthly breakdowns.
     */
    private function getYearSummary(string $userId, int $year, string $startDate, string $endDate): array {
        $transactions = $this->transactionMapper->findAllByUserAndDateRange($userId, $startDate, $endDate);

        $income = 0.0;
        $expenses = 0.0;
        $monthlyData = [];

        foreach ($transactions as $tx) {
            $amount = abs((float) $tx->getAmount());
            $txDate = $tx->getDate();
            $month = (int) date('n', strtotime($txDate));

            if (!isset($monthlyData[$month])) {
                $monthlyData[$month] = ['income' => 0, 'expenses' => 0];
            }

            if ($tx->getType() === 'credit') {
                $income += $amount;
                $monthlyData[$month]['income'] += $amount;
            } else {
                $expenses += $amount;
                $monthlyData[$month]['expenses'] += $amount;
            }
        }

        // Calculate averages
        $monthCount = count($monthlyData);

        return [
            'income' => round($income, 2),
            'expenses' => round($expenses, 2),
            'savings' => round($income - $expenses, 2),
            'transactionCount' => count($transactions),
            'avgMonthlyIncome' => $monthCount > 0 ? round($income / $monthCount, 2) : 0,
            'avgMonthlyExpenses' => $monthCount > 0 ? round($expenses / $monthCount, 2) : 0,
            'monthsWithData' => $monthCount,
        ];
    }

    /**
     * Calculate percent change.
     */
    private function calculatePercentChange(float $current, float $previous): ?float {
        if ($previous == 0) {
            return $current > 0 ? 100.0 : ($current < 0 ? -100.0 : null);
        }
        return round((($current - $previous) / $previous) * 100, 1);
    }
}
