<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\BillController;
use OCA\Budget\Service\Bill\BillSuggestionService;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\UpcomingBillsService;
use OCA\Budget\Service\ValidationService;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A date of the year paid by a payment of the other year (5 January 2027
 * paid on 28 December) is counted in the year the money went out (#399).
 * The export prints nothing for its month, as the screen's totals add
 * nothing for it, and the year adds up what is printed.
 */
class BillCalendarPaidElsewhereExportTest extends TestCase {
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

	public function testAMonthPaidInTheOtherYearPrintsNothing(): void {
		$this->service->method('getAnnualOverview')->willReturn([
			'year' => 2027,
			'bills' => [[
				'id' => 8, 'name' => 'Garage Miete', 'amount' => 150.0, 'currency' => 'CHF', 'frequency' => 'monthly',
				'isActive' => true,
				'occurrences' => array_fill(1, 12, true),
				'paidMonths' => [],
				'paidAmounts' => [],
				'paidElsewhere' => [1 => [['date' => '2026-12-28', 'amount' => 150.0, 'due' => '2027-01-05']]],
				'unrecordedMonths' => [],
				'expectedAmounts' => array_fill(1, 12, 150.0),
				'owedAmounts' => array_fill(2, 11, 150.0),
			]],
			'monthlyTotals' => array_replace(array_fill(1, 12, 150.0), [1 => 0.0]),
		]);

		$csv = $this->controller->exportCalendar('csv', 2027)->render();

		$this->assertStringContainsString(
			'"Garage Miete",150.00,monthly,,' . implode(',', array_fill(0, 11, '150.00')) . ',"1,650.00"' . "\n",
			$csv
		);
	}
}
