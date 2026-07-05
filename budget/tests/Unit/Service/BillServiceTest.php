<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
			}
			return $text;
		});
		$accountMapper = $this->createMock(AccountMapper::class);
		$currencyConversion = $this->createMock(CurrencyConversionService::class);
		$splitService = $this->createMock(TransactionSplitService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new BillService(
			$this->mapper,
			$this->frequencyCalculator,
			$this->recurringDetector,
			$this->transactionService,
			$l,
			$accountMapper,
			$currencyConversion,
			$splitService,
			$logger
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
		if (array_key_exists('createTransaction', $overrides)) {
			$bill->setCreateTransaction($overrides['createTransaction']);
		}
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

	public function testCreatePersistsStartDateAndFloorsNextDue(): void {
		// First call = next due from today (before start); second = from start date.
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnOnConsecutiveCalls('2099-07-01', '2099-09-01');
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(fn(Bill $b) => $b);

		$bill = $this->service->create(
			'user1', 'Rent', 1000.0, 'monthly', 1,
			null, null, null, null, null,
			null, null, null, false, null,
			false, false, null, null, [],
			null, null, null, '2099-09-01' // startDate (last arg)
		);

		$this->assertSame('2099-09-01', $bill->getStartDate());
		// next due is floored to the start date rather than the earlier 2099-07-01
		$this->assertSame('2099-09-01', $bill->getNextDueDate());
	}

	public function testMonthlyOccurrencesRespectStartDate(): void {
		$bill = new Bill();
		$bill->setFrequency('monthly');
		$bill->setDueDay(1);
		$bill->setStartDate('2026-06-01');

		$method = new \ReflectionMethod($this->service, 'calculateMonthlyOccurrences');
		$method->setAccessible(true);
		$occ = $method->invoke($this->service, $bill, 2026);

		// Months before June are excluded; June onward occur.
		for ($m = 1; $m <= 5; $m++) {
			$this->assertFalse($occ[$m], "month $m should be excluded (before start date)");
		}
		for ($m = 6; $m <= 12; $m++) {
			$this->assertTrue($occ[$m], "month $m should occur (on/after start date)");
		}
	}

	public function testCreateAutoPayRequiresAccount(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Auto-pay requires an account');

		$this->service->create('user1', 'Test', 10.0, 'monthly', null, null, null, null, null, null, null, null, null, false, null, true);
	}

	public function testCreateTransferRequiresDestination(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Transfer requires a destination');

		$this->service->create(
			'user1', 'Transfer', 100.0, 'monthly', null, null, null, 1,
			null, null, null, null, null, false, null, false,
			true, null // isTransfer=true, destinationAccountId=null
		);
	}

	public function testCreateTransferRejectsSameAccount(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot transfer to the same account');

		$this->service->create(
			'user1', 'Transfer', 100.0, 'monthly', null, null, null, 5,
			null, null, null, null, null, false, null, false,
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
			null, null, null, null, null,
			true, '2024-06-15' // createTransaction=true, transactionDate
		);
	}

	// ── createFromDetected (#278) ───────────────────────────────────

	public function testCreateFromDetectedAcrossFrequencies(): void {
		// Regression for #278: createFromDetected drifted its positional args
		// into create(), pushing `false` into ?string customRecurrencePattern
		// and 500-ing every detect-and-add. Exercise detector-shaped items.
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-01');
		$this->mapper->method('insert')->willReturnCallback(function (Bill $b) {
			$b->setId(7);
			return $b;
		});

		$mk = fn(array $o = []) => array_merge([
			'patternKey' => 'netflix|16', 'description' => 'NETFLIX 12345',
			'suggestedName' => 'Netflix', 'amount' => 15.99, 'frequency' => 'monthly',
			'dueDay' => 16, 'categoryId' => null, 'accountId' => null,
			'occurrences' => 4, 'confidence' => 0.83, 'autoDetectPattern' => 'NETFLIX',
			'lastSeen' => '2026-06-01',
		], $o);

		$detected = [
			$mk(),
			$mk(['frequency' => 'weekly', 'dueDay' => 3]),
			$mk(['frequency' => 'yearly']),
			$mk(['amount' => '15.99']),           // numeric string must not TypeError
			$mk(['dueDay' => null]),
			$mk(['categoryId' => 5, 'accountId' => 9]),
		];

		$created = $this->service->createFromDetected('user1', $detected);

		$this->assertCount(6, $created);
		foreach ($created as $bill) {
			$this->assertInstanceOf(Bill::class, $bill);
			$this->assertSame('NETFLIX', $bill->getAutoDetectPattern());
		}
	}

	public function testCreateFromDetectedTransfer(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-01');
		$this->mapper->method('insert')->willReturnCallback(function (Bill $b) {
			$b->setId(8);
			return $b;
		});

		$created = $this->service->createFromDetected('user1', [[
			'suggestedName' => 'Savings transfer', 'amount' => 200.0, 'frequency' => 'monthly',
			'dueDay' => 1, 'isTransfer' => true, 'destinationAccountId' => 3, 'accountId' => 1,
		]]);

		$this->assertCount(1, $created);
		$this->assertTrue($created[0]->getIsTransfer());
		$this->assertSame(3, $created[0]->getDestinationAccountId());
		$this->assertNull($created[0]->getCategoryId());
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
		$bill = $result['bill'];

		$this->assertSame(date('Y-m-d'), $bill->getLastPaidDate());
		$this->assertSame('2099-07-15', $bill->getNextDueDate());
		$this->assertTrue($bill->getIsActive());
		$this->assertArrayHasKey('previousState', $result);
		$this->assertArrayHasKey('createdTransactionIds', $result);
	}

	// ── pre-created next transaction opt-out (#311) ─────────────────

	public function testMarkPaidCreatesNextPlaceholderByDefault(): void {
		// Legacy rows have no flag (null) — treated as opted in
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		// Once for the payment leg, once for the next occurrence
		$this->transactionService->expects($this->exactly(2))->method('createFromBill');

		$this->service->markPaid(1, 'user1');
	}

	public function testMarkPaidSkipsNextPlaceholderWhenOptedOut(): void {
		$bill = $this->makeBill(['createTransaction' => false]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		// Only the payment leg is recorded — no placeholder for the next occurrence
		$this->transactionService->expects($this->once())
			->method('createFromBill')
			->with('user1', $bill, $this->anything(), 'cleared');

		$result = $this->service->markPaid(1, 'user1');

		// Schedule still advances as normal
		$this->assertSame('2099-07-15', $result['bill']->getNextDueDate());
	}

	public function testSkipPaymentSkipsPlaceholderWhenOptedOut(): void {
		$bill = $this->makeBill(['createTransaction' => false]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$this->transactionService->expects($this->once())->method('deleteScheduledBillTransactions')->with(1);
		$this->transactionService->expects($this->never())->method('createFromBill');

		$result = $this->service->skipPayment(1, 'user1');

		$this->assertSame('2099-07-15', $result['bill']->getNextDueDate());
	}

	public function testUpdateTogglingOffRemovesPlaceholders(): void {
		$bill = $this->makeBill(); // no flag = enabled
		$this->mapper->method('find')->willReturn($bill);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-06-15');

		$this->transactionService->expects($this->once())->method('deleteScheduledBillTransactions')->with(1);
		$this->transactionService->expects($this->never())->method('createFromBill');

		$this->service->update(1, 'user1', ['createTransaction' => false]);
	}

	public function testUpdateTogglingOnCreatesPlaceholder(): void {
		$bill = $this->makeBill(['createTransaction' => false]);
		$this->mapper->method('find')->willReturn($bill);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-06-15');

		$this->transactionService->expects($this->once())->method('createFromBill');
		$this->transactionService->expects($this->never())->method('deleteScheduledBillTransactions');

		$this->service->update(1, 'user1', ['createTransaction' => true]);
	}

	public function testCreatePersistsPreBookOptOut(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-01');
		$this->mapper->method('insert')->willReturnCallback(fn(Bill $b) => $b);

		$bill = $this->service->create('user1', 'Netflix', 15.99, 'monthly', 1, createTransaction: false);

		$this->assertFalse($bill->getCreateTransaction());
	}

	public function testMarkPaidUsesProvidedDate(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-06-15']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1', '2099-06-10');

		$this->assertSame('2099-06-10', $result['bill']->getLastPaidDate());
	}

	public function testMarkPaidOneTimeDeactivates(): void {
		$bill = $this->makeBill(['frequency' => 'one-time', 'nextDueDate' => '2099-06-15']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['bill']->getIsActive());
		$this->assertNull($result['bill']->getNextDueDate());
	}

	public function testMarkPaidDecrementsRemainingPayments(): void {
		$bill = $this->makeBill(['remainingPayments' => 3]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertSame(2, $result['bill']->getRemainingPayments());
		$this->assertTrue($result['bill']->getIsActive());
	}

	public function testMarkPaidLastPaymentDeactivates(): void {
		$bill = $this->makeBill(['remainingPayments' => 1]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertSame(0, $result['bill']->getRemainingPayments());
		$this->assertFalse($result['bill']->getIsActive());
		$this->assertNull($result['bill']->getNextDueDate());
	}

	public function testMarkPaidDeactivatesWhenPastEndDate(): void {
		$bill = $this->makeBill(['endDate' => '2099-06-30']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		// Next due date would be after end date
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['bill']->getIsActive());
		$this->assertNull($result['bill']->getNextDueDate());
	}

	public function testMarkPaidResetsAutoPayFailed(): void {
		$bill = $this->makeBill(['autoPayFailed' => true]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['bill']->getAutoPayFailed());
	}

	public function testMarkPaidCreatesTransactionForOneTimeBill(): void {
		$bill = $this->makeBill(['frequency' => 'one-time']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		// One-time bills create a cleared transaction for the current payment before deactivating
		$this->transactionService->expects($this->once())->method('createFromBill');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['bill']->getIsActive());
	}

	public function testMarkPaidReportsPaymentTransactionRecorded(): void {
		$bill = $this->makeBill(['frequency' => 'one-time']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->markPaid(1, 'user1');

		$this->assertTrue($result['paymentTransactionRecorded']);
	}

	public function testMarkPaidReportsNoTransactionWhenBillHasNoAccount(): void {
		// The #89/#274 silent leak: a bill without an account is marked paid
		// but no money movement is recorded — the result must say so, loudly.
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('user1');
		$bill->setName('Mortgage');
		$bill->setAmount(2912.0);
		$bill->setFrequency('monthly');
		$bill->setIsActive(true);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-28');

		$this->transactionService->expects($this->never())->method('createFromBill');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['paymentTransactionRecorded']);
		$this->assertNotNull($result['bill']->getLastPaidDate());
	}

	public function testMarkPaidReportsNoTransactionWhenCreationFails(): void {
		$bill = $this->makeBill(['frequency' => 'one-time']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->transactionService->method('createFromBill')
			->willThrowException(new \Exception('account gone'));

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['paymentTransactionRecorded']);
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

	// ===== Auto-match bills from imported transactions (#274) =====

	private function makeImportedTx(array $overrides = []): \OCA\Budget\Db\Transaction {
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId($overrides['id'] ?? 500);
		$tx->setAccountId($overrides['accountId'] ?? 1);
		$tx->setDate($overrides['date'] ?? '2026-06-14');
		$tx->setDescription($overrides['description'] ?? 'NETFLIX PAYMENT 12345');
		$tx->setVendor($overrides['vendor'] ?? null);
		$tx->setAmount($overrides['amount'] ?? 15.99);
		$tx->setType($overrides['type'] ?? 'debit');
		$tx->setStatus($overrides['status'] ?? 'cleared');
		return $tx;
	}

	private function setupAutoMatchBill(array $overrides = []): Bill {
		$bill = $this->makeBill(array_merge([
			'autoDetectPattern' => 'NETFLIX',
			'nextDueDate' => '2026-06-15',
			'accountId' => 1,
		], $overrides));
		$this->mapper->method('findActive')->willReturn([$bill]);
		// markPaid loads + saves the SAME instance, so the advanced state
		// flows back into the matcher's reload naturally
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-07-15');
		return $bill;
	}

	public function testAutoMatchMarksBillPaidAndLinksTransaction(): void {
		$bill = $this->setupAutoMatchBill();
		$tx = $this->makeImportedTx();

		// The existing transaction gets LINKED — no new money movement
		$this->transactionService->expects($this->once())
			->method('update')
			->with(500, 'user1', ['billId' => 1]);

		$marked = $this->service->autoMatchPaidFromImport('user1', [$tx]);

		$this->assertSame(1, $marked);
		$this->assertSame('2026-06-14', $bill->getLastPaidDate());
		$this->assertSame('2026-07-15', $bill->getNextDueDate());
	}

	public function testAutoMatchMatchesPatternInVendor(): void {
		$this->setupAutoMatchBill();
		$tx = $this->makeImportedTx(['description' => 'Card payment 9912', 'vendor' => 'Netflix Inc']);

		$this->assertSame(1, $this->service->autoMatchPaidFromImport('user1', [$tx]));
	}

	public function testAutoMatchSkipsTransactionOutsideDueWindow(): void {
		$this->setupAutoMatchBill();
		// 45 days before the due date — a historical re-import, not this period
		$tx = $this->makeImportedTx(['date' => '2026-05-01']);

		$this->transactionService->expects($this->never())->method('update');
		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$tx]));
	}

	public function testAutoMatchSkipsWrongAccount(): void {
		$this->setupAutoMatchBill(['accountId' => 1]);
		$tx = $this->makeImportedTx(['accountId' => 2]);

		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$tx]));
	}

	public function testAutoMatchSkipsAmountOutsideTolerance(): void {
		$this->setupAutoMatchBill(['amount' => 15.99]);
		$tx = $this->makeImportedTx(['amount' => 30.00]);

		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$tx]));
	}

	public function testAutoMatchSkipsCreditsAndScheduled(): void {
		$this->setupAutoMatchBill();

		$credit = $this->makeImportedTx(['type' => 'credit']);
		$scheduled = $this->makeImportedTx(['status' => 'scheduled']);

		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$credit, $scheduled]));
	}

	public function testAutoMatchNeverDoubleAdvancesInOneBatch(): void {
		$this->setupAutoMatchBill();
		// Two same-period payments (e.g. duplicate rows in a statement):
		// the first advances the due date to 2026-07-15, putting the second
		// outside the new window
		$tx1 = $this->makeImportedTx(['id' => 500, 'date' => '2026-06-14']);
		$tx2 = $this->makeImportedTx(['id' => 501, 'date' => '2026-06-16']);

		$this->transactionService->expects($this->once())->method('update');
		$this->assertSame(1, $this->service->autoMatchPaidFromImport('user1', [$tx1, $tx2]));
	}

	public function testAutoMatchIgnoresTransferAndPatternlessBills(): void {
		$transfer = $this->makeBill(['id' => 1, 'isTransfer' => true, 'autoDetectPattern' => 'NETFLIX', 'nextDueDate' => '2026-06-15']);
		$patternless = $this->makeBill(['id' => 2, 'autoDetectPattern' => null, 'nextDueDate' => '2026-06-15']);
		$this->mapper->method('findActive')->willReturn([$transfer, $patternless]);

		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$this->makeImportedTx()]));
	}
}
