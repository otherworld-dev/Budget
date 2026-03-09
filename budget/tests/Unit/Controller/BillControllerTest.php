<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\BillController;
use OCA\Budget\Db\Bill;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BillControllerTest extends TestCase {
	private BillController $controller;
	private BillService $service;
	private ValidationService $validationService;
	private IRequest $request;
	private LoggerInterface $logger;
	private bool $streamOverridden = false;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(BillService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Default validation mocks (pass-through)
		$this->validationService->method('validateName')
			->willReturn(['valid' => true, 'sanitized' => 'Test Bill']);
		$this->validationService->method('validateFrequency')
			->willReturnCallback(function ($freq) {
				return ['valid' => true, 'formatted' => $freq];
			});
		$this->validationService->method('validatePattern')
			->willReturn(['valid' => true, 'sanitized' => 'PATTERN']);
		$this->validationService->method('validateNotes')
			->willReturn(['valid' => true, 'sanitized' => 'some notes']);
		$this->validationService->method('validateDate')
			->willReturn(['valid' => true]);

		$this->controller = new BillController(
			$this->request,
			$this->service,
			$this->validationService,
			'user1',
			$this->logger
		);
	}

	protected function tearDown(): void {
		if ($this->streamOverridden) {
			stream_wrapper_restore('php');
			$this->streamOverridden = false;
		}
	}

	private function mockInput(string $json): void {
		MockPhpInputStream::$data = $json;
		stream_wrapper_unregister('php');
		stream_wrapper_register('php', MockPhpInputStream::class);
		$this->streamOverridden = true;
	}

	private function controllerWithValidation(ValidationService $vs): BillController {
		return new BillController(
			$this->request,
			$this->service,
			$vs,
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

	public function testIndexActiveTransfersOnly(): void {
		$this->service->expects($this->once())
			->method('findByType')
			->with('user1', true, true)
			->willReturn([]);

		$response = $this->controller->index(true, true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testIndexNonTransferBillsOnly(): void {
		$this->service->expects($this->once())
			->method('findByType')
			->with('user1', false, null)
			->willReturn([]);

		$response = $this->controller->index(false, false);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testIndexStringActiveOnly(): void {
		$this->service->method('findActive')->with('user1')->willReturn([]);

		$response = $this->controller->index('true');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
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

	// ── create ──────────────────────────────────────────────────────

	public function testCreateSuccess(): void {
		$this->mockInput(json_encode([
			'name' => 'Netflix',
			'amount' => 15.99,
		]));

		$bill = $this->createMock(Bill::class);
		$this->service->method('create')->willReturn($bill);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid request data', $response->getData()['error']);
	}

	public function testCreateMissingName(): void {
		$this->mockInput(json_encode(['amount' => 10.00]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', $response->getData()['error']);
	}

	public function testCreateMissingAmount(): void {
		$this->mockInput(json_encode(['name' => 'Netflix']));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', $response->getData()['error']);
	}

	public function testCreateAutoPayWithoutAccount(): void {
		$this->mockInput(json_encode([
			'name' => 'Netflix',
			'amount' => 15.99,
			'autoPayEnabled' => true,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Auto-pay requires an account', $response->getData()['error']);
	}

	public function testCreateTransferWithoutDestination(): void {
		$this->mockInput(json_encode([
			'name' => 'Savings Transfer',
			'amount' => 500.00,
			'isTransfer' => true,
			'accountId' => 1,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('destination account', $response->getData()['error']);
	}

	public function testCreateTransferSameAccount(): void {
		$this->mockInput(json_encode([
			'name' => 'Self Transfer',
			'amount' => 500.00,
			'isTransfer' => true,
			'accountId' => 1,
			'destinationAccountId' => 1,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('same account', $response->getData()['error']);
	}

	public function testCreateInvalidName(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => false, 'error' => 'Name required']);
		$vs->method('validateFrequency')->willReturn(['valid' => true, 'formatted' => 'monthly']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['name' => '', 'amount' => 10.00]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Name required', $response->getData()['error']);
	}

	public function testCreateInvalidFrequency(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'Bill']);
		$vs->method('validateFrequency')->willReturn(['valid' => false, 'error' => 'Bad freq']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['name' => 'Bill', 'amount' => 10.00, 'frequency' => 'bad']));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Bad freq', $response->getData()['error']);
	}

	public function testCreateInvalidDueDay(): void {
		$this->mockInput(json_encode([
			'name' => 'Netflix', 'amount' => 15.99, 'dueDay' => 32,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Due day', $response->getData()['error']);
	}

	public function testCreateInvalidDueDayZero(): void {
		$this->mockInput(json_encode([
			'name' => 'Netflix', 'amount' => 15.99, 'dueDay' => 0,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidDueMonth(): void {
		$this->mockInput(json_encode([
			'name' => 'Netflix', 'amount' => 15.99, 'dueMonth' => 13,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Due month', $response->getData()['error']);
	}

	public function testCreateInvalidDueMonthZero(): void {
		$this->mockInput(json_encode([
			'name' => 'Netflix', 'amount' => 15.99, 'dueMonth' => 0,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidAutoDetectPattern(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'Bill']);
		$vs->method('validateFrequency')->willReturn(['valid' => true, 'formatted' => 'monthly']);
		$vs->method('validatePattern')->willReturn(['valid' => false, 'error' => 'Bad pattern']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'autoDetectPattern' => 'bad',
		]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Bad pattern', $response->getData()['error']);
	}

	public function testCreateInvalidNotes(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'Bill']);
		$vs->method('validateFrequency')->willReturn(['valid' => true, 'formatted' => 'monthly']);
		$vs->method('validateNotes')->willReturn(['valid' => false, 'error' => 'Notes too long']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'notes' => str_repeat('x', 5000),
		]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Notes too long', $response->getData()['error']);
	}

	public function testCreateInvalidReminderDays(): void {
		$this->mockInput(json_encode([
			'name' => 'Netflix', 'amount' => 15.99, 'reminderDays' => 31,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Reminder days', $response->getData()['error']);
	}

	public function testCreateNegativeReminderDays(): void {
		$this->mockInput(json_encode([
			'name' => 'Netflix', 'amount' => 15.99, 'reminderDays' => -1,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateInvalidCustomRecurrencePattern(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'frequency' => 'custom',
			'customRecurrencePattern' => 'not json',
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid JSON', $response->getData()['error']);
	}

	public function testCreateInvalidTransactionDate(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => true, 'sanitized' => 'Bill']);
		$vs->method('validateFrequency')->willReturn(['valid' => true, 'formatted' => 'monthly']);
		$vs->method('validateDate')->willReturn(['valid' => false, 'error' => 'Bad date']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'transactionDate' => 'bad',
		]));

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Bad date', $response->getData()['error']);
	}

	public function testCreateInvalidRemainingPayments(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'remainingPayments' => 0,
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Remaining payments', $response->getData()['error']);
	}

	public function testCreateWithAllFields(): void {
		$this->mockInput(json_encode([
			'name' => 'Rent',
			'amount' => 1200.00,
			'frequency' => 'monthly',
			'dueDay' => 1,
			'dueMonth' => null,
			'categoryId' => 5,
			'accountId' => 1,
			'autoDetectPattern' => 'RENT',
			'notes' => 'Monthly rent',
			'reminderDays' => 3,
			'createTransaction' => true,
			'transactionDate' => '2026-03-01',
			'autoPayEnabled' => true,
			'isTransfer' => false,
			'tagIds' => [1, 2],
			'endDate' => '2027-03-01',
			'remainingPayments' => 12,
		]));

		$bill = $this->createMock(Bill::class);
		$this->service->expects($this->once())->method('create')->willReturn($bill);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateServiceException(): void {
		$this->mockInput(json_encode(['name' => 'Bill', 'amount' => 10.00]));
		$this->service->method('create')->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateValidCustomMonthsPattern(): void {
		$this->mockInput(json_encode([
			'name' => 'Insurance',
			'amount' => 500.00,
			'frequency' => 'custom',
			'customRecurrencePattern' => json_encode(['months' => [1, 6, 7]]),
		]));

		$bill = $this->createMock(Bill::class);
		$this->service->method('create')->willReturn($bill);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateCustomPatternEmptyMonths(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'frequency' => 'custom',
			'customRecurrencePattern' => json_encode(['months' => []]),
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('one month', $response->getData()['error']);
	}

	public function testCreateCustomPatternInvalidMonth(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'frequency' => 'custom',
			'customRecurrencePattern' => json_encode(['months' => [13]]),
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateCustomPatternNotArray(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'frequency' => 'custom',
			'customRecurrencePattern' => json_encode('string'),
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('JSON object', $response->getData()['error']);
	}

	public function testCreateCustomPatternMonthsNotArray(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'frequency' => 'custom',
			'customRecurrencePattern' => json_encode(['months' => 'jan']),
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Months must be an array', $response->getData()['error']);
	}

	public function testCreateValidCustomDatesPattern(): void {
		$this->mockInput(json_encode([
			'name' => 'Tax',
			'amount' => 2000.00,
			'frequency' => 'custom',
			'customRecurrencePattern' => json_encode([
				'dates' => [
					['month' => 1, 'day' => 15],
					['month' => 7, 'day' => 15],
				],
			]),
		]));

		$bill = $this->createMock(Bill::class);
		$this->service->method('create')->willReturn($bill);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateCustomDatesEmptyArray(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'frequency' => 'custom',
			'customRecurrencePattern' => json_encode(['dates' => []]),
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('one date', $response->getData()['error']);
	}

	public function testCreateCustomDatesMissingFields(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'frequency' => 'custom',
			'customRecurrencePattern' => json_encode(['dates' => [['month' => 1]]]),
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('month', $response->getData()['error']);
	}

	public function testCreateCustomDatesInvalidDay(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'frequency' => 'custom',
			'customRecurrencePattern' => json_encode([
				'dates' => [['month' => 1, 'day' => 32]],
			]),
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Day must be', $response->getData()['error']);
	}

	public function testCreateCustomPatternNoMonthsOrDates(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'frequency' => 'custom',
			'customRecurrencePattern' => json_encode(['something' => 'else']),
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('months', $response->getData()['error']);
	}

	public function testCreateCustomDatesNotArray(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'frequency' => 'custom',
			'customRecurrencePattern' => json_encode(['dates' => 'nope']),
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Dates must be an array', $response->getData()['error']);
	}

	public function testCreateCustomDatesInvalidMonth(): void {
		$this->mockInput(json_encode([
			'name' => 'Bill', 'amount' => 10, 'frequency' => 'custom',
			'customRecurrencePattern' => json_encode([
				'dates' => [['month' => 13, 'day' => 1]],
			]),
		]));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Month must be', $response->getData()['error']);
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateSuccess(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));

		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid request data', $response->getData()['error']);
	}

	public function testUpdateEmptyUpdates(): void {
		$this->mockInput(json_encode([]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('No valid fields', $response->getData()['error']);
	}

	public function testUpdateInvalidName(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateName')->willReturn(['valid' => false, 'error' => 'Bad name']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['name' => '']));

		$response = $controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Bad name', $response->getData()['error']);
	}

	public function testUpdateInvalidFrequency(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateFrequency')->willReturn(['valid' => false, 'error' => 'Bad freq']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['frequency' => 'bad']));

		$response = $controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateInvalidDueDay(): void {
		$this->mockInput(json_encode(['dueDay' => 32]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Due day', $response->getData()['error']);
	}

	public function testUpdateDueDayNull(): void {
		$this->mockInput(json_encode(['dueDay' => null]));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateInvalidDueMonth(): void {
		$this->mockInput(json_encode(['dueMonth' => 13]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateDueMonthNull(): void {
		$this->mockInput(json_encode(['dueMonth' => null]));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateInvalidAutoDetectPattern(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validatePattern')->willReturn(['valid' => false, 'error' => 'Bad pattern']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['autoDetectPattern' => 'bad']));

		$response = $controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateAutoDetectPatternNull(): void {
		$this->mockInput(json_encode(['autoDetectPattern' => '']));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateInvalidNotes(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateNotes')->willReturn(['valid' => false, 'error' => 'Notes bad']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['notes' => 'bad']));

		$response = $controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateNotesNull(): void {
		$this->mockInput(json_encode(['notes' => '']));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateAmount(): void {
		$this->mockInput(json_encode(['amount' => 99.99]));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateCategoryIdNull(): void {
		$this->mockInput(json_encode(['categoryId' => null]));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateAccountIdNull(): void {
		$this->mockInput(json_encode(['accountId' => null]));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateActive(): void {
		$this->mockInput(json_encode(['active' => false]));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateInvalidReminderDays(): void {
		$this->mockInput(json_encode(['reminderDays' => 31]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateReminderDaysNull(): void {
		$this->mockInput(json_encode(['reminderDays' => null]));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateLastPaidDate(): void {
		$this->mockInput(json_encode(['lastPaidDate' => '2026-03-01']));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateInvalidCustomRecurrencePattern(): void {
		$this->mockInput(json_encode(['customRecurrencePattern' => 'not json']));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateCustomRecurrencePatternNull(): void {
		$this->mockInput(json_encode(['customRecurrencePattern' => '']));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateAutoPayEnabled(): void {
		$this->mockInput(json_encode(['autoPayEnabled' => true]));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateIsTransfer(): void {
		$this->mockInput(json_encode(['isTransfer' => true, 'destinationAccountId' => 2]));

		$existingBill = new Bill();
		$existingBill->setDestinationAccountId(2);
		$existingBill->setAccountId(1);
		$this->service->method('find')->willReturn($existingBill);

		$updatedBill = new Bill();
		$this->service->method('update')->willReturn($updatedBill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateTransferWithoutDestination(): void {
		$this->mockInput(json_encode(['isTransfer' => true]));

		$existingBill = new Bill();
		$existingBill->setDestinationAccountId(null);
		$this->service->method('find')->willReturn($existingBill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('destination account', $response->getData()['error']);
	}

	public function testUpdateTransferSameAccount(): void {
		$this->mockInput(json_encode(['destinationAccountId' => 1]));

		$existingBill = new Bill();
		$existingBill->setAccountId(1);
		$existingBill->setDestinationAccountId(null);
		$this->service->method('find')->willReturn($existingBill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('same account', $response->getData()['error']);
	}

	public function testUpdateTagIds(): void {
		$this->mockInput(json_encode(['tagIds' => [1, 2, 3]]));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateTagIdsEmpty(): void {
		$this->mockInput(json_encode(['tagIds' => []]));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateInvalidEndDate(): void {
		$vs = $this->createMock(ValidationService::class);
		$vs->method('validateDate')->willReturn(['valid' => false, 'error' => 'Bad date']);

		$controller = $this->controllerWithValidation($vs);
		$this->mockInput(json_encode(['endDate' => 'bad']));

		$response = $controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateEndDateNull(): void {
		$this->mockInput(json_encode(['endDate' => '']));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateInvalidRemainingPayments(): void {
		$this->mockInput(json_encode(['remainingPayments' => 0]));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Remaining payments', $response->getData()['error']);
	}

	public function testUpdateRemainingPaymentsNull(): void {
		$this->mockInput(json_encode(['remainingPayments' => '']));
		$bill = $this->createMock(Bill::class);
		$this->service->method('update')->willReturn($bill);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateServiceException(): void {
		$this->mockInput(json_encode(['name' => 'Updated']));
		$this->service->method('update')->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
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
		$this->mockInput(json_encode([]));
		$bill = $this->createMock(Bill::class);
		$this->service->method('markPaid')->willReturn($bill);

		$response = $this->controller->markPaid(1, '2026-03-01');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMarkPaidHandlesError(): void {
		$this->mockInput(json_encode([]));
		$this->service->method('markPaid')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->markPaid(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testMarkPaidWithCreateNextTransaction(): void {
		$this->mockInput(json_encode(['createNextTransaction' => false]));
		$bill = $this->createMock(Bill::class);
		$this->service->expects($this->once())
			->method('markPaid')
			->with(1, 'user1', '2026-03-01', false)
			->willReturn($bill);

		$response = $this->controller->markPaid(1, '2026-03-01');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── upcoming ────────────────────────────────────────────────────

	public function testUpcomingReturnsBills(): void {
		$bills = [['id' => 1, 'dueDate' => '2026-03-15']];
		$this->service->method('findUpcoming')->with('user1', 30)->willReturn($bills);

		$response = $this->controller->upcoming(30);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpcomingHandlesError(): void {
		$this->service->method('findUpcoming')->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->upcoming();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpcomingDefaultDays(): void {
		$this->service->expects($this->once())
			->method('findUpcoming')
			->with('user1', 30)
			->willReturn([]);

		$this->controller->upcoming();
	}

	// ── dueThisMonth ────────────────────────────────────────────────

	public function testDueThisMonthReturnsBills(): void {
		$this->service->method('findDueThisMonth')->willReturn([['id' => 1]]);

		$response = $this->controller->dueThisMonth();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDueThisMonthHandlesError(): void {
		$this->service->method('findDueThisMonth')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->dueThisMonth();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── overdue ─────────────────────────────────────────────────────

	public function testOverdueReturnsBills(): void {
		$this->service->method('findOverdue')->willReturn([['id' => 1]]);

		$response = $this->controller->overdue();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testOverdueHandlesError(): void {
		$this->service->method('findOverdue')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->overdue();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── summary ─────────────────────────────────────────────────────

	public function testSummaryReturnsData(): void {
		$summary = ['monthlyTotal' => 1500.00, 'billCount' => 5];
		$this->service->method('getMonthlySummary')->willReturn($summary);

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSummaryHandlesError(): void {
		$this->service->method('getMonthlySummary')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── statusForMonth ──────────────────────────────────────────────

	public function testStatusForMonthReturnsData(): void {
		$status = ['paid' => 3, 'unpaid' => 2];
		$this->service->method('getBillStatusForMonth')->willReturn($status);

		$response = $this->controller->statusForMonth('2026-03');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testStatusForMonthHandlesError(): void {
		$this->service->method('getBillStatusForMonth')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->statusForMonth();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testStatusForMonthDefaultNull(): void {
		$this->service->expects($this->once())
			->method('getBillStatusForMonth')
			->with('user1', null)
			->willReturn([]);

		$this->controller->statusForMonth();
	}

	// ── detect ──────────────────────────────────────────────────────

	public function testDetectReturnsDetectedBills(): void {
		$detected = [['pattern' => 'Netflix', 'amount' => 15.99]];
		$this->service->method('detectRecurringBills')->with('user1', 6)->willReturn($detected);

		$response = $this->controller->detect(6);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDetectHandlesError(): void {
		$this->service->method('detectRecurringBills')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->detect();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testDetectDefaultMonths(): void {
		$this->service->expects($this->once())
			->method('detectRecurringBills')
			->with('user1', 6)
			->willReturn([]);

		$this->controller->detect();
	}

	// ── createFromDetected ──────────────────────────────────────────

	public function testCreateFromDetectedSuccess(): void {
		$this->mockInput(json_encode([
			'bills' => [
				['name' => 'Netflix', 'amount' => 15.99],
			],
		]));

		$created = [['id' => 1, 'name' => 'Netflix']];
		$this->service->method('createFromDetected')->willReturn($created);

		$response = $this->controller->createFromDetected();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(1, $response->getData()['created']);
	}

	public function testCreateFromDetectedInvalidJson(): void {
		$this->mockInput('not json');

		$response = $this->controller->createFromDetected();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateFromDetectedMissingBills(): void {
		$this->mockInput(json_encode(['other' => 'data']));

		$response = $this->controller->createFromDetected();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateFromDetectedServiceError(): void {
		$this->mockInput(json_encode(['bills' => [['name' => 'X']]]));
		$this->service->method('createFromDetected')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->createFromDetected();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
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

	public function testAnnualOverviewFutureYearLimit(): void {
		$response = $this->controller->annualOverview(2101);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testAnnualOverviewWithTransfers(): void {
		$this->service->method('getAnnualOverview')
			->with('user1', 2026, true, 'active')
			->willReturn([]);

		$response = $this->controller->annualOverview(2026, 'true');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAnnualOverviewInvalidStatusDefaultsToActive(): void {
		$this->service->expects($this->once())
			->method('getAnnualOverview')
			->with('user1', 2026, false, 'active')
			->willReturn([]);

		$response = $this->controller->annualOverview(2026, 'false', 'bogus');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAnnualOverviewAllStatus(): void {
		$this->service->expects($this->once())
			->method('getAnnualOverview')
			->with('user1', 2026, false, 'all')
			->willReturn([]);

		$response = $this->controller->annualOverview(2026, 'false', 'all');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAnnualOverviewHandlesError(): void {
		$this->service->method('getAnnualOverview')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->annualOverview(2026);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── exportCalendar ──────────────────────────────────────────────

	public function testExportCalendarCsvSuccess(): void {
		$data = [
			'year' => 2026,
			'bills' => [
				[
					'name' => 'Rent',
					'amount' => 1200,
					'frequency' => 'monthly',
					'occurrences' => array_fill(1, 12, true),
				],
			],
			'monthlyTotals' => array_fill(1, 12, 1200),
		];
		$this->service->method('getAnnualOverview')->willReturn($data);

		$response = $this->controller->exportCalendar('csv', 2026);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportCalendarInvalidYear(): void {
		$response = $this->controller->exportCalendar('csv', 1990);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testExportCalendarDefaultYear(): void {
		$currentYear = (int)date('Y');
		$this->service->method('getAnnualOverview')
			->with('user1', $currentYear, false, 'active')
			->willReturn(['year' => $currentYear, 'bills' => [], 'monthlyTotals' => []]);

		$response = $this->controller->exportCalendar();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportCalendarPdfFallbackToCsv(): void {
		$data = ['year' => 2026, 'bills' => [], 'monthlyTotals' => []];
		$this->service->method('getAnnualOverview')->willReturn($data);

		// TCPDF likely not loaded in test env, so PDF falls back to CSV
		$response = $this->controller->exportCalendar('pdf', 2026);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportCalendarHandlesError(): void {
		$this->service->method('getAnnualOverview')
			->willThrowException(new \RuntimeException('err'));

		$response = $this->controller->exportCalendar('csv', 2026);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testExportCalendarInvalidStatusDefaultsToActive(): void {
		$this->service->expects($this->once())
			->method('getAnnualOverview')
			->with('user1', 2026, false, 'active')
			->willReturn(['year' => 2026, 'bills' => [], 'monthlyTotals' => []]);

		$this->controller->exportCalendar('csv', 2026, 'false', 'bogus');
	}

	public function testExportCalendarEmptyBills(): void {
		$data = ['year' => 2026, 'bills' => [], 'monthlyTotals' => []];
		$this->service->method('getAnnualOverview')->willReturn($data);

		$response = $this->controller->exportCalendar('csv', 2026);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}
}
