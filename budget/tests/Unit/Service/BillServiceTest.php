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
	private AccountMapper $accountMapper;

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
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$currencyConversion = $this->createMock(CurrencyConversionService::class);
		$splitService = $this->createMock(TransactionSplitService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->service = new BillService(
			$this->mapper,
			$this->frequencyCalculator,
			$this->recurringDetector,
			$this->transactionService,
			$l,
			$this->accountMapper,
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

	// ── biweekly anchoring to startDate (#364) ──────────────────────

	public function testCreateBiweeklyAnchorsToStartDate(): void {
		// Back the mock with the real calculator so the anchor maths runs.
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn(...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));
		$this->mapper->method('insert')->willReturnCallback(fn(Bill $b) => $b);

		// Anchor 21 days ago: occurrences at -21, -7 and +7 days. Without the
		// anchor, creation week would win and next due would land at +14.
		$anchor = (new \DateTime('-21 days'))->format('Y-m-d');
		$dueDay = (int) (new \DateTime())->format('N'); // same weekday as anchor

		$bill = $this->service->create('user1', 'Pay', 100.0, 'biweekly', $dueDay, startDate: $anchor);

		$this->assertSame((new \DateTime('+7 days'))->format('Y-m-d'), $bill->getNextDueDate());
	}

	public function testUpdateUnrelatedFieldKeepsBiweeklyParityWithStartDate(): void {
		// Live bug: the update consistency check recomputed "from today", so
		// editing ANY field of a biweekly bill in the off-parity week silently
		// flipped its parity. With a startDate anchor the stored due date is
		// consistent and must be left alone.
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn(...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));

		$anchor = (new \DateTime('-21 days'))->format('Y-m-d');
		$bill = $this->makeBill([
			'frequency' => 'biweekly',
			'dueDay' => (int) (new \DateTime())->format('N'),
			'nextDueDate' => (new \DateTime('+7 days'))->format('Y-m-d'),
		]);
		$bill->setStartDate($anchor);
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->expects($this->once())->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['name' => 'Renamed']);

		$this->assertNotNull($captured);
		$this->assertArrayNotHasKey('next_due_date', $captured, 'an unrelated edit must not move an anchored biweekly bill');
	}

	public function testUpdateScheduleChangeRecomputesFromStartDateAnchor(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn(...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));

		// Weekly bill due today, anchored 21 days back; switching it to
		// biweekly must land on the anchor's fortnight (+7 days), not on
		// today's week parity (+14 days).
		$anchor = (new \DateTime('-21 days'))->format('Y-m-d');
		$bill = $this->makeBill([
			'frequency' => 'weekly',
			'dueDay' => (int) (new \DateTime())->format('N'),
			'nextDueDate' => (new \DateTime())->format('Y-m-d'),
		]);
		$bill->setStartDate($anchor);
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['frequency' => 'biweekly']);

		$this->assertSame((new \DateTime('+7 days'))->format('Y-m-d'), $captured['next_due_date'] ?? null);
	}

	public function testUpdateSettingStartDateReanchorsNextDue(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn(...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));

		// Un-anchored biweekly bill stuck on creation-week parity (+14 days);
		// giving it a startDate must snap next due onto the anchor's
		// fortnight (+7 days).
		$anchor = (new \DateTime('-21 days'))->format('Y-m-d');
		$bill = $this->makeBill([
			'frequency' => 'biweekly',
			'dueDay' => (int) (new \DateTime())->format('N'),
			'nextDueDate' => (new \DateTime('+14 days'))->format('Y-m-d'),
		]);
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['startDate' => $anchor]);

		$this->assertSame((new \DateTime('+7 days'))->format('Y-m-d'), $captured['next_due_date'] ?? null);
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

	// ── unrecorded payments (#274) ──────────────────────────────────

	public function testFindUnrecordedPaymentsFlagsPaidBillWithoutTransaction(): void {
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$bill = $this->makeBill(['id' => 7, 'name' => 'Hypothek', 'amount' => 2912.00, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('findAll')->willReturn([$bill]);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([]);

		$result = $this->service->findUnrecordedPayments('user1');

		$this->assertCount(1, $result);
		$this->assertSame(7, $result[0]['billId']);
		$this->assertSame('Hypothek', $result[0]['name']);
		$this->assertSame($paidDate, $result[0]['lastPaidDate']);
	}

	public function testFindUnrecordedPaymentsIgnoresRecordedPayment(): void {
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('findAll')->willReturn([$bill]);

		// Linked payment dated 3 days off the paid date still counts
		$tx = $this->makeImportedTx(['date' => date('Y-m-d', strtotime('-13 days'))]);
		$tx->setBillId(7);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([$tx]);

		$this->assertSame([], $this->service->findUnrecordedPayments('user1'));
	}

	public function testFindUnrecordedPaymentsIgnoresOldAndNeverPaidBills(): void {
		$old = $this->makeBill(['id' => 1, 'lastPaidDate' => date('Y-m-d', strtotime('-90 days'))]);
		$never = $this->makeBill(['id' => 2, 'lastPaidDate' => null]);
		$this->mapper->method('findAll')->willReturn([$old, $never]);
		$this->transactionService->expects($this->never())->method('findRecordedBillTransactions');

		$this->assertSame([], $this->service->findUnrecordedPayments('user1'));
	}

	public function testRecordMissedPaymentCreatesClearedTransactionOnPaidDate(): void {
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([]);

		$created = $this->makeImportedTx(['id' => 900, 'date' => $paidDate]);
		$this->transactionService->expects($this->once())
			->method('createFromBill')
			->with('user1', $bill, $paidDate, 'cleared')
			->willReturn($created);

		$result = $this->service->recordMissedPayment(7, 'user1');

		$this->assertSame(900, $result['transaction']->getId());
	}

	public function testRecordMissedPaymentRefusesWhenAlreadyRecorded(): void {
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('find')->willReturn($bill);

		$tx = $this->makeImportedTx(['date' => $paidDate]);
		$tx->setBillId(7);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([$tx]);
		$this->transactionService->expects($this->never())->method('createFromBill');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->recordMissedPayment(7, 'user1');
	}

	public function testRecordMissedPaymentRefusesWithoutAccount(): void {
		// makeBill's `?? 1` default swallows a null override, so unset explicitly
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => date('Y-m-d')]);
		$bill->setAccountId(null);
		$this->mapper->method('find')->willReturn($bill);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->recordMissedPayment(7, 'user1');
	}

	// ── amountType (#347) ───────────────────────────────────────────

	public function testBillSerializesAmountTypeWithFixedDefault(): void {
		$bill = $this->makeBill();
		$this->assertSame('fixed', $bill->jsonSerialize()['amountType']);

		$bill->setAmountType('statement');
		$this->assertSame('statement', $bill->jsonSerialize()['amountType']);
	}

	private function makeCardAccount(int $id = 20, string $type = 'credit_card'): \OCA\Budget\Db\Account {
		$account = new \OCA\Budget\Db\Account();
		$account->setId($id);
		$account->setUserId('user1');
		$account->setName('Visa');
		$account->setType($type);
		$account->setCurrency('GBP');
		return $account;
	}

	public function testCreateStatementBillRequiresTransferWithDestination(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('requires a transfer with a destination account');

		$this->service->create(
			userId: 'user1', name: 'Visa payment', amount: 0.0,
			amountType: 'statement'
		);
	}

	public function testCreateStatementBillRejectsNonCardDestination(): void {
		$this->accountMapper->method('findById')->willReturn($this->makeCardAccount(20, 'checking'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('only available for transfers to a credit card');

		$this->service->create(
			userId: 'user1', name: 'Visa payment', amount: 0.0,
			accountId: 1, isTransfer: true, destinationAccountId: 20,
			amountType: 'statement'
		);
	}

	public function testCreateStatementBillResolvesInitialEstimate(): void {
		$this->accountMapper->method('findById')->willReturn($this->makeCardAccount());
		$this->transactionService->method('getStatementAmountForAccount')
			->with(20, $this->anything())
			->willReturn(440.0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-09-15');
		$this->mapper->method('insert')->willReturnCallback(fn(Bill $b) => $b);

		$bill = $this->service->create(
			userId: 'user1', name: 'Visa payment', amount: 0.0, frequency: 'monthly',
			dueDay: 15, accountId: 1, isTransfer: true, destinationAccountId: 20,
			amountType: 'statement'
		);

		$this->assertSame('statement', $bill->getAmountType());
		$this->assertEqualsWithDelta(440.0, $bill->getAmount(), 0.001);
	}

	public function testMarkPaidStatementBillResolvesAmountAtDueDate(): void {
		$bill = $this->makeBill([
			'isTransfer' => true, 'destinationAccountId' => 20,
			'amount' => 300.0, 'nextDueDate' => '2026-08-15',
		]);
		$bill->setAmountType('statement');
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-09-15');
		$this->transactionService->expects($this->once())
			->method('getStatementAmountForAccount')
			->with(20, '2026-08-15')
			->willReturn(440.0);
		$this->transactionService->expects($this->once())
			->method('clearScheduledBillTransaction')
			->with('user1', 1, $this->anything(), 440.0)
			->willReturn(null);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(55);
		$this->transactionService->method('createFromBill')->willReturn($tx);

		$result = $this->service->markPaid(1, 'user1');

		$this->assertEqualsWithDelta(440.0, $result['bill']->getAmount(), 0.001);
		$this->assertEqualsWithDelta(300.0, $result['previousState']['amount'], 0.001);
		$this->assertEqualsWithDelta(440.0, $result['statementAmount'], 0.001);
	}

	public function testMarkPaidFixedBillNeverResolvesStatementAmount(): void {
		$bill = $this->makeBill(['nextDueDate' => '2026-08-15']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-09-15');
		$this->transactionService->expects($this->never())->method('getStatementAmountForAccount');
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(55);
		$this->transactionService->method('createFromBill')->willReturn($tx);

		$result = $this->service->markPaid(1, 'user1');

		$this->assertEqualsWithDelta(15.99, $result['bill']->getAmount(), 0.001);
		$this->assertNull($result['statementAmount']);
	}

	public function testMarkPaidCurrentBalanceResolvesAtPaidDate(): void {
		// current_balance pays everything owed at payment time, so the
		// boundary is the paid date, not the due date
		$bill = $this->makeBill([
			'isTransfer' => true, 'destinationAccountId' => 20,
			'amount' => 300.0, 'nextDueDate' => '2026-08-15',
		]);
		$bill->setAmountType('current_balance');
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-09-15');
		$this->transactionService->expects($this->once())
			->method('getStatementAmountForAccount')
			->with(20, '2026-08-19')
			->willReturn(475.0);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(55);
		$this->transactionService->method('createFromBill')->willReturn($tx);

		$result = $this->service->markPaid(1, 'user1', '2026-08-19');

		$this->assertEqualsWithDelta(475.0, $result['bill']->getAmount(), 0.001);
		$this->assertEqualsWithDelta(475.0, $result['statementAmount'], 0.001);
	}

	public function testMarkPaidMinimumPaymentUsesCardMinimum(): void {
		$card = $this->makeCardAccount();
		$card->setMinimumPayment(50.0);
		$this->accountMapper->method('findById')->willReturn($card);
		$bill = $this->makeBill([
			'isTransfer' => true, 'destinationAccountId' => 20,
			'amount' => 300.0, 'nextDueDate' => '2026-08-15',
		]);
		$bill->setAmountType('minimum_payment');
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-09-15');
		$this->transactionService->method('getStatementAmountForAccount')
			->with(20, '2026-08-19')
			->willReturn(475.0);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(55);
		$this->transactionService->method('createFromBill')->willReturn($tx);

		$result = $this->service->markPaid(1, 'user1', '2026-08-19');

		$this->assertEqualsWithDelta(50.0, $result['bill']->getAmount(), 0.001);
	}

	public function testMarkPaidMinimumPaymentNeverExceedsOwed(): void {
		// Owing less than the minimum: pay what is owed, like real cards
		$card = $this->makeCardAccount();
		$card->setMinimumPayment(50.0);
		$this->accountMapper->method('findById')->willReturn($card);
		$bill = $this->makeBill([
			'isTransfer' => true, 'destinationAccountId' => 20,
			'amount' => 300.0, 'nextDueDate' => '2026-08-15',
		]);
		$bill->setAmountType('minimum_payment');
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-09-15');
		$this->transactionService->method('getStatementAmountForAccount')->willReturn(30.0);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(55);
		$this->transactionService->method('createFromBill')->willReturn($tx);

		$result = $this->service->markPaid(1, 'user1', '2026-08-19');

		$this->assertEqualsWithDelta(30.0, $result['bill']->getAmount(), 0.001);
	}

	public function testCreateCurrentBalanceBillRequiresCardDestination(): void {
		$this->accountMapper->method('findById')->willReturn($this->makeCardAccount(20, 'savings'));

		$this->expectException(\InvalidArgumentException::class);

		$this->service->create(
			userId: 'user1', name: 'Visa payment', amount: 0.0,
			accountId: 1, isTransfer: true, destinationAccountId: 20,
			amountType: 'current_balance'
		);
	}

	public function testUndoPaidRestoresPreviousAmount(): void {
		$bill = $this->makeBill(['amount' => 440.0]);
		$bill->setAmountType('statement');
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$restored = $this->service->undoPaid(1, 'user1', [
			'lastPaidDate' => null, 'nextDueDate' => '2026-08-15',
			'isActive' => true, 'amount' => 300.0,
		], []);

		$this->assertEqualsWithDelta(300.0, $restored->getAmount(), 0.001);
	}

	// ── durable mark-as-unpaid (#365) ───────────────────────────────

	private function makePaidUndoSnapshot(array $overrides = []): string {
		return json_encode(array_merge([
			'previousState' => [
				'lastPaidDate' => null,
				'nextDueDate' => '2099-06-15',
				'remainingPayments' => null,
				'isActive' => true,
				'autoPayFailed' => false,
				'amount' => 15.99,
			],
			'createdTransactionIds' => [55, 56],
			'hadScheduledTransaction' => false,
			'paidDate' => '2099-06-15',
		], $overrides));
	}

	public function testMarkPaidPersistsUndoSnapshot(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-06-15', 'remainingPayments' => 3]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$payment = new \OCA\Budget\Db\Transaction();
		$payment->setId(55);
		$placeholder = new \OCA\Budget\Db\Transaction();
		$placeholder->setId(56);
		$this->transactionService->method('createFromBill')
			->willReturnOnConsecutiveCalls($payment, $placeholder);

		$result = $this->service->markPaid(1, 'user1');

		$raw = $result['bill']->getPaidUndoState();
		$this->assertNotNull($raw, 'markPaid must persist the undo snapshot on the bill');
		$decoded = json_decode($raw, true);
		$this->assertSame('2099-06-15', $decoded['previousState']['nextDueDate']);
		$this->assertSame(3, $decoded['previousState']['remainingPayments']);
		// The payment leg and the next-occurrence placeholder are tracked
		// separately: the placeholder may materialise into a real ledger row
		// before the snapshot is used, and must then survive the revert.
		$this->assertSame([55], $decoded['createdTransactionIds']);
		$this->assertSame([56], $decoded['scheduledTransactionIds']);
		$this->assertNull($decoded['linkedTransactionId']);
		$this->assertFalse($decoded['hadScheduledTransaction']);
		$this->assertSame(date('Y-m-d'), $decoded['paidDate']);
	}

	public function testMarkUnpaidRestoresRecurringBill(): void {
		$bill = $this->makeBill([
			'nextDueDate' => '2099-07-15', 'remainingPayments' => 2,
			'lastPaidDate' => '2099-06-15',
		]);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot([
			'previousState' => [
				'lastPaidDate' => null, 'nextDueDate' => '2099-06-15',
				'remainingPayments' => 3, 'isActive' => true,
				'autoPayFailed' => false, 'amount' => 15.99,
			],
		]));
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$deleted = [];
		$this->transactionService->method('deleteAsAccountOwner')
			->willReturnCallback(function (int $id) use (&$deleted) {
				$deleted[] = $id;
				return true;
			});

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertSame('2099-06-15', $restored->getNextDueDate());
		$this->assertSame(3, $restored->getRemainingPayments());
		$this->assertNull($restored->getLastPaidDate());
		$this->assertSame([55, 56], $deleted);
		$this->assertNull($restored->getPaidUndoState(), 'snapshot must be cleared after use');
	}

	public function testMarkUnpaidReactivatesOneTimeBill(): void {
		$bill = $this->makeBill([
			'frequency' => 'one-time', 'isActive' => false,
			'lastPaidDate' => '2099-06-15',
		]);
		$bill->setNextDueDate(null);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertTrue($restored->getIsActive(), 'one-time bill must be reactivated');
		$this->assertSame('2099-06-15', $restored->getNextDueDate());
		$this->assertNull($restored->getLastPaidDate());
		$this->assertNull($restored->getPaidUndoState());
	}

	public function testMarkUnpaidRestoresStatementAmountFromSnapshot(): void {
		// Statement amounts resolve from the card ledger at payment time and
		// cannot be re-derived later (#347) — only the snapshot can restore it.
		$bill = $this->makeBill([
			'isTransfer' => true, 'destinationAccountId' => 20, 'amount' => 440.0,
		]);
		$bill->setAmountType('statement');
		$bill->setPaidUndoState($this->makePaidUndoSnapshot([
			'previousState' => [
				'lastPaidDate' => null, 'nextDueDate' => '2099-06-15',
				'remainingPayments' => null, 'isActive' => true,
				'autoPayFailed' => false, 'amount' => 300.0,
			],
		]));
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertEqualsWithDelta(300.0, $restored->getAmount(), 0.001);
	}

	public function testMarkUnpaidToleratesAlreadyDeletedTransactions(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-07-15', 'lastPaidDate' => '2099-06-15']);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->transactionService->method('deleteAsAccountOwner')
			->willThrowException(new \Exception('already gone'));

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertSame('2099-06-15', $restored->getNextDueDate());
		$this->assertNull($restored->getPaidUndoState());
	}

	public function testMarkUnpaidRecreatesScheduledPlaceholderFromSnapshot(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-07-15', 'lastPaidDate' => '2099-06-15']);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot(['hadScheduledTransaction' => true]));
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->transactionService->expects($this->once())->method('createFromBill');

		$this->service->markUnpaid(1, 'user1');
	}

	public function testMarkUnpaidWithoutSnapshotThrows(): void {
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('This bill has no recorded payment to undo');

		$this->service->markUnpaid(1, 'user1');
	}

	public function testMarkUnpaidWithCorruptSnapshotThrows(): void {
		$bill = $this->makeBill();
		$bill->setPaidUndoState('not json');
		$this->mapper->method('find')->willReturn($bill);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->markUnpaid(1, 'user1');
	}

	public function testUndoPaidClearsPersistedSnapshot(): void {
		// The toast path and the durable path share the revert — either one
		// consumes the stored snapshot.
		$bill = $this->makeBill();
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$restored = $this->service->undoPaid(1, 'user1', [
			'lastPaidDate' => null, 'nextDueDate' => '2099-06-15', 'isActive' => true,
		], []);

		$this->assertNull($restored->getPaidUndoState());
	}

	public function testBillSerializesCanMarkUnpaidHintNotRawSnapshot(): void {
		$bill = $this->makeBill();
		$this->assertFalse($bill->jsonSerialize()['canMarkUnpaid']);

		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$json = $bill->jsonSerialize();
		$this->assertTrue($json['canMarkUnpaid']);
		$this->assertArrayNotHasKey('paidUndoState', $json, 'internal blob must not reach the frontend');
	}

	// ── mark-unpaid review round (#363, #364, #365) ─────────────────

	public function testUndoPaidDeletesTransactionsUnderTheAccountOwner(): void {
		// markPaid created the payment rows under the ACCOUNT owner (#334) —
		// for a bill on a shared account that is not the acting user, so the
		// revert must route every deletion through the owner-resolving path.
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->transactionService->expects($this->never())->method('delete');
		$deleted = [];
		$this->transactionService->method('deleteAsAccountOwner')
			->willReturnCallback(function (int $id, bool $onlyIfScheduled = false) use (&$deleted) {
				$deleted[] = $id;
				return true;
			});

		$this->service->undoPaid(1, 'user1', [
			'lastPaidDate' => null, 'nextDueDate' => '2099-06-15', 'isActive' => true,
		], [55, 56]);

		$this->assertSame([55, 56], $deleted);
	}

	public function testUpdateEditOnThePaidDayKeepsTheAdvancedDueDate(): void {
		// A biweekly bill anchored on today's grid was paid TODAY: next due
		// advanced to D+14. The consistency check used to recompute "on or
		// after today", which is today itself — snapping the bill back onto
		// the just-paid occurrence, which auto-pay then paid again.
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn(...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));

		$today = new \DateTime();
		$bill = $this->makeBill([
			'frequency' => 'biweekly',
			'dueDay' => (int) $today->format('N'),
			'nextDueDate' => (clone $today)->modify('+14 days')->format('Y-m-d'),
			'lastPaidDate' => $today->format('Y-m-d'),
		]);
		$bill->setStartDate((clone $today)->modify('-28 days')->format('Y-m-d'));
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['name' => 'Renamed']);

		$this->assertNotNull($captured);
		$this->assertArrayNotHasKey('next_due_date', $captured, 'an edit on the paid day must not snap next due back to the paid occurrence');
	}

	public function testUpdateKeepsAnOverdueDueDate(): void {
		// Overdue anchored bill: stored due 3 days ago, previous occurrence
		// paid one period earlier. The overdue date is real, unpaid state —
		// an unrelated edit must not silently snap it into the future.
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn(...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));

		$today = new \DateTime();
		$overdue = (clone $today)->modify('-3 days')->format('Y-m-d');
		$bill = $this->makeBill([
			'frequency' => 'biweekly',
			'dueDay' => (int) (new \DateTime($overdue))->format('N'),
			'nextDueDate' => $overdue,
			'lastPaidDate' => (clone $today)->modify('-17 days')->format('Y-m-d'),
		]);
		$bill->setStartDate((clone $today)->modify('-31 days')->format('Y-m-d'));
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['name' => 'Renamed']);

		$this->assertNotNull($captured);
		$this->assertArrayNotHasKey('next_due_date', $captured, 'an unrelated edit must not un-overdue the bill');
	}

	public function testUpdateMaterialEditSpendsTheUndoSnapshot(): void {
		// The snapshot would restore the OLD amount/schedule over a deliberate
		// edit — a material change spends it.
		$bill = $this->makeBill();
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-06-15');

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['amount' => 20.0]);

		$this->assertNotNull($captured);
		$this->assertArrayHasKey('paid_undo_state', $captured);
		$this->assertNull($captured['paid_undo_state']);
	}

	public function testUpdateRenameKeepsTheUndoSnapshot(): void {
		$bill = $this->makeBill();
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-06-15');

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		// A rename plus an unchanged material value: neither spends the snapshot
		$this->service->update(1, 'user1', ['name' => 'Renamed', 'amount' => 15.99]);

		$this->assertNotNull($captured);
		$this->assertArrayNotHasKey('paid_undo_state', $captured);
	}

	public function testSkipPaymentSpendsTheUndoSnapshot(): void {
		// Skip moves next_due_date; a snapshot restored afterwards would bring
		// back a pre-skip date.
		$bill = $this->makeBill();
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->skipPayment(1, 'user1');

		$this->assertNull($result['bill']->getPaidUndoState());
	}

	public function testMarkUnpaidDeletesThePlaceholderOnlyWhileStillScheduled(): void {
		// The snapshot's placeholder may have materialised into a real ledger
		// row since the payment — the revert asks for the scheduled-only
		// delete and completes either way.
		$bill = $this->makeBill(['nextDueDate' => '2099-07-15', 'lastPaidDate' => '2099-06-15']);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot([
			'createdTransactionIds' => [55],
			'scheduledTransactionIds' => [56],
		]));
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$calls = [];
		$this->transactionService->method('deleteAsAccountOwner')
			->willReturnCallback(function (int $id, bool $onlyIfScheduled = false) use (&$calls) {
				$calls[] = [$id, $onlyIfScheduled];
				// The placeholder became 'cleared' — left alone
				return !$onlyIfScheduled;
			});

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertSame([[55, false], [56, true]], $calls);
		$this->assertSame('2099-06-15', $restored->getNextDueDate(), 'the revert still completes');
		$this->assertNull($restored->getPaidUndoState());
	}

	public function testMarkPaidWithLinkedTransactionSnapshotsTheLink(): void {
		// Link-existing / import-match payments create nothing — the snapshot
		// records the LINKED id so the revert can unlink it (never delete it).
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$this->transactionService->expects($this->once())
			->method('update')
			->with(77, 'user1', ['billId' => 1]);

		$result = $this->service->markPaid(1, 'user1', null, false, 77);

		$decoded = json_decode($result['bill']->getPaidUndoState(), true);
		$this->assertSame(77, $decoded['linkedTransactionId']);
		$this->assertSame([], $decoded['createdTransactionIds']);
	}

	public function testMarkUnpaidUnlinksALinkedTransactionInsteadOfDeletingIt(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-07-15', 'lastPaidDate' => '2099-06-15']);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot([
			'createdTransactionIds' => [],
			'linkedTransactionId' => 77,
		]));
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');
		$this->transactionService->expects($this->once())
			->method('unlinkBillAsAccountOwner')
			->with(77);

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertSame('2099-06-15', $restored->getNextDueDate());
	}

	public function testMarkUnpaidWithNonArrayTransactionIdsThrows(): void {
		// A corrupt blob must fail like a missing snapshot, not TypeError past
		// the controller's catches.
		$bill = $this->makeBill();
		$bill->setPaidUndoState(json_encode([
			'previousState' => ['nextDueDate' => '2099-06-15', 'isActive' => true],
			'createdTransactionIds' => 'bogus',
		]));
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('This bill has no recorded payment to undo');

		$this->service->markUnpaid(1, 'user1');
	}

	public function testMarkPaidStillSucceedsWhenSnapshotPersistFails(): void {
		// The payment is committed by the first mapper update; a DB error on
		// the snapshot write may only cost the durable undo, never the payment.
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$calls = 0;
		$this->mapper->method('update')->willReturnCallback(function (Bill $b) use (&$calls) {
			if (++$calls === 2) {
				throw new \Exception('server has gone away');
			}
			return $b;
		});
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertSame('2099-07-15', $result['bill']->getNextDueDate());
		$this->assertSame(date('Y-m-d'), $result['bill']->getLastPaidDate());
		$this->assertNull($result['bill']->getPaidUndoState(), 'a snapshot that failed to persist must not be advertised');
	}
}
