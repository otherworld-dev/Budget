<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Report;

use OCA\Budget\Service\Report\ReportCalculator;
use OCA\Budget\Service\Report\ReportExporter;
use PHPUnit\Framework\TestCase;

class ReportExporterTest extends TestCase {
    private ReportExporter $exporter;
    private ReportCalculator $calculator;

    protected function setUp(): void {
        $this->calculator = $this->createMock(ReportCalculator::class);
        $this->exporter = new ReportExporter($this->calculator);
    }

    // ===== CSV export =====

    public function testExportSummaryCsv(): void {
        $data = [
            'totals' => [
                'totalIncome' => 5000.0,
                'totalExpenses' => 3000.0,
                'netIncome' => 2000.0,
                'currentBalance' => 10000.0,
            ],
            'accounts' => [
                ['name' => 'Checking', 'balance' => 5000.0, 'income' => 3000.0, 'expenses' => 2000.0, 'net' => 1000.0],
            ],
        ];

        $result = $this->exporter->export($data, 'summary', 'csv');

        $this->assertEquals('text/csv', $result['contentType']);
        $this->assertStringContainsString('.csv', $result['filename']);
        $this->assertStringContainsString('summary', $result['filename']);
        $this->assertStringContainsString('Total Income', $result['stream']);
        $this->assertStringContainsString('5000', $result['stream']);
        $this->assertStringContainsString('Checking', $result['stream']);
    }

    public function testExportSpendingCsv(): void {
        $data = [
            'data' => [
                ['name' => 'Food', 'total' => 500.0, 'count' => 20],
                ['name' => 'Transport', 'total' => 300.0, 'count' => 10],
            ],
            'totals' => ['amount' => 800.0, 'transactions' => 30],
        ];

        $result = $this->exporter->export($data, 'spending', 'csv');

        $this->assertStringContainsString('Food', $result['stream']);
        $this->assertStringContainsString('Transport', $result['stream']);
        $this->assertStringContainsString('spending', $result['filename']);
    }

    public function testExportCashFlowCsv(): void {
        $data = [
            'data' => [
                ['month' => '2025-01', 'income' => 5000.0, 'expenses' => 3000.0, 'net' => 2000.0],
            ],
            'averageMonthly' => ['income' => 5000.0, 'expenses' => 3000.0, 'net' => 2000.0],
        ];

        $result = $this->exporter->export($data, 'cashflow', 'csv');

        $this->assertStringContainsString('Income', $result['stream']);
        $this->assertStringContainsString('Cumulative', $result['stream']);
    }

    public function testExportIncomeCsv(): void {
        $data = [
            'data' => [
                ['name' => 'Salary', 'total' => 5000.0, 'count' => 1],
            ],
            'totals' => ['amount' => 5000.0, 'transactions' => 1],
        ];

        $result = $this->exporter->export($data, 'income', 'csv');
        $this->assertStringContainsString('Salary', $result['stream']);
    }

    public function testExportBudgetCsv(): void {
        $data = [
            'categories' => [
                ['categoryName' => 'Food', 'budgeted' => 500.0, 'spent' => 400.0, 'remaining' => 100.0, 'percentage' => 80.0, 'status' => 'ok'],
            ],
            'totals' => ['budgeted' => 500.0, 'spent' => 400.0, 'remaining' => 100.0],
        ];

        $result = $this->exporter->export($data, 'budget', 'csv');
        $this->assertStringContainsString('Food', $result['stream']);
        $this->assertStringContainsString('Status', $result['stream']);
    }

    // ===== JSON export =====

    public function testExportJson(): void {
        $data = ['totals' => ['totalIncome' => 5000.0]];

        $result = $this->exporter->export($data, 'summary', 'json');

        $this->assertEquals('application/json', $result['contentType']);
        $this->assertStringContainsString('.json', $result['filename']);
        $decoded = json_decode($result['stream'], true);
        $this->assertEquals(5000.0, $decoded['totals']['totalIncome']);
    }

    // ===== PDF export (without TCPDF) =====

    public function testExportPdfFallsBackToJsonWhenTcpdfMissing(): void {
        // TCPDF is unlikely to be loaded in test env
        if (class_exists('TCPDF')) {
            $this->markTestSkipped('TCPDF is loaded, cannot test fallback');
        }

        $data = ['totals' => ['totalIncome' => 3000.0]];
        $result = $this->exporter->export($data, 'summary', 'pdf');

        // Falls back to JSON
        $this->assertEquals('application/json', $result['contentType']);
        $this->assertStringContainsString('.json', $result['filename']);
    }

    // ===== Unknown format =====

    public function testExportUnknownFormatThrows(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->exporter->export([], 'summary', 'xml');
    }

    // ===== filename format =====

    public function testExportFilenameContainsDate(): void {
        $result = $this->exporter->export([], 'spending', 'json');
        $this->assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2}/', $result['filename']);
    }
}
