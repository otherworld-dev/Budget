<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Service\Report\ReportCalculator;
use OCA\Budget\Service\Report\ReportAggregator;
use OCA\Budget\Service\Report\ReportExporter;

/**
 * Orchestrates report generation by delegating to specialized services.
 */
class ReportService {
    private ReportCalculator $calculator;
    private ReportAggregator $aggregator;
    private ReportExporter $exporter;

    public function __construct(
        ReportCalculator $calculator,
        ReportAggregator $aggregator,
        ReportExporter $exporter
    ) {
        $this->calculator = $calculator;
        $this->aggregator = $aggregator;
        $this->exporter = $exporter;
    }

    /**
     * Generate a comprehensive financial summary.
     */
    public function generateSummary(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null
    ): array {
        return $this->aggregator->generateSummary($userId, $accountId, $startDate, $endDate);
    }

    /**
     * Generate summary with comparison to previous period.
     */
    public function generateSummaryWithComparison(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null
    ): array {
        return $this->aggregator->generateSummaryWithComparison($userId, $accountId, $startDate, $endDate);
    }

    /**
     * Generate a spending report grouped by the specified dimension.
     */
    public function getSpendingReport(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        string $groupBy = 'category'
    ): array {
        $report = [
            'period' => [
                'startDate' => $startDate,
                'endDate' => $endDate
            ],
            'groupBy' => $groupBy,
            'data' => [],
            'totals' => [
                'amount' => 0,
                'transactions' => 0
            ]
        ];

        $report['data'] = match ($groupBy) {
            'category' => $this->calculator->getSpendingByCategory($userId, $accountId, $startDate, $endDate),
            'month' => $this->calculator->getSpendingByMonth($userId, $accountId, $startDate, $endDate),
            'vendor' => $this->calculator->getSpendingByVendor($userId, $accountId, $startDate, $endDate),
            'account' => $this->calculator->getSpendingByAccount($userId, $startDate, $endDate),
            default => [],
        };

        $report['totals'] = $this->calculator->calculateTotals($report['data']);

        return $report;
    }

    /**
     * Generate an income report grouped by the specified dimension.
     */
    public function getIncomeReport(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        string $groupBy = 'month'
    ): array {
        $report = [
            'period' => [
                'startDate' => $startDate,
                'endDate' => $endDate
            ],
            'groupBy' => $groupBy,
            'data' => [],
            'totals' => [
                'amount' => 0,
                'transactions' => 0
            ]
        ];

        $report['data'] = match ($groupBy) {
            'category' => $this->calculator->getIncomeByCategory($userId, $accountId, $startDate, $endDate),
            'month' => $this->calculator->getIncomeByMonth($userId, $accountId, $startDate, $endDate),
            'source' => $this->calculator->getIncomeBySource($userId, $accountId, $startDate, $endDate),
            default => [],
        };

        $report['totals'] = $this->calculator->calculateTotals($report['data']);

        return $report;
    }

    /**
     * Generate a budget report with category-by-category breakdown.
     */
    public function getBudgetReport(string $userId, string $startDate, string $endDate): array {
        return $this->aggregator->getBudgetReport($userId, $startDate, $endDate);
    }

    /**
     * Generate a cash flow report by month.
     */
    public function getCashFlowReport(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null
    ): array {
        return $this->aggregator->getCashFlowReport($userId, $accountId, $startDate, $endDate);
    }

    /**
     * Export a report to the specified format.
     *
     * @param string $userId User ID
     * @param string $type Report type (summary, spending, income, cashflow, budget)
     * @param string $format Export format (csv, json, pdf)
     * @param int|null $accountId Optional account filter
     * @param string $startDate Start date
     * @param string $endDate End date
     * @return array{stream: string, contentType: string, filename: string}
     */
    public function exportReport(
        string $userId,
        string $type,
        string $format,
        string $startDate,
        string $endDate,
        ?int $accountId = null
    ): array {
        // Generate the report data
        $data = match ($type) {
            'summary' => $this->generateSummaryWithComparison($userId, $startDate, $endDate, $accountId),
            'spending' => $this->getSpendingReport($userId, $startDate, $endDate, $accountId),
            'income' => $this->getIncomeReport($userId, $startDate, $endDate, $accountId),
            'cashflow' => $this->getCashFlowReport($userId, $startDate, $endDate, $accountId),
            'budget' => $this->getBudgetReport($userId, $startDate, $endDate),
            default => throw new \InvalidArgumentException('Unknown report type: ' . $type),
        };

        return $this->exporter->export($data, $type, $format);
    }
}
