<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Service\Report\ReportCalculator;
use OCA\Budget\Service\Report\ReportAggregator;
use OCA\Budget\Service\Report\ReportExporter;
use OCA\Budget\Service\Report\TagReportService;

/**
 * Orchestrates report generation by delegating to specialized services.
 */
class ReportService {
    private ReportCalculator $calculator;
    private ReportAggregator $aggregator;
    private ReportExporter $exporter;
    private TagReportService $tagReportService;

    public function __construct(
        ReportCalculator $calculator,
        ReportAggregator $aggregator,
        ReportExporter $exporter,
        TagReportService $tagReportService
    ) {
        $this->calculator = $calculator;
        $this->aggregator = $aggregator;
        $this->exporter = $exporter;
        $this->tagReportService = $tagReportService;
    }

    /**
     * Generate a comprehensive financial summary.
     * @param int[] $tagIds Optional tag filter (OR logic)
     * @param bool $includeUntagged Include untagged transactions
     */
    public function generateSummary(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        array $tagIds = [],
        bool $includeUntagged = true
    ): array {
        return $this->aggregator->generateSummary(
            $userId,
            $accountId,
            $startDate,
            $endDate,
            $tagIds,
            $includeUntagged
        );
    }

    /**
     * Generate summary with comparison to previous period.
     * @param int[] $tagIds Optional tag filter (OR logic)
     * @param bool $includeUntagged Include untagged transactions
     */
    public function generateSummaryWithComparison(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        array $tagIds = [],
        bool $includeUntagged = true
    ): array {
        return $this->aggregator->generateSummaryWithComparison(
            $userId,
            $accountId,
            $startDate,
            $endDate,
            $tagIds,
            $includeUntagged
        );
    }

    /**
     * Generate a spending report grouped by the specified dimension.
     * @param int|null $tagSetId Tag set ID when groupBy='tag'
     * @param int|null $categoryId Category filter when groupBy='tag'
     */
    public function getSpendingReport(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        string $groupBy = 'category',
        ?int $tagSetId = null,
        ?int $categoryId = null
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
            'tag' => $tagSetId !== null
                ? $this->calculator->getSpendingByTag($userId, $tagSetId, $startDate, $endDate, $accountId, $categoryId)
                : [],
            default => [],
        };

        if ($groupBy === 'tag' && $tagSetId !== null) {
            $report['tagSetId'] = $tagSetId;
        }

        $report['totals'] = $this->calculator->calculateTotals($report['data']);

        return $report;
    }

    /**
     * Generate an income report grouped by the specified dimension.
     * @param int|null $tagSetId Tag set ID when groupBy='tag'
     * @param int|null $categoryId Category filter when groupBy='tag'
     */
    public function getIncomeReport(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        string $groupBy = 'month',
        ?int $tagSetId = null,
        ?int $categoryId = null
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
            'tag' => $tagSetId !== null
                ? $this->calculator->getIncomeByTag($userId, $tagSetId, $startDate, $endDate, $accountId, $categoryId)
                : [],
            default => [],
        };

        if ($groupBy === 'tag' && $tagSetId !== null) {
            $report['tagSetId'] = $tagSetId;
        }

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
     * @param int[] $tagIds Optional tag filter (OR logic)
     * @param bool $includeUntagged Include untagged transactions
     */
    public function getCashFlowReport(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        array $tagIds = [],
        bool $includeUntagged = true
    ): array {
        return $this->aggregator->getCashFlowReport(
            $userId,
            $accountId,
            $startDate,
            $endDate,
            $tagIds,
            $includeUntagged
        );
    }

    /**
     * Get tag dimensions for spending across categories.
     */
    public function getTagDimensions(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null
    ): array {
        return $this->aggregator->getTagDimensions(
            $userId,
            $startDate,
            $endDate,
            $accountId,
            $categoryId
        );
    }

    /**
     * Get tag combination report.
     */
    public function getTagCombinationReport(
        string $userId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null,
        int $minCombinationSize = 2,
        int $limit = 50
    ): array {
        return $this->tagReportService->getTagCombinationReport(
            $userId,
            $startDate,
            $endDate,
            $accountId,
            $categoryId,
            $minCombinationSize,
            $limit
        );
    }

    /**
     * Get cross-tabulation (pivot table) of two tag sets.
     */
    public function getTagCrossTabulation(
        string $userId,
        int $tagSetId1,
        int $tagSetId2,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null
    ): array {
        return $this->tagReportService->getCrossTabulation(
            $userId,
            $tagSetId1,
            $tagSetId2,
            $startDate,
            $endDate,
            $accountId,
            $categoryId
        );
    }

    /**
     * Get monthly trend for specific tags.
     */
    public function getTagTrendReport(
        string $userId,
        array $tagIds,
        string $startDate,
        string $endDate,
        ?int $accountId = null
    ): array {
        return $this->tagReportService->getTagTrendReport(
            $userId,
            $tagIds,
            $startDate,
            $endDate,
            $accountId
        );
    }

    /**
     * Get spending breakdown by a specific tag set.
     */
    public function getTagSetBreakdown(
        string $userId,
        int $tagSetId,
        string $startDate,
        string $endDate,
        ?int $accountId = null,
        ?int $categoryId = null
    ): array {
        return $this->tagReportService->getTagSetBreakdown(
            $userId,
            $tagSetId,
            $startDate,
            $endDate,
            $accountId,
            $categoryId
        );
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
