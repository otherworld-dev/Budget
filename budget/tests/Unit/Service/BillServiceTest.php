<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\TransactionService;
use PHPUnit\Framework\TestCase;

class BillServiceTest extends TestCase {
	private BillService $service;
	private BillMapper $mapper;
	private FrequencyCalculator $frequencyCalculator;
	private RecurringBillDetector $recurringDetector;
	private TransactionService $transactionService;

	protected function setUp(): void {
		$this->mapper = $this->createMock(BillMapper::class);
		$this->frequencyCalculator = $this->createMock(FrequencyCalculator::class);
		$this->recurringDetector = $this->createMock(RecurringBillDetector::class);
		$this->transactionService = $this->createMock(TransactionService::class);

		$this->service = new BillService(
			$this->mapper,
			$this->frequencyCalculator,
			$this->recurringDetector,
			$this->transactionService
		);
	}

	private function makeBill(array $overrides = []): Bill {
		$bill = new Bill();
		$bill->setId($overrides['id'] ?? 1);
		$bill->setUserId($overrides['userId'] ?? 'user1');
		$bill->setName($overrides['name'] ?? 'Netflix');
		$bill->setAmount($overrides['amount'] ?? 15.99);
		$bill->setFrequency($overrides['frequency'] ?? 'monthly');
		$bill->setDueDay($overrides['dueDay'] ?? 15);
		$bill->setDueMonth($overrides['dueMonth'] ?? null);
		$bill->setIsActive($overrides['isActive'] ?? true);
		$bill->setAccountId($overrides['accountId'] ?? 1);
		$bill->setNextDueDate($overrides['nextDueDate'] ?? '2099-06-15');
		$bill->setAutoPayEnabled($overrides['autoPayEnabled'] ?? false);
		$bill->setAutoPayFailed($overrides['autoPayFailed'] ?? false);
		$bill->setLastPaidDate($overrides['lastPaidDate'] ?? null);
		$bill->setRemainingPayments($overrides['remainingPayments'] ?? null);
		$bill->setEndDate($overrides['endDate'] ?? null);
		$bill->setCustomRecurrencePattern($overrides['customRecurrencePattern'] ?? null);
		$bill->setIsTransfer($overrides['isTransfer'] ?? false);
		$bill->setDestinationAccountId($overrides['destinationAccountId'] ?? null);
		$bill->setAutoDetectPattern($overrides['autoDetectPattern'] ?? null);
		$bill->setCreatedAt($overrides['createdAt'] ?? '2024-01-01 00:00:00');
		return $bill;
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateBasicBill(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturn('2099-07-01');
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(fn(Bill $b) => $b);

		$bill = $this->service->create('user1', 'Netflix', 15.99, 'monthly', 1);

		$this->assertSame('Netflix', $bill->getName());
		$this->assertEqualsWithDelta(15.99, $bill->getAmount(), 0.001);
		$this->assertSame('monthly', $bill->getFrequency());
		$this->assertSame('2099-07-01', $bill->getNextDueDate());
		$this->assertTrue($bill->getIsActive());
	}

	public function testCreateAutoPayRequiresAccount(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Auto-pay requires an account');

		$this->service->create('user1', 'Test', 10.0, 'monthly', null, null, null, null, null, null, null, null, false, null, true);
	}

	public function testCreateTransferRequiresDestination(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Transfer requires a destination');

		$this->service->create(
			'user1', 'Transfer', 100.0, 'monthly', null, null, null, 1,
			null, null, null, null, false, null, false,
			true, null // isTransfer=true, destinationAccountId=null
		);
	}

	public function testCreateTransferRejectsSameAccount(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot transfer to the same account');

		$this->service->create(
			'user1', 'Transfer', 100.0, 'monthly', null, null, null, 5,
			null, null, null, null, false, null, false,
			true, 5 // isTransfer=true, destinationAccountId=same as accountId
		);
	}

	public function testCreateWithTransaction(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-01');
		$this->mapper->method('insert')->willReturnCallback(function (Bill $b) {
			$b->setId(42);
			return $b;
		});
		$this->transactionService->expects($this->once())
			->method('createFromBill')
			->with('user1', $this->isInstanceOf(Bill::class), '2024-06-15');

		$this->service->create(
			'user1', 'Test', 50.0, 'monthly', null, null, null, 1,
			null, null, null, null,
			true, '2024-06-15' // createTransaction=true, transactionDate
		);
	}

	// ── markPaid ────────────────────────────────────────────────────

	public function testMarkPaidAdvancesNextDueDate(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-06-15']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->frequencyCalculator->expects($this->once())
			->method('calculateNextDueDate')
			->with('monthly', 15, null, '2099-06-15', null)
			->willReturn('2099-07-15');

		$this->transactionService->method('createFromBill'); // allow call

		$result = $this->service->markPaid(1, 'user1');

		$this->assertSame(date('Y-m-d'), $result->getLastPaidDate());
		$this->assertSame('2099-07-15', $result->getNextDueDate());
		$this->assertTrue($result->getIsActive());
	}

	public function testMarkPaidUsesProvidedDate(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-06-15']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1', '2099-06-10');

		$this->assertSame('2099-06-10', $result->getLastPaidDate());
	}

