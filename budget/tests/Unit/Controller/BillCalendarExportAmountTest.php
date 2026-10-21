<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\BillController;
use OCA\Budget\Service\Bill\BillSuggestionService;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\UpcomingBillsService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Tests\Unit\Support\ReadsPdfText;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Bills Calendar export's Amount column and bill names (#399).
 *
 * The CSV printed the amount as a raw number ('1013.1', '630') beside month
 * columns written to two decimals. A row folding several one-time invoices
 * of one name printed the first invoice's amount, which was none of the
 * others'. And in the PDF a long bill name ran on into the Amount column.
 * The amount is now written as the months are, in the bill's currency's
 * decimals, left blank for a row whose invoices' amounts differ, and a long
 * name wraps inside its own column.
 */
class BillCalendarExportAmountTest extends TestCase {
	use ReadsPdfText;

	private BillService $service;
	private BillController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(BillService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $params = []) => vsprintf($text, $params));
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('canAccess')->willReturn(true);
		$shares->method('resolveOwner')->willReturn('user1');

		$this->controller = new BillController(
			$this->createMock(IRequest::class), $this->service, $this->createMock(ValidationService::class), $shares,
			$this->createMock(BillSuggestionService::class), $this->createMock(UpcomingBillsService::class),
			$l, 'user1', $this->createMock(LoggerInterface::class),
		);
	}

	private function months(array $values): array {
		return array_replace(array_fill(1, 12, false), $values);
	}

	private function oneTime(string $name, float $amount, int $month, array $extra = []): array {
		return $extra + [
			'id' => 1, 'name' => $name, 'amount' => $amount, 'currency' => 'CHF', 'frequency' => 'one-time',
			'isActive' => false,
			'occurrences' => $this->months([$month => true]),
			'paidMonths' => [$month],
			'paidAmounts' => [$month => $amount],
			'unrecordedMonths' => [],
			'expectedAmounts' => [$month => $amount],
			'owedAmounts' => [],
		];
	}

	private function export(string $format, array ...$rows): string {
		return $this->exportWithTotals($format, array_fill(1, 12, 0.0), ...$rows);
	}

	private function exportWithTotals(string $format, array $monthlyTotals, array ...$rows): string {
		$this->service->method('getAnnualOverview')->willReturn([
			'year' => 2026,
			'bills' => $rows,
			'monthlyTotals' => $monthlyTotals,
			'baseCurrency' => 'CHF',
		]);
		return $this->controller->exportCalendar($format, 2026)->render();
	}

	/**
	 * Monthly Totals converted from another currency carry more decimals
	 * than they print. The year was added up at two decimals by truncating
	 * each month, so twelve months printed as 100.01 came to 1,200.00. Each
	 * month is rounded as printed first, and the year is what the row shows.
	 */
	public function testTheYearAddsUpTheMonthsAsPrinted(): void {
		$csv = $this->exportWithTotals('csv', array_fill(1, 12, 100.006));

		$this->assertStringContainsString(
			'Total,,,' . implode(',', array_fill(0, 12, '100.01')) . ',"1,200.12"' . '
',
			$csv
		);
	}

	public function testThePdfYearAddsUpTheSame(): void {
		$this->requireTcpdf();

		$text = $this->pdfText($this->exportWithTotals('pdf', array_fill(1, 12, 100.006)));

		$this->assertStringContainsString('(1,200.12)', $text);
	}

	public function testTheAmountIsWrittenAsTheMonthsAre(): void {
		$csv = $this->export('csv',
			$this->oneTime('Dick Kuchen - Anzahlungsrechnung 711783', 630.0, 4),
			$this->oneTime('Ersatzakku Lenovo x1', 1013.1, 11),
		);

		$this->assertStringContainsString('"Dick Kuchen - Anzahlungsrechnung 711783",630.00,one-time,,,,630.00,', $csv);
		$this->assertStringContainsString('"Ersatzakku Lenovo x1","1,013.10",one-time,', $csv);
		$this->assertStringNotContainsString(',1013.1,', $csv);
		$this->assertStringNotContainsString(',630,', $csv);
	}

	public function testARowOfInvoicesWithDifferentAmountsLeavesTheAmountBlank(): void {
		$csv = $this->export('csv', $this->oneTime('Corner Card SA', 1013.1, 7, [
			'billIds' => [62, 63], 'mixedAmounts' => true,
			'occurrences' => $this->months([6 => true, 7 => true]),
			'paidMonths' => [6, 7],
			'paidAmounts' => [6 => 50.0, 7 => 1013.1],
			'expectedAmounts' => [6 => 50.0, 7 => 1013.1],
		]));

		$this->assertStringContainsString('"Corner Card SA",,one-time,,,,,,50.00,"1,013.10",,,,,,"1,063.10"' . "\n", $csv);
	}

	public function testARowOfInvoicesOfOneAmountShowsIt(): void {
		$csv = $this->export('csv', $this->oneTime('EKZ Ratenrechnung', 166.7, 4, [
			'billIds' => [51, 52],
			'occurrences' => $this->months([4 => true, 5 => true]),
			'paidMonths' => [4, 5],
			'paidAmounts' => [4 => 166.7, 5 => 166.7],
			'expectedAmounts' => [4 => 166.7, 5 => 166.7],
		]));

		$this->assertStringContainsString('"EKZ Ratenrechnung",166.70,one-time,,,,166.70,166.70,', $csv);
	}

	/** A currency without minor units is written without them, Amount and months alike. */
	public function testACurrencyWithoutDecimalsIsWrittenWithout(): void {
		$csv = $this->export('csv', $this->oneTime('Ryokan', 12000.0, 3, ['currency' => 'JPY']));

		$this->assertStringContainsString('Ryokan,"12,000",one-time,,,"12,000",,,,,,,,,,"12,000"' . "\n", $csv);
	}

	public function testThePdfAmountMatches(): void {
		$this->requireTcpdf();

		$text = $this->pdfText($this->export('pdf',
			$this->oneTime('Ersatzakku Lenovo x1', 1013.1, 11),
			$this->oneTime('Corner Card SA', 1013.1, 7, ['billIds' => [62, 63], 'mixedAmounts' => true, 'paidAmounts' => [7 => 777.77], 'expectedAmounts' => [7 => 777.77]]),
		));

		$this->assertStringContainsString('1,013.10', $text);
		// Ersatzakku's Amount, November and Annual; Corner Card's month
		// prints 777.77 and its Amount is blank, not the first invoice's 1,013.10
		$this->assertSame(3, substr_count($text, '(1,013.10)'));
		$this->assertStringContainsString('(777.77)', $text);
		$this->assertStringNotContainsString('1013.1)', $text);
	}

	/** The PDF writes each line of a cell as its own text run: a wrapped name is two runs, a short one is one. */
	public function testALongNameWrapsInsideItsColumn(): void {
		$this->requireTcpdf();

		$long = 'Dick Kuchen - Anzahlungsrechnung 711783 Restzahlung Montage';
		$text = $this->pdfText($this->export('pdf',
			$this->oneTime($long, 630.0, 4),
			$this->oneTime('Rent', 1200.0, 4),
		));

		$this->assertStringContainsString('(Rent)', $text);
		$this->assertStringNotContainsString('(' . $long . ')', $text, 'the long name is not written on one line');
		$this->assertStringContainsString('Dick Kuchen', $text);
		$this->assertStringContainsString('Montage)', $text, 'the end of the name is still printed');
		$this->assertStringContainsString('(630.00)', $text);
	}
}
