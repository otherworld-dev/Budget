<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\Report\ReportAggregator;
use OCA\Budget\Service\Report\ReportCalculator;
use OCA\Budget\Service\Report\ReportExporter;
use OCA\Budget\Service\Report\TagReportService;
use OCA\Budget\Service\ReportService;
use PHPUnit\Framework\TestCase;

class ReportServiceTest extends TestCase {
    private ReportService $service;
    private ReportCalculator $calculator;
    private ReportAggregator $aggregator;
    private ReportExporter $exporter;
    private TagReportService $tagReportService;

    protected function setUp(): void {
        $this->calculator = $this->createMock(ReportCalculator::class);
        $this->aggregator = $this->createMock(ReportAggregator::class);
        $this->exporter = $this->createMock(ReportExporter::class);
        $this->tagReportService = $this->createMock(TagReportService::class);

        $this->service = new ReportService(
            $this->calculator,
            $this->aggregator,
            $this->exporter,
            $this->tagReportService
        );
    }

    // ===== generateSummary =====

    public function testGenerateSummaryDelegatesToAggregator(): void {
        $expected = ['totals' => ['totalIncome' => 5000.0]];
        $this->aggregator->expects($this->once())->method('generateSummary')
            ->with('user1', null, '2025-01-01', '2025-12-31', [], true)
            ->willReturn($expected);

        $result = $this->service->generateSummary('user1', '2025-01-01', '2025-12-31');
        $this->assertSame($expected, $result);
    }

    public function testGenerateSummaryWithFilters(): void {
        $this->aggregator->expects($this->once())->method('generateSummary')
            ->with('user1', 5, '2025-01-01', '2025-06-30', [1, 2], false);

        $this->service->generateSummary('user1', '2025-01-01', '2025-06-30', 5, [1, 2], false);
    }

    // ===== generateSummaryWithComparison =====

    public function testGenerateSummaryWithComparisonDelegates(): void {
        $this->aggregator->expects($this->once())->method('generateSummaryWithComparison')
            ->with('user1', null, '2025-01-01', '2025-12-31', [], true);

        $this->service->generateSummaryWithComparison('user1', '2025-01-01', '2025-12-31');
    }

    // ===== getSpendingReport =====

    public function testGetSpendingReportByCategory(): void {
        $data = [['name' => 'Food', 'total' => 500.0, 'count' => 20]];
        $this->calculator->expects($this->once())->method('getSpendingByCategory')
            ->with('user1', null, '2025-01-01', '2025-12-31')
            ->willReturn($data);
        $this->calculator->method('calculateTotals')->willReturn(['amount' => 500.0, 'transactions' => 20]);

        $result = $this->service->getSpendingReport('user1', '2025-01-01', '2025-12-31');

        $this->assertEquals('category', $result['groupBy']);
        $this->assertCount(1, $result['data']);
        $this->assertEquals(500.0, $result['totals']['amount']);
    }

    public function testGetSpendingReportByMonth(): void {
        $this->calculator->expects($this->once())->method('getSpendingByMonth')
            ->willReturn([]);
        $this->calculator->method('calculateTotals')->willReturn(['amount' => 0, 'transactions' => 0]);

        $result = $this->service->getSpendingReport('user1', '2025-01-01', '2025-12-31', null, 'month');
        $this->assertEquals('month', $result['groupBy']);
    }

    public function testGetSpendingReportByVendor(): void {
        $this->calculator->expects($this->once())->method('getSpendingByVendor')->willReturn([]);
        $this->calculator->method('calculateTotals')->willReturn(['amount' => 0, 'transactions' => 0]);

        $this->service->getSpendingReport('user1', '2025-01-01', '2025-12-31', null, 'vendor');
    }

    public function testGetSpendingReportByTag(): void {
        $this->calculator->expects($this->once())->method('getSpendingByTag')
            ->with('user1', 5, '2025-01-01', '2025-12-31', null, null)
            ->willReturn([]);
        $this->calculator->method('calculateTotals')->willReturn(['amount' => 0, 'transactions' => 0]);

        $result = $this->service->getSpendingReport('user1', '2025-01-01', '2025-12-31', null, 'tag', 5);
        $this->assertEquals(5, $result['tagSetId']);
    }

