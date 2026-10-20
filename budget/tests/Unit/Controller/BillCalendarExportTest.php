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
 * The Bills Calendar's CSV and PDF printed the bill's amount in every month
 * it falls in, whatever was paid (#399): four Corner Card invoices folded
 * into one row printed 1,013.10 four times. Each month now prints what the
 * screen shows, what was paid or else what is still owed, and nothing for a
 * month moved past with no payment recorded; the year adds up what is printed.
 */
class BillCalendarExportTest extends TestCase {
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

	/** The reporter's four one-time Corner Card SA invoices, one row, each month its own payment. */
	private function cornerCard(): array {
		return [
			'id' => 62, 'billIds' => [62, 63, 73, 81], 'name' => 'Corner Card SA', 'amount' => 1013.1, 'currency' => 'CHF',
			'frequency' => 'one-time', 'isActive' => false,
			'occurrences' => $this->months([5 => true, 6 => true, 7 => true, 8 => true]),
			'paidMonths' => [5, 6, 7, 8],
			'paidAmounts' => [5 => 0.2, 6 => 50.0, 7 => 1013.1, 8 => 500.0],
			'unrecordedMonths' => [],
			'expectedAmounts' => [5 => 0.2, 6 => 50.0, 7 => 1013.1, 8 => 500.0],
			'owedAmounts' => [],
		];
	}

	/** CSX Pro Credit Cards: April and May moved past unpaid, June to October paid, the rest still owed. */
	private function csx(): array {
		return [
			'id' => 47, 'name' => 'CSX Pro Credit Cards', 'amount' => 100.0, 'currency' => 'CHF', 'frequency' => 'monthly',
			'isActive' => true,
			'occurrences' => $this->months(array_fill(4, 9, true)),
			'paidMonths' => [6, 7, 8, 9, 10],
			'paidAmounts' => [6 => 50.0, 7 => 50.0, 8 => 100.0, 9 => 100.0, 10 => 100.0],
			'unrecordedMonths' => [4, 5],
			'expectedAmounts' => array_fill(4, 9, 100.0),
			'owedAmounts' => [11 => 100.0, 12 => 100.0],
		];
	}

	private function export(string $format, array ...$rows): string {
		$this->service->method('getAnnualOverview')->willReturn([
			'year' => 2026,
			'bills' => $rows,
			'monthlyTotals' => array_fill(1, 12, 0.0),
		]);
		return $this->controller->exportCalendar($format, 2026)->render();
	}

	public function testEachMonthPrintsWhatWasPaidAndTheYearAddsItUp(): void {
		$csv = $this->export('csv', $this->cornerCard());

		$this->assertStringContainsString(
			'"Corner Card SA","1,013.10",one-time,,,,,0.20,50.00,"1,013.10",500.00,,,,,"1,563.30"' . "\n",
			$csv
		);
	}

	/** Paid where paid, owed where still owed, blank where the bill moved on with no payment recorded. */
	public function testAMonthWithNoPaymentRecordedPrintsNothing(): void {
		$csv = $this->export('csv', $this->csx());

		$this->assertStringContainsString(
			'"CSX Pro Credit Cards",100.00,monthly,,,,,,50.00,50.00,100.00,100.00,100.00,100.00,100.00,600.00' . "\n",
			$csv
		);
	}

	/** A row that says nothing about payments still prints its amount in each month it falls in. */
	public function testARowWithoutPaymentsPrintsItsAmount(): void {
		$csv = $this->export('csv', [
			'name' => 'Rent', 'amount' => 1200, 'frequency' => 'monthly', 'occurrences' => array_fill(1, 12, true),
		]);

		$this->assertStringContainsString(
			'Rent,"1,200.00",monthly,' . implode(',', array_fill(0, 12, '"1,200.00"')) . ',"14,400.00"' . "\n",
			$csv
		);
	}

	public function testThePdfPrintsTheSameValues(): void {
		$this->requireTcpdf();

		$text = $this->pdfText($this->export('pdf', $this->cornerCard(), $this->csx()));

		$this->assertStringContainsString('1,563.30', $text);
		$this->assertStringContainsString('600.00', $text);
		$this->assertStringContainsString('0.20', $text);
		// What every month printed before: the row amount, added up
		$this->assertStringNotContainsString('4,052.40', $text);
		$this->assertStringNotContainsString('900.00', $text);
	}
}
