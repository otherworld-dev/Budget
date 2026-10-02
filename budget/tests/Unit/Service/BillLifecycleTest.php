<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Bills against the real schedule, on a fixed day (2026-09-28).
 *
 * next_due_date is the first occurrence not yet paid or skipped. An edit
 * that leaves the schedule alone leaves it alone; a schedule change moves
 * the pending occurrence within its own month; the pre-booked row follows
 * every edit; and paying settles exactly the occurrence the page showed.
 */
class BillLifecycleTest extends TestCase {
	private const TODAY = '2026-09-28';

	private BillService $service;
	private BillMapper $mapper;
	private TransactionService $transactions;
	private ?Bill $stored = null;
	/** @var string[] */
	private array $calls = [];
	private bool $bookingFails = false;

	protected function setUp(): void {
		$this->mapper = $this->createMock(BillMapper::class);
		$this->mapper->method('insert')->willReturnCallback(function (Bill $bill) {
			$bill->setId(1);
			$this->stored = $bill;
			return $bill;
		});
		$this->mapper->method('update')->willReturnCallback(function (Bill $bill) {
			$this->stored = $bill;
			return $bill;
		});
		$this->mapper->method('find')->willReturnCallback(fn () => clone $this->stored);
		$this->mapper->method('updateFields')->willReturnCallback(function (int $id, string $user, array $fields) {
			foreach ($fields as $column => $value) {
				$this->stored->{'set' . str_replace('_', '', ucwords($column, '_'))}($value);
			}
		});

		$this->transactions = $this->createMock(TransactionService::class);
		$this->transactions->method('createFromBill')->willReturnCallback(function (string $user, Bill $bill, ?string $date = null) {
			if ($this->bookingFails) {
				throw new \RuntimeException('account gone');
			}
			$this->calls[] = 'create:' . ($date ?? 'next:' . $bill->getNextDueDate());
			$tx = new Transaction();
			$tx->setId(500 + count($this->calls));
			return $tx;
		});
		$this->transactions->method('deleteScheduledBillTransactions')->willReturnCallback(function (int $billId) {
			$this->calls[] = 'drop-pending';
		});
		$this->transactions->method('clearScheduledBillTransaction')->willReturn(null);

		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->service = new BillService(
			$this->mapper,
			new FrequencyCalculator(),
			$this->createMock(RecurringBillDetector::class),
			$this->transactions,
			$l,
			$this->createMock(AccountMapper::class),
			$this->createMock(CurrencyConversionService::class),
			$this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class),
			null,
			$this->createMock(RecurringIncomeMapper::class),
			null,
			$clock,
		);
	}

	private function bill(array $fields): Bill {
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('user1');
		$bill->setName('Rent');
		$bill->setAmount(800.0);
		$bill->setFrequency($fields['frequency'] ?? 'monthly');
		$bill->setDueDay(array_key_exists('dueDay', $fields) ? $fields['dueDay'] : 15);
		$bill->setDueMonth($fields['dueMonth'] ?? null);
		$bill->setIsActive($fields['isActive'] ?? true);
		$bill->setAccountId(array_key_exists('accountId', $fields) ? $fields['accountId'] : 3);
		$bill->setNextDueDate(array_key_exists('nextDueDate', $fields) ? $fields['nextDueDate'] : '2026-10-15');
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? null);
		$bill->setStartDate($fields['startDate'] ?? null);
		$bill->setEndDate($fields['endDate'] ?? null);
		$bill->setRemainingPayments($fields['remainingPayments'] ?? null);
		$bill->setAutoPayEnabled(false);
		$bill->setAutoPayFailed(false);
		$bill->setIsTransfer(false);
		if (array_key_exists('createTransaction', $fields)) {
			$bill->setCreateTransaction($fields['createTransaction']);
		}
		$this->stored = $bill;
		return $bill;
	}

	private function create(string $frequency, ?int $dueDay, array $extra = []): Bill {
		return $this->service->create(
			'user1', 'Rent', 800.0, $frequency, $dueDay, $extra['dueMonth'] ?? null, null,
			$extra['accountId'] ?? 3, null, null, null, null, null, $extra['createTransaction'] ?? false,
			startDate: $extra['startDate'] ?? null, endDate: $extra['endDate'] ?? null,
		);
	}

	// ── create ──────────────────────────────────────────────────────

	public function testAOneTimeBillNeedsItsDate(): void {
		// Without one it was put on 1 January next year (#399)
		$this->expectException(\InvalidArgumentException::class);
		$this->create('one-time', null);
	}

	public function testABillDueTodayIsDueToday(): void {
		$this->assertSame(self::TODAY, $this->create('monthly', 28)->getNextDueDate());
	}

	public function testAFutureStartDateOffTheScheduleStartsAtTheNextOccurrence(): void {
		// The start date itself became the first due date, a day the
		// schedule never falls on
		$this->assertSame('2026-12-15', $this->create('monthly', 15, ['startDate' => '2026-11-20'])->getNextDueDate());
	}

	public function testABillEndingBeforeItsFirstDueDateIsNeverActive(): void {
		$bill = $this->create('monthly', 15, ['endDate' => '2026-10-01']);

		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
	}

	// ── edit ────────────────────────────────────────────────────────

	public function testAnEditThatKeepsTheScheduleKeepsAPaidAheadDate(): void {
		// Paid early, the bill is due again in November. Renaming it put the
		// due date back to October, the occurrence already paid
		$this->bill(['nextDueDate' => '2026-11-15', 'lastPaidDate' => '2026-09-20']);

		$bill = $this->service->update(1, 'user1', ['name' => 'Rent flat', 'frequency' => 'monthly', 'dueDay' => 15]);

		$this->assertSame('2026-11-15', $bill->getNextDueDate());
	}

	public function testAnEditThatKeepsTheScheduleKeepsASkip(): void {
		$this->bill(['nextDueDate' => '2026-11-15']);

		$this->assertSame('2026-11-15', $this->service->update(1, 'user1', ['amount' => 820.0])->getNextDueDate());
	}

	public function testChangingTheDayMovesThePendingOccurrenceWithinItsMonth(): void {
		// Recalculating from today brought back the occurrence already paid
		$this->bill(['nextDueDate' => '2026-11-15', 'lastPaidDate' => '2026-10-14']);

		$this->assertSame('2026-11-25', $this->service->update(1, 'user1', ['dueDay' => 25])->getNextDueDate());
	}

	public function testAnEditRebuildsThePreBookedRow(): void {
		// The row kept the old amount, account or date, and Mark Paid then
		// recorded the stale one
		$this->bill(['nextDueDate' => '2026-10-15']);

		$this->service->update(1, 'user1', ['amount' => 820.0]);

		$this->assertSame(['drop-pending', 'create:next:2026-10-15'], $this->calls);
	}

	public function testANotesEditLeavesThePreBookedRowAlone(): void {
		$this->bill(['nextDueDate' => '2026-10-15']);

		$this->service->update(1, 'user1', ['notes' => 'Ring the landlord']);

		$this->assertSame([], $this->calls);
	}

	public function testAnEditWithPreBookingOffOnlyRemovesTheRow(): void {
		$this->bill(['nextDueDate' => '2026-10-15', 'createTransaction' => false]);

		$this->service->update(1, 'user1', ['amount' => 820.0]);

		$this->assertSame(['drop-pending'], $this->calls);
	}

	public function testMovingTheEndDateBeforeTheNextDueDateEndsTheBill(): void {
		$this->bill(['nextDueDate' => '2026-10-15']);

		$bill = $this->service->update(1, 'user1', ['endDate' => '2026-10-01']);

		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
	}

	public function testExtendingAnEndedBillBringsItBack(): void {
		// It stayed inactive whatever was changed
		$this->bill(['isActive' => false, 'nextDueDate' => null, 'endDate' => '2026-09-01', 'lastPaidDate' => '2026-08-15']);

		$bill = $this->service->update(1, 'user1', ['endDate' => '2027-12-31']);

		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-10-15', $bill->getNextDueDate());
	}

	public function testPausingAndResumingABill(): void {
		$this->bill(['nextDueDate' => '2026-10-15']);
		$this->assertFalse($this->service->update(1, 'user1', ['isActive' => false])->getIsActive());

		$bill = $this->service->update(1, 'user1', ['isActive' => true]);
		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-10-15', $bill->getNextDueDate());
	}

	public function testSwitchingToOneTimeNeedsADate(): void {
		$this->bill([]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->update(1, 'user1', ['frequency' => 'one-time']);
	}

	// ── pay ─────────────────────────────────────────────────────────

	public function testAnInactiveBillCannotBePaidAgain(): void {
		// A paid one-time bill recorded another payment on a second click
		$this->bill(['frequency' => 'one-time', 'isActive' => false, 'nextDueDate' => null, 'startDate' => '2026-09-01']);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->markPaid(1, 'user1');
	}

	public function testAStaleOrDoubleClickIsRefused(): void {
		$this->bill(['nextDueDate' => '2026-11-15']);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->markPaid(1, 'user1', null, true, null, '2026-10-15');
	}

	public function testPayingSettlesExactlyTheOccurrenceShown(): void {
		$this->bill(['nextDueDate' => '2026-08-15']);

		$result = $this->service->markPaid(1, 'user1', self::TODAY, false, null, '2026-08-15');

		$this->assertSame('2026-09-15', $result['bill']->getNextDueDate());
	}

	public function testUndoBringsTheRowBackEvenAfterALink(): void {
		// Linking a bank row removed the pre-booked one, and undo never put
		// it back
		$this->bill(['nextDueDate' => '2026-10-15']);
		$this->service->markPaid(1, 'user1', self::TODAY, false, 77);
		$this->calls = [];

		$this->service->markUnpaid(1, 'user1');

		$this->assertContains('create:next:2026-10-15', $this->calls);
	}

	public function testAutoPayThatRecordsNothingIsRevertedAndSwitchedOff(): void {
		// Auto-pay reported success and moved the bill on while no
		// transaction was booked
		$bill = $this->bill(['nextDueDate' => '2026-09-15']);
		$bill->setAutoPayEnabled(true);
		$this->bookingFails = true;

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertFalse($result['success']);
		$this->assertSame('2026-09-15', $this->stored->getNextDueDate());
		$this->assertFalse($this->stored->getAutoPayEnabled());
	}

	public function testPayingABillWithdrawsItsReminders(): void {
		// A reminder or overdue notice stayed up after the bill was paid
		$notifications = $this->createMock(\OCP\Notification\IManager::class);
		$notifications->method('createNotification')->willReturnCallback(fn () => $this->createConfiguredMock(
			\OCP\Notification\INotification::class, []
		));
		$notifications->expects($this->once())->method('markProcessed');
		$service = $this->serviceWith($notifications);
		$this->bill(['nextDueDate' => '2026-09-15']);

		$service->markPaid(1, 'user1', self::TODAY, false);
	}

	private function serviceWith(\OCP\Notification\IManager $notifications): BillService {
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return new BillService(
			$this->mapper, new FrequencyCalculator(), $this->createMock(RecurringBillDetector::class),
			$this->transactions, $l, $this->createMock(AccountMapper::class),
			$this->createMock(CurrencyConversionService::class), $this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class), $this->createMock(DismissedSuggestionMapper::class),
			null, $this->createMock(RecurringIncomeMapper::class), null, $clock, $notifications,
		);
	}

	public function testUndoDoesNotBookARowForABillThatStoppedPreBooking(): void {
		$this->bill(['nextDueDate' => '2026-10-15']);
		$this->service->markPaid(1, 'user1', self::TODAY, true);
		$this->stored->setCreateTransaction(false);
		$this->calls = [];

		$this->service->markUnpaid(1, 'user1');

		$this->assertSame([], array_filter($this->calls, fn ($c) => str_starts_with($c, 'create:')));
	}
}
