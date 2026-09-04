<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Report;

use OCA\Budget\Service\Report\ReportExporter;
use OCA\Budget\Tests\Unit\Support\ReadsPdfText;
use OCP\IL10N;
use OCP\L10N\IFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ReportExporterTest extends TestCase {
    use ReadsPdfText;

    private ReportExporter $exporter;

    protected function setUp(): void {
        $this->exporter = $this->exporterTranslating([]);
    }

    /**
     * A translator that knows $dictionary and passes every other string
     * through vsprintf — so a lone '%' in a label (the #305 crash) throws
     * here as it would in production.
     *
     * @param array<string, string> $dictionary
     */
    private function translator(array $dictionary): IL10N {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(
            fn(string $text, array $params = []) => vsprintf($dictionary[$text] ?? $text, $params)
        );
        return $l;
    }

    /**
     * @param array<string, string> $dictionary
     */
    private function exporterTranslating(array $dictionary): ReportExporter {
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($this->translator($dictionary));
        return new ReportExporter($factory);
    }

    // ===== fixtures =====

    private function summaryData(): array {
        return [
            'period' => ['startDate' => '2026-01-01', 'endDate' => '2026-01-31'],
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
    }

    private function spendingData(): array {
        return [
            'period' => ['startDate' => '2026-01-01', 'endDate' => '2026-01-31'],
            'data' => [
                ['name' => 'Food', 'total' => 500.0, 'count' => 20],
                ['name' => 'Transport', 'total' => 300.0, 'count' => 10],
            ],
            'totals' => ['amount' => 800.0, 'transactions' => 30],
        ];
    }

    private function cashFlowData(): array {
        return [
            'period' => ['startDate' => '2025-01-01', 'endDate' => '2025-02-28'],
            'data' => [
                ['month' => '2025-01', 'income' => 5000.0, 'expenses' => 3000.0, 'net' => 2000.0],
                ['month' => '2025-02', 'income' => 5000.0, 'expenses' => 3500.0, 'net' => 1500.0],
            ],
            'averageMonthly' => ['income' => 5000.0, 'expenses' => 3250.0, 'net' => 1750.0],
        ];
    }

    private function incomeData(): array {
        return [
            'data' => [
                ['name' => 'Salary', 'total' => 5000.0, 'count' => 1],
            ],
            'totals' => ['amount' => 5000.0, 'transactions' => 1],
        ];
    }

    private function budgetData(string $status = 'good'): array {
        return [
            'categories' => [
                ['categoryName' => 'Food', 'budgeted' => 500.0, 'spent' => 400.0, 'remaining' => 100.0, 'percentage' => 80.0, 'status' => $status],
            ],
            'totals' => ['budgeted' => 500.0, 'spent' => 400.0, 'remaining' => 100.0],
            'overallStatus' => $status,
        ];
    }

    private function categoryMonthlyData(): array {
        return [
            'period' => ['startDate' => '2026-01-01', 'endDate' => '2026-02-28', 'months' => ['2026-01', '2026-02']],
            'rows' => [
                ['name' => 'Housing', 'depth' => 0, 'isParent' => true, 'monthly' => ['2026-01' => -900.0, '2026-02' => -900.0], 'total' => -1800.0],
                ['name' => 'Rent', 'depth' => 1, 'isParent' => false, 'monthly' => ['2026-01' => -900.0, '2026-02' => -900.0], 'total' => -1800.0],
            ],
            'totals' => ['monthly' => ['2026-01' => -900.0, '2026-02' => -900.0], 'total' => -1800.0],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function incomeExpenseData(): array {
        return [
            'period' => ['startDate' => '2026-01-01', 'endDate' => '2026-12-31'],
            'income' => [
                'data' => [
                    ['name' => 'Membership', 'total' => 4200.0, 'count' => 84],
                    ['name' => 'Fundraising', 'total' => 800.0, 'count' => 3],
                ],
                'totals' => ['amount' => 5000.0, 'transactions' => 87],
            ],
            'expenses' => [
                'data' => [
                    ['name' => 'Pitch hire', 'total' => 2400.0, 'count' => 24],
                ],
                'totals' => ['amount' => 3000.0, 'transactions' => 40],
            ],
            'totals' => ['income' => 5000.0, 'expenses' => 3000.0, 'net' => 2000.0],
        ];
    }

    private function dataFor(string $type): array {
        return match ($type) {
            'summary' => $this->summaryData(),
            'spending' => $this->spendingData(),
            'cashflow' => $this->cashFlowData(),
            'income' => $this->incomeData(),
            'budget' => $this->budgetData('over'),
            'category-monthly' => $this->categoryMonthlyData(),
            'income-expense' => $this->incomeExpenseData(),
        };
    }

    // ===== CSV export =====

    public function testExportSummaryCsv(): void {
        $result = $this->exporter->export($this->summaryData(), 'summary', 'csv');

        $this->assertEquals('text/csv', $result['contentType']);
        $this->assertStringContainsString('.csv', $result['filename']);
        $this->assertStringContainsString('summary', $result['filename']);
        $this->assertStringContainsString('Total Income', $result['stream']);
        $this->assertStringContainsString('5000', $result['stream']);
        $this->assertStringContainsString('Checking', $result['stream']);
    }

    public function testExportSpendingCsv(): void {
        $result = $this->exporter->export($this->spendingData(), 'spending', 'csv');

        $this->assertStringContainsString('Food', $result['stream']);
        $this->assertStringContainsString('Transport', $result['stream']);
        $this->assertStringContainsString('spending', $result['filename']);
    }

    public function testExportCashFlowCsv(): void {
        $result = $this->exporter->export($this->cashFlowData(), 'cashflow', 'csv');

        $this->assertStringContainsString('Income', $result['stream']);
        $this->assertStringContainsString('Cumulative', $result['stream']);
    }

    public function testExportIncomeCsv(): void {
        $result = $this->exporter->export($this->incomeData(), 'income', 'csv');
        $this->assertStringContainsString('Salary', $result['stream']);
    }

    public function testExportBudgetCsv(): void {
        $result = $this->exporter->export($this->budgetData(), 'budget', 'csv');
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

    // ===== income & expenses CSV (#344) =====

    public function testIncomeExpenseCsvHasBothTablesWithTheirOwnTotals(): void {
        $result = $this->exporter->export($this->incomeExpenseData(), 'income-expense', 'csv');
        $csv = $result['stream'];

        $this->assertStringContainsString("Income\n", $csv);
        $this->assertStringContainsString('Membership,4200,84', $csv);
        $this->assertStringContainsString('"Total Income",5000,87', $csv);
        $this->assertStringContainsString("Expenses\n", $csv);
        $this->assertStringContainsString('"Pitch hire",2400,24', $csv);
        $this->assertStringContainsString('"Total Expenses",3000,40', $csv);
    }

    public function testIncomeExpenseCsvEndsWithTheNet(): void {
        $csv = $this->exporter->export($this->incomeExpenseData(), 'income-expense', 'csv')['stream'];

        $this->assertStringContainsString('Net,2000', $csv);
        $this->assertStringContainsString('Period,2026-01-01,2026-12-31', $csv);
    }

    public function testIncomeExpenseCsvSurvivesAnEmptyPeriod(): void {
        $csv = $this->exporter->export([], 'income-expense', 'csv')['stream'];

        $this->assertStringContainsString('Income', $csv);
        $this->assertStringContainsString('Expenses', $csv);
        $this->assertStringContainsString('Net,0', $csv);
    }

    // ===== PDF export (without TCPDF) =====

    public function testExportPdfFallsBackToJsonWhenTcpdfMissing(): void {
        if (class_exists('TCPDF')) {
            $this->markTestSkipped('TCPDF is loaded, cannot test fallback');
        }

        $data = ['totals' => ['totalIncome' => 3000.0]];
        $result = $this->exporter->export($data, 'summary', 'pdf');

        // Falls back to JSON
        $this->assertEquals('application/json', $result['contentType']);
        $this->assertStringContainsString('.json', $result['filename']);
    }

    // ===== translation (#377) =====

    public function testSummaryCsvIsWrittenInTheUsersLanguage(): void {
        $exporter = $this->exporterTranslating([
            'Total Income' => 'Gesamteinnahmen',
            'Account' => 'Konto',
        ]);

        $csv = $exporter->export($this->summaryData(), 'summary', 'csv')['stream'];

        $this->assertStringContainsString('Gesamteinnahmen', $csv);
        $this->assertStringContainsString('Konto', $csv);
        $this->assertStringNotContainsString('Total Income', $csv);
    }

    public function testSummaryPdfIsWrittenInTheUsersLanguage(): void {
        $this->requireTcpdf();
        $exporter = $this->exporterTranslating([
            'Summary Report' => 'Zusammenfassung',
            'Financial Summary' => 'Finanzuebersicht',
            'Total Income' => 'Gesamteinnahmen',
            'Period: %1$s to %2$s' => 'Zeitraum: %1$s bis %2$s',
        ]);

        $result = $exporter->export($this->summaryData(), 'summary', 'pdf');

        $this->assertEquals('application/pdf', $result['contentType']);
        $text = $this->pdfText($result['stream']);
        $this->assertStringContainsString('Zusammenfassung', $text);
        $this->assertStringContainsString('Finanzuebersicht', $text);
        $this->assertStringContainsString('Gesamteinnahmen', $text);
        $this->assertStringContainsString('Zeitraum: 2026-01-01 bis 2026-01-31', $text);
        $this->assertStringNotContainsString('Total Income', $text);
    }

    /**
     * A web request's translator is the session user's; a background job has
     * no session and names the language it wants instead.
     */
    public function testTheRequestedLanguageDecidesTheOutput(): void {
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturnCallback(
            fn(string $app, ?string $lang = null) => $lang === 'de'
                ? $this->translator(['Total Income' => 'Gesamteinnahmen'])
                : $this->translator([])
        );
        $exporter = new ReportExporter($factory);

        $german = $exporter->export($this->summaryData(), 'summary', 'csv', 'de')['stream'];
        $default = $exporter->export($this->summaryData(), 'summary', 'csv')['stream'];

        $this->assertStringContainsString('Gesamteinnahmen', $german);
        $this->assertStringContainsString('Total Income', $default);
    }

    public function testComparisonRowsAreLabelledInTheUsersLanguage(): void {
        $exporter = $this->exporterTranslating([
            'Net Income' => 'Nettoeinkommen',
            'vs Previous Period' => 'Vorperiode',
        ]);
        $data = $this->summaryData();
        $data['comparison'] = ['changes' => [
            'income' => ['percentage' => 5.0, 'direction' => 'up'],
            'expenses' => ['percentage' => 2.5, 'direction' => 'down'],
            'netIncome' => ['percentage' => 12.0, 'direction' => 'up'],
        ]];

        $csv = $exporter->export($data, 'summary', 'csv')['stream'];

        $this->assertStringContainsString('Vorperiode', $csv);
        $this->assertStringContainsString('Nettoeinkommen,+12%', $csv);
        $this->assertStringContainsString('Expenses,-2.5%', $csv);
        $this->assertStringNotContainsString('NetIncome', $csv);
    }

    public function testBudgetStatusIsALabelNotACode(): void {
        $exporter = $this->exporterTranslating(['Over budget' => 'Budget ueberschritten']);

        $csv = $exporter->export($this->budgetData('over'), 'budget', 'csv')['stream'];

        $this->assertStringContainsString('Budget ueberschritten', $csv);
        $this->assertStringNotContainsString(',over', $csv);
    }

    /**
     * The aggregator labels month-grouped rows in English ("Jul 2026"); the
     * export re-labels them from the row's month key.
     */
    public function testMonthGroupedRowsAreLabelledInTheUsersLanguage(): void {
        $exporter = $this->exporterTranslating(['Jul' => 'Juli']);
        $data = [
            'data' => [
                ['name' => 'Jul 2026', 'month' => '2026-07', 'total' => 3995.0, 'count' => 2],
            ],
            'totals' => ['amount' => 3995.0, 'transactions' => 2],
        ];

        $csv = $exporter->export($data, 'income', 'csv')['stream'];

        $this->assertStringContainsString('"Juli 2026",3995,2', $csv);
        $this->assertStringNotContainsString('Jul 2026', $csv);
    }

    public function testIncomePdfMonthRowsAreLabelledInTheUsersLanguage(): void {
        $this->requireTcpdf();
        $exporter = $this->exporterTranslating(['Jul' => 'Juli']);
        $data = [
            'data' => [
                ['name' => 'Jul 2026', 'month' => '2026-07', 'total' => 3995.0, 'count' => 2],
            ],
            'totals' => ['amount' => 3995.0, 'transactions' => 2],
        ];

        $text = $this->pdfText($exporter->export($data, 'income', 'pdf')['stream']);

        $this->assertStringContainsString('Juli 2026', $text);
        $this->assertStringNotContainsString('Jul 2026', $text);
    }

    /**
     * A row whose name is only a placeholder for "no vendor" (the mapper flags
     * it) is labelled by the translator, not by the English placeholder.
     */
    public function testPlaceholderNamesAreTranslated(): void {
        $exporter = $this->exporterTranslating(['Unknown' => 'Unbekannt']);
        $data = $this->incomeExpenseData();
        $data['income']['data'][] = ['name' => 'Unknown Source', 'unknown' => true, 'total' => 50.0, 'count' => 1];

        $csv = $exporter->export($data, 'income-expense', 'csv')['stream'];

        $this->assertStringContainsString('Unbekannt,50,1', $csv);
        $this->assertStringNotContainsString('Unknown Source', $csv);
    }

    public function testPlaceholderNamesAreTranslatedInThePdf(): void {
        $this->requireTcpdf();
        $exporter = $this->exporterTranslating(['Unknown' => 'Unbekannt']);
        $data = $this->incomeExpenseData();
        $data['income']['data'][] = ['name' => 'Unknown Source', 'unknown' => true, 'total' => 50.0, 'count' => 1];

        $text = $this->pdfText($exporter->export($data, 'income-expense', 'pdf')['stream']);

        $this->assertStringContainsString('Unbekannt', $text);
        $this->assertStringNotContainsString('Unknown Source', $text);
    }

    public function testMonthColumnsUseTranslatedMonthNames(): void {
        $exporter = $this->exporterTranslating(['Jan' => 'Jaen']);

        $csv = $exporter->export($this->categoryMonthlyData(), 'category-monthly', 'csv')['stream'];

        $this->assertStringContainsString('Jaen 2026', $csv);
        $this->assertStringContainsString('Feb 2026', $csv);
    }

    public function testCashFlowPdfMonthColumnUsesTranslatedMonthNames(): void {
        $this->requireTcpdf();
        $exporter = $this->exporterTranslating(['Jan' => 'Jaen']);

        $text = $this->pdfText($exporter->export($this->cashFlowData(), 'cashflow', 'pdf')['stream']);

        $this->assertStringContainsString('Jaen 2025', $text);
        $this->assertStringContainsString('Feb 2025', $text);
    }

    /**
     * Guard for the #305 class: every label now passes through the
     * translator, whose vsprintf treats a lone '%' as a format specifier.
     * Each report in each format must come out the other side.
     */
    #[DataProvider('reportTypesAndFormats')]
    public function testEveryReportSurvivesTheTranslator(string $type, string $format, string $contentType): void {
        if ($format === 'pdf') {
            $this->requireTcpdf();
        }

        $result = $this->exporter->export($this->dataFor($type), $type, $format);

        $this->assertEquals($contentType, $result['contentType']);
        $this->assertNotSame('', $result['stream']);
    }

    public static function reportTypesAndFormats(): array {
        $cases = [];
        foreach (['summary', 'spending', 'cashflow', 'income', 'budget', 'category-monthly', 'income-expense'] as $type) {
            $cases["$type csv"] = [$type, 'csv', 'text/csv'];
            $cases["$type pdf"] = [$type, 'pdf', 'application/pdf'];
        }
        return $cases;
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
