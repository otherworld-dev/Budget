<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Report;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;

/**
 * Handles calculation of spending and income metrics.
 */
class ReportCalculator {
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;

    public function __construct(
        AccountMapper $accountMapper,
        TransactionMapper $transactionMapper
    ) {
        $this->accountMapper = $accountMapper;
        $this->transactionMapper = $transactionMapper;
    }

    /**
     * Get spending grouped by category.
     */
    public function getSpendingByCategory(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate
    ): array {
        return $this->transactionMapper->getSpendingSummary($userId, $startDate, $endDate);
    }

    /**
     * Get spending grouped by month.
     */
    public function getSpendingByMonth(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate
    ): array {
        $data = $this->transactionMapper->getSpendingByMonth($userId, $accountId, $startDate, $endDate);
        return array_map(fn($row) => [
            'name' => $this->formatMonthLabel($row['month']),
            'month' => $row['month'],
            'total' => (float)$row['total'],
            'count' => (int)$row['count']
        ], $data);
    }

    /**
     * Get spending grouped by vendor.
     */
    public function getSpendingByVendor(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate
    ): array {
        return $this->transactionMapper->getSpendingByVendor($userId, $accountId, $startDate, $endDate);
    }

    /**
     * Get spending grouped by account.
     * OPTIMIZED: Uses single aggregated SQL query instead of N+1 pattern.
     */
    public function getSpendingByAccount(
        string $userId,
        string $startDate,
        string $endDate
    ): array {
        return $this->transactionMapper->getSpendingByAccountAggregated($userId, $startDate, $endDate);
    }

    /**
     * Get income grouped by category.
     */
    public function getIncomeByCategory(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate
    ): array {
        // For income by category, use income by source as a proxy
        return $this->getIncomeBySource($userId, $accountId, $startDate, $endDate);
    }

    /**
     * Get income grouped by month.
     */
    public function getIncomeByMonth(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate
    ): array {
        $data = $this->transactionMapper->getIncomeByMonth($userId, $accountId, $startDate, $endDate);
        return array_map(fn($row) => [
            'name' => $this->formatMonthLabel($row['month']),
            'month' => $row['month'],
            'total' => (float)$row['total'],
            'count' => (int)$row['count']
        ], $data);
    }

    /**
     * Get income grouped by source/vendor.
     */
    public function getIncomeBySource(
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate
    ): array {
        return $this->transactionMapper->getIncomeBySource($userId, $accountId, $startDate, $endDate);
    }

    /**
     * Calculate percentage change between two values.
     */
    public function calculatePercentChange(float $previous, float $current): array {
        if ($previous == 0) {
            return [
                'percentage' => $current > 0 ? 100 : 0,
                'direction' => $current > 0 ? 'up' : ($current < 0 ? 'down' : 'none'),
                'absolute' => $current - $previous
            ];
        }

        $change = (($current - $previous) / abs($previous)) * 100;
        return [
            'percentage' => round(abs($change), 1),
            'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'none'),
            'absolute' => $current - $previous
        ];
    }

    /**
     * Format a YYYY-MM string as a human-readable month label.
     */
    public function formatMonthLabel(string $yearMonth): string {
        $date = \DateTime::createFromFormat('Y-m', $yearMonth);
        return $date ? $date->format('M Y') : $yearMonth;
    }

    /**
     * Get budget status based on spending percentage.
     */
    public function getBudgetStatus(float $percentage): string {
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
     * Calculate totals from report data items.
     */
    public function calculateTotals(array $data): array {
        $amount = 0;
        $transactions = 0;

        foreach ($data as $item) {
            $amount += $item['total'];
            $transactions += $item['count'];
        }

        return [
            'amount' => $amount,
            'transactions' => $transactions
        ];
    }
}