	public function testMarkPaidOneTimeDeactivates(): void {
		$bill = $this->makeBill(['frequency' => 'one-time', 'nextDueDate' => '2099-06-15']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result->getIsActive());
		$this->assertNull($result->getNextDueDate());
	}

	public function testMarkPaidDecrementsRemainingPayments(): void {
		$bill = $this->makeBill(['remainingPayments' => 3]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertSame(2, $result->getRemainingPayments());
		$this->assertTrue($result->getIsActive());
	}

	public function testMarkPaidLastPaymentDeactivates(): void {
		$bill = $this->makeBill(['remainingPayments' => 1]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertSame(0, $result->getRemainingPayments());
		$this->assertFalse($result->getIsActive());
		$this->assertNull($result->getNextDueDate());
	}

	public function testMarkPaidDeactivatesWhenPastEndDate(): void {
		$bill = $this->makeBill(['endDate' => '2099-06-30']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		// Next due date would be after end date
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result->getIsActive());
		$this->assertNull($result->getNextDueDate());
	}

	public function testMarkPaidResetsAutoPayFailed(): void {
		$bill = $this->makeBill(['autoPayFailed' => true]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result->getAutoPayFailed());
	}

	public function testMarkPaidCreatesTransactionForOneTimeBill(): void {
		$bill = $this->makeBill(['frequency' => 'one-time']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		// One-time bills create a cleared transaction for the current payment before deactivating
		$this->transactionService->expects($this->once())->method('createFromBill');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result->getIsActive());
	}

	// ── processAutoPay ──────────────────────────────────────────────

	public function testProcessAutoPaySuccess(): void {
		$bill = $this->makeBill(['autoPayEnabled' => true, 'accountId' => 1]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('successfully', $result['message']);
	}

	public function testProcessAutoPayNotEnabled(): void {
		$bill = $this->makeBill(['autoPayEnabled' => false]);
		$this->mapper->method('find')->willReturn($bill);

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not enabled', $result['message']);
	}

	public function testProcessAutoPayNoAccount(): void {
		// Build a bill where accountId is truly null (not set at all)
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('user1');
		$bill->setName('Test');
		$bill->setAmount(10.0);
		$bill->setFrequency('monthly');
		$bill->setAutoPayEnabled(true);
		$bill->setAutoPayFailed(false);
		// Do NOT call setAccountId → remains null
		$bill->setIsActive(true);

		$this->mapper->method('find')->willReturn($bill);

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('no account', $result['message']);
	}

	// ── matchTransactionToBill ──────────────────────────────────────

	public function testMatchTransactionToBillExactMatch(): void {
		$bill = $this->makeBill(['autoDetectPattern' => 'NETFLIX', 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		$result = $this->service->matchTransactionToBill('user1', 'NETFLIX.COM Subscription', 15.99);
		$this->assertNotNull($result);
		$this->assertSame('Netflix', $result->getName());
	}

	public function testMatchTransactionToBillWithinTolerance(): void {
		$bill = $this->makeBill(['autoDetectPattern' => 'NETFLIX', 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		// Within 10% tolerance
		$result = $this->service->matchTransactionToBill('user1', 'NETFLIX Payment', 16.50);
		$this->assertNotNull($result);
	}

	public function testMatchTransactionToBillOutsideTolerance(): void {
		$bill = $this->makeBill(['autoDetectPattern' => 'NETFLIX', 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		// Way outside 10% tolerance
		$result = $this->service->matchTransactionToBill('user1', 'NETFLIX Premium', 25.00);
		$this->assertNull($result);
	}

	public function testMatchTransactionToBillNoPatternMatch(): void {
		$bill = $this->makeBill(['autoDetectPattern' => 'NETFLIX', 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		$result = $this->service->matchTransactionToBill('user1', 'SPOTIFY Premium', 9.99);
		$this->assertNull($result);
	}

	public function testMatchTransactionToBillCaseInsensitive(): void {
		$bill = $this->makeBill(['autoDetectPattern' => 'netflix', 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		$result = $this->service->matchTransactionToBill('user1', 'NETFLIX Subscription', 15.99);
		$this->assertNotNull($result);
	}

	public function testMatchTransactionToBillSkipsEmptyPattern(): void {
		$bill = $this->makeBill(['autoDetectPattern' => null, 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		$result = $this->service->matchTransactionToBill('user1', 'Something', 15.99);
		$this->assertNull($result);
	}

	// ── findUpcoming ────────────────────────────────────────────────

	public function testFindUpcomingDeduplicates(): void {
		$bill1 = $this->makeBill(['id' => 1, 'nextDueDate' => '2024-01-10']);
		$bill2 = $this->makeBill(['id' => 2, 'nextDueDate' => '2024-01-20']);

		// bill1 appears in both overdue and upcoming
		$this->mapper->method('findOverdue')->willReturn([$bill1]);
		$this->mapper->method('findDueInRange')->willReturn([$bill1, $bill2]);

		$result = $this->service->findUpcoming('user1');

		$this->assertCount(2, $result);
	}

	public function testFindUpcomingSortsByDueDate(): void {
		$billLater = $this->makeBill(['id' => 1, 'nextDueDate' => '2099-06-20']);
		$billEarlier = $this->makeBill(['id' => 2, 'nextDueDate' => '2099-06-05']);

		$this->mapper->method('findOverdue')->willReturn([]);
		$this->mapper->method('findDueInRange')->willReturn([$billLater, $billEarlier]);

		$result = $this->service->findUpcoming('user1');

		$this->assertSame(2, $result[0]->getId());
		$this->assertSame(1, $result[1]->getId());
	}

	public function testFindUpcomingSortsByDueDateAscending(): void {
		$billLate = $this->makeBill(['id' => 1, 'nextDueDate' => '2099-12-01']);
		$billEarly = $this->makeBill(['id' => 2, 'nextDueDate' => '2099-01-01']);

		$this->mapper->method('findOverdue')->willReturn([]);
		$this->mapper->method('findDueInRange')->willReturn([$billLate, $billEarly]);

		$result = $this->service->findUpcoming('user1');

		$this->assertCount(2, $result);
		// Earlier due date first
		$this->assertSame('2099-01-01', $result[0]->getNextDueDate());
		$this->assertSame('2099-12-01', $result[1]->getNextDueDate());
	}

	// ── detectRecurringBills ────────────────────────────────────────

	public function testDetectRecurringBillsDelegatesToDetector(): void {
		$expected = [['description' => 'Netflix', 'amount' => 15.99]];
		$this->recurringDetector->expects($this->once())
			->method('detectRecurringBills')
			->with('user1', 6)
			->willReturn($expected);

		$result = $this->service->detectRecurringBills('user1', 6);
		$this->assertSame($expected, $result);
	}
}
