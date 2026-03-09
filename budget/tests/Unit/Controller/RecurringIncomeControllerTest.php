<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\RecurringIncomeController;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RecurringIncomeControllerTest extends TestCase {
	private RecurringIncomeController $controller;
	private RecurringIncomeService $service;
	private ValidationService $validationService;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(RecurringIncomeService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Salary']);
		$this->validationService->method('validateFrequency')
			->willReturn(['valid' => true, 'formatted' => 'monthly']);
		$this->validationService->method('validatePattern')
			->willReturn(['valid' => true, 'sanitized' => 'SALARY']);

		$this->controller = new RecurringIncomeController(
			$this->request,
			$this->service,
			$this->validationService,
			'user1',
			$this->logger
		);
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsAllIncome(): void {
		$incomes = [['id' => 1, 'name' => 'Salary']];
		$this->service->method('findAll')->with('user1')->willReturn($incomes);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testIndexReturnsActiveOnly(): void {
		$incomes = [['id' => 1]];
		$this->service->method('findActive')->with('user1')->willReturn($incomes);

		$response = $this->controller->index(true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testIndexHandlesError(): void {
		$this->service->method('findAll')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsIncome(): void {
		$income = $this->createMock(RecurringIncome::class);
		$this->service->method('find')->with(1, 'user1')->willReturn($income);

		$response = $this->controller->show(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testShowReturnsNotFound(): void {
		$this->service->method('find')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateReturnsCreated(): void {
		$income = $this->createMock(RecurringIncome::class);
		$this->service->method('create')->willReturn($income);

		$response = $this->controller->create('Salary', 3000.00);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreatePassesAllArguments(): void {
		$income = $this->createMock(RecurringIncome::class);
		$this->service->expects($this->once())
			->method('create')
			->with('user1', 'Salary', 3000.00, 'monthly', 15, 6, 5, 2, 'Salary', 'SALARY', 'bonus notes')
			->willReturn($income);

		$response = $this->controller->create(
			'Salary', 3000.00, 'monthly', 15, 6, 5, 2, 'Employer', 'SALARY', 'bonus notes'
		);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateRejectsInvalidExpectedDay(): void {
		$response = $this->controller->create('Salary', 3000.00, 'monthly', 32);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Expected day', $response->getData()['error']);
	}

	public function testCreateRejectsZeroExpectedDay(): void {
		$response = $this->controller->create('Salary', 3000.00, 'monthly', 0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Expected day', $response->getData()['error']);
	}

	public function testCreateRejectsInvalidExpectedMonth(): void {
		$response = $this->controller->create('Salary', 3000.00, 'monthly', null, 13);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Expected month', $response->getData()['error']);
	}

	public function testCreateRejectsZeroExpectedMonth(): void {
		$response = $this->controller->create('Salary', 3000.00, 'monthly', null, 0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Expected month', $response->getData()['error']);
	}

	public function testCreateRejectsInvalidName(): void {
		// Override the default mock for this test
		$validationService = $this->createMock(ValidationService::class);
		$validationService->method('validateName')
			->willReturn(['valid' => false, 'error' => 'Name is required']);
		$validationService->method('validateFrequency')
			->willReturn(['valid' => true, 'formatted' => 'monthly']);

		$controller = new RecurringIncomeController(
			$this->request,
			$this->service,
			$validationService,
			'user1',
			$this->logger
		);

		$response = $controller->create('', 3000.00);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateRejectsInvalidFrequency(): void {
		$validationService = $this->createMock(ValidationService::class);
		$validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Salary']);
		$validationService->method('validateFrequency')
			->willReturn(['valid' => false, 'error' => 'Invalid frequency']);

		$controller = new RecurringIncomeController(
			$this->request,
			$this->service,
			$validationService,
			'user1',
			$this->logger
		);

		$response = $controller->create('Salary', 3000.00, 'invalid');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateHandlesServiceError(): void {
		$this->service->method('create')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->create('Salary', 3000.00);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroyDeletesIncome(): void {
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Recurring income deleted', $response->getData()['message']);
	}

	public function testDestroyReturnsNotFound(): void {
		$this->service->method('delete')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── upcoming ────────────────────────────────────────────────────

	public function testUpcomingReturnsIncomes(): void {
		$incomes = [['id' => 1]];
		$this->service->method('findUpcoming')->with('user1', 30)->willReturn($incomes);

		$response = $this->controller->upcoming(30);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpcomingDefaultsDays(): void {
		$this->service->expects($this->once())
			->method('findUpcoming')
			->with('user1', 30)
			->willReturn([]);

		$response = $this->controller->upcoming();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpcomingHandlesError(): void {
		$this->service->method('findUpcoming')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->upcoming();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── expectedThisMonth ───────────────────────────────────────────

	public function testExpectedThisMonthReturnsData(): void {
		$incomes = [['id' => 1]];
		$this->service->method('findExpectedThisMonth')->willReturn($incomes);

		$response = $this->controller->expectedThisMonth();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testExpectedThisMonthHandlesError(): void {
		$this->service->method('findExpectedThisMonth')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->expectedThisMonth();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── summary ─────────────────────────────────────────────────────

	public function testSummaryReturnsData(): void {
		$summary = ['monthlyTotal' => 5000.00];
		$this->service->method('getMonthlySummary')->willReturn($summary);

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSummaryHandlesError(): void {
		$this->service->method('getMonthlySummary')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── markReceived ────────────────────────────────────────────────

	public function testMarkReceivedReturnsIncome(): void {
		$income = $this->createMock(RecurringIncome::class);
		$this->service->method('markReceived')->willReturn($income);

		$response = $this->controller->markReceived(1, '2026-03-01');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMarkReceivedWithoutDate(): void {
		$income = $this->createMock(RecurringIncome::class);
		$this->service->expects($this->once())
			->method('markReceived')
			->with(1, 'user1', null)
			->willReturn($income);

		$response = $this->controller->markReceived(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMarkReceivedHandlesError(): void {
		$this->service->method('markReceived')
			->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->markReceived(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── detect ──────────────────────────────────────────────────────

	public function testDetectReturnsDetectedIncome(): void {
		$detected = [['pattern' => 'SALARY', 'amount' => 3000.00]];
		$this->service->method('detectRecurringIncome')->willReturn($detected);

		$response = $this->controller->detect(24);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDetectPassesDebugFlag(): void {
		$this->service->expects($this->once())
			->method('detectRecurringIncome')
			->with('user1', 12, true)
			->willReturn([]);

		$response = $this->controller->detect(12, true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDetectHandlesError(): void {
		$this->service->method('detectRecurringIncome')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->detect();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
