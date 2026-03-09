<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\BillController;
use OCA\Budget\Db\Bill;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BillControllerTest extends TestCase {
	private BillController $controller;
	private BillService $service;
	private ValidationService $validationService;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(BillService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new BillController(
			$this->request,
			$this->service,
			$this->validationService,
			'user1',
			$this->logger
		);
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsAllBills(): void {
		$bills = [['id' => 1, 'name' => 'Rent']];
		$this->service->method('findAll')->with('user1')->willReturn($bills);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testIndexReturnsActiveBillsOnly(): void {
		$bills = [['id' => 1, 'name' => 'Rent', 'active' => true]];
		$this->service->method('findActive')->with('user1')->willReturn($bills);

		$response = $this->controller->index(true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testIndexFiltersByTransferType(): void {
		$bills = [['id' => 1, 'isTransfer' => true]];
		$this->service->method('findByType')->with('user1', true, null)->willReturn($bills);

		$response = $this->controller->index(false, true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testIndexHandlesError(): void {
		$this->service->method('findAll')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsBill(): void {
		$bill = $this->createMock(Bill::class);
		$this->service->method('find')->with(1, 'user1')->willReturn($bill);

		$response = $this->controller->show(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testShowReturnsNotFound(): void {
		$this->service->method('find')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroyDeletesBill(): void {
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	public function testDestroyReturnsNotFound(): void {
		$this->service->method('delete')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── markPaid ────────────────────────────────────────────────────

	public function testMarkPaidReturnsBill(): void {
		$bill = $this->createMock(Bill::class);
		$this->service->method('markPaid')->willReturn($bill);

		$response = $this->controller->markPaid(1, '2026-03-01');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMarkPaidHandlesError(): void {
		$this->service->method('markPaid')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->markPaid(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── upcoming ────────────────────────────────────────────────────

	public function testUpcomingReturnsBills(): void {
		$bills = [['id' => 1, 'dueDate' => '2026-03-15']];
		$this->service->method('findUpcoming')->with('user1', 30)->willReturn($bills);

		$response = $this->controller->upcoming(30);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── dueThisMonth ────────────────────────────────────────────────

	public function testDueThisMonthReturnsBills(): void {
		$bills = [['id' => 1]];
		$this->service->method('findDueThisMonth')->willReturn($bills);

		$response = $this->controller->dueThisMonth();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── overdue ─────────────────────────────────────────────────────

	public function testOverdueReturnsBills(): void {
		$bills = [['id' => 1]];
		$this->service->method('findOverdue')->willReturn($bills);

		$response = $this->controller->overdue();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── summary ─────────────────────────────────────────────────────

	public function testSummaryReturnsData(): void {
		$summary = ['monthlyTotal' => 1500.00, 'billCount' => 5];
		$this->service->method('getMonthlySummary')->willReturn($summary);

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── statusForMonth ──────────────────────────────────────────────

	public function testStatusForMonthReturnsData(): void {
		$status = ['paid' => 3, 'unpaid' => 2];
		$this->service->method('getBillStatusForMonth')->willReturn($status);

		$response = $this->controller->statusForMonth('2026-03');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── detect ──────────────────────────────────────────────────────

	public function testDetectReturnsDetectedBills(): void {
		$detected = [['pattern' => 'Netflix', 'amount' => 15.99]];
		$this->service->method('detectRecurringBills')->with('user1', 6)->willReturn($detected);

		$response = $this->controller->detect(6);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── annualOverview ──────────────────────────────────────────────

	public function testAnnualOverviewReturnsData(): void {
		$overview = ['year' => 2026, 'bills' => [], 'monthlyTotals' => []];
		$this->service->method('getAnnualOverview')
			->with('user1', 2026, false, 'active')
			->willReturn($overview);

		$response = $this->controller->annualOverview(2026);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAnnualOverviewRejectsInvalidYear(): void {
		$response = $this->controller->annualOverview(1990);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid year', $response->getData()['error']);
	}

	public function testAnnualOverviewDefaultsToCurrentYear(): void {
		$currentYear = (int)date('Y');
		$this->service->method('getAnnualOverview')
			->with('user1', $currentYear, false, 'active')
			->willReturn(['year' => $currentYear]);

		$response = $this->controller->annualOverview();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}
}