    public function testGetSpendingReportByTagWithoutTagSetReturnsEmpty(): void {
        $this->calculator->method('calculateTotals')->willReturn(['amount' => 0, 'transactions' => 0]);

        $result = $this->service->getSpendingReport('user1', '2025-01-01', '2025-12-31', null, 'tag', null);
        $this->assertEmpty($result['data']);
    }

    // ===== getIncomeReport =====

    public function testGetIncomeReportByMonth(): void {
        $this->calculator->expects($this->once())->method('getIncomeByMonth')->willReturn([]);
        $this->calculator->method('calculateTotals')->willReturn(['amount' => 0, 'transactions' => 0]);

        $result = $this->service->getIncomeReport('user1', '2025-01-01', '2025-12-31');
        $this->assertEquals('month', $result['groupBy']);
    }

    public function testGetIncomeReportBySource(): void {
        $this->calculator->expects($this->once())->method('getIncomeBySource')->willReturn([]);
        $this->calculator->method('calculateTotals')->willReturn(['amount' => 0, 'transactions' => 0]);

        $this->service->getIncomeReport('user1', '2025-01-01', '2025-12-31', null, 'source');
    }

    // ===== getBudgetReport =====

    public function testGetBudgetReportDelegatesToAggregator(): void {
        $expected = ['categories' => []];
        $this->aggregator->expects($this->once())->method('getBudgetReport')
            ->with('user1', '2025-01-01', '2025-12-31')
            ->willReturn($expected);

        $result = $this->service->getBudgetReport('user1', '2025-01-01', '2025-12-31');
        $this->assertSame($expected, $result);
    }

    // ===== getCashFlowReport =====

    public function testGetCashFlowReportDelegates(): void {
        $this->aggregator->expects($this->once())->method('getCashFlowReport')
            ->with('user1', 3, '2025-01-01', '2025-12-31', [], true);

        $this->service->getCashFlowReport('user1', '2025-01-01', '2025-12-31', 3);
    }

    // ===== Tag report delegation =====

    public function testGetTagCombinationReportDelegates(): void {
        $this->tagReportService->expects($this->once())->method('getTagCombinationReport')
            ->with('user1', '2025-01-01', '2025-12-31', null, null, 2, 50);

        $this->service->getTagCombinationReport('user1', '2025-01-01', '2025-12-31');
    }

    public function testGetTagCrossTabDelegates(): void {
        $this->tagReportService->expects($this->once())->method('getCrossTabulation')
            ->with('user1', 1, 2, '2025-01-01', '2025-12-31', null, null);

        $this->service->getTagCrossTabulation('user1', 1, 2, '2025-01-01', '2025-12-31');
    }

    public function testGetTagTrendReportDelegates(): void {
        $this->tagReportService->expects($this->once())->method('getTagTrendReport')
            ->with('user1', [1, 2], '2025-01-01', '2025-12-31', null);

        $this->service->getTagTrendReport('user1', [1, 2], '2025-01-01', '2025-12-31');
    }

    public function testGetTagSetBreakdownDelegates(): void {
        $this->tagReportService->expects($this->once())->method('getTagSetBreakdown')
            ->with('user1', 5, '2025-01-01', '2025-12-31', null, null);

        $this->service->getTagSetBreakdown('user1', 5, '2025-01-01', '2025-12-31');
    }

    // ===== exportReport =====

    public function testExportReportGeneratesDataThenExports(): void {
        $summaryData = ['totals' => ['totalIncome' => 5000.0]];
        $this->aggregator->method('generateSummaryWithComparison')->willReturn($summaryData);

        $this->exporter->expects($this->once())->method('export')
            ->with($summaryData, 'summary', 'csv')
            ->willReturn(['stream' => 'csv data', 'contentType' => 'text/csv', 'filename' => 'summary_report.csv']);

        $result = $this->service->exportReport('user1', 'summary', 'csv', '2025-01-01', '2025-12-31');
        $this->assertEquals('text/csv', $result['contentType']);
    }

    public function testExportReportUnknownTypeThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->exportReport('user1', 'invalid', 'csv', '2025-01-01', '2025-12-31');
    }
}
