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
	/** Runs before each lookup of the bill: another process changing it in between */
	private ?\Closure $beforeFind = null;

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
		$this->mapper->method('find')->willReturnCallback(function () {
			if ($this->beforeFind !== null) {
				($this->beforeFind)();
			}
			return clone $this->stored;
		});
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

	// ── summary ─────────────────────────────────────────────────────

	public function testTheSummaryCountsAnOccurrenceStillOwedAsOverdue(): void {
		// Paid this month for August, September's occurrence was still owed
		// but the card counted the bill as paid, never overdue
		$late = $this->bill(['nextDueDate' => '2026-09-15', 'lastPaidDate' => '2026-09-05']);
		$this->mapper->method('findActive')->willReturn([$late]);
		$this->mapper->method('findByType')->willReturn([$late]);

		$summary = $this->service->getMonthlySummary('user1');

		$this->assertSame(1, $summary['overdue']);
	}

	public function testTheSummaryCountsAOneTimeBillPaidThisMonth(): void {
		// Paying it switched it off, which took it out of the count
		$recurring = $this->bill(['nextDueDate' => '2026-10-15', 'lastPaidDate' => '2026-09-14']);
		$paidInvoice = clone $recurring;
		$paidInvoice->setId(2);
		$paidInvoice->setFrequency('one-time');
		$paidInvoice->setIsActive(false);
		$paidInvoice->setNextDueDate(null);
		$paidInvoice->setLastPaidDate('2026-09-20');
		$this->mapper->method('findActive')->willReturn([$recurring]);
		$this->mapper->method('findByType')->willReturn([$recurring, $paidInvoice]);

		$this->assertSame(2, $this->service->getMonthlySummary('user1')['paidThisMonth']);
	}

	public function testTheMonthsStatusFollowsTheOccurrence(): void {
		// The listed date is the bill's next due date, which is unpaid by
		// definition; a payment earlier in the month for an older occurrence
		// read it as paid
		$bill = $this->bill(['nextDueDate' => '2026-09-15', 'lastPaidDate' => '2026-09-05']);
		$this->mapper->method('findDueInRange')->willReturn([$bill]);

		$status = $this->service->getBillStatusForMonth('user1', '2026-09');

		$this->assertFalse($status[0]['isPaid']);
		$this->assertTrue($status[0]['isOverdue']);
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
		$this->transactions->method('findTransaction')->willReturn($this->bankRow(77, 3, '2026-10-14'));
		$this->transactions->method('linkBillAsAccountOwner')->willReturnCallback(fn () => $this->bankRow(77, 3, '2026-10-14'));
		$this->service->markPaid(1, 'user1', self::TODAY, false, 77);
		$this->calls = [];

		$this->service->markUnpaid(1, 'user1');

		$this->assertContains('create:next:2026-10-15', $this->calls);
	}

	/**
	 * Paying an undated one-time bill keeps its due date as its start date
	 * (#333). Mark Unpaid left that behind, so a date the server had once
	 * made up (1 January next year) came back as if the user had entered
	 * it (#399 review, F69).
	 */
	public function testUnpayingPutsTheStartDateBack(): void {
		$this->bill(['frequency' => 'one-time', 'dueDay' => null, 'nextDueDate' => '2027-01-01', 'startDate' => null]);
		$this->service->markPaid(1, 'user1', self::TODAY, false);
		$this->assertSame('2027-01-01', $this->stored->getStartDate());

		$this->service->markUnpaid(1, 'user1');

		$this->assertNull($this->stored->getStartDate());
		$this->assertSame('2027-01-01', $this->stored->getNextDueDate());
	}

	private function bankRow(int $id, int $accountId, string $date): Transaction {
		$row = new Transaction();
		$row->setId($id);
		$row->setAccountId($accountId);
		$row->setDate($date);
		$row->setAmount(800.0);
		$row->setType('debit');
		$row->setIsSplit(false);
		return $row;
	}

	public function testLinkingABankRowPaysTheBillOnTheRowsDate(): void {
		// It was dated the day the user clicked, so the card listed the
		// payment as unrecorded and offered to book it again
		$this->bill(['nextDueDate' => '2026-09-15']);
		$this->transactions->method('findTransaction')->willReturn($this->bankRow(77, 3, '2026-09-14'));
		$this->transactions->expects($this->once())->method('linkBillAsAccountOwner')->with(77)
			->willReturnCallback(fn () => $this->bankRow(77, 3, '2026-09-14'));

		$result = $this->service->markPaid(1, 'user1', null, false, 77);

		$this->assertSame('2026-09-14', $result['bill']->getLastPaidDate());
		$this->assertSame('2026-10-15', $result['bill']->getNextDueDate());
		$this->assertTrue($result['paymentTransactionRecorded']);
	}

	public function testABillWithNoAccountCanBePaidByLinkingARow(): void {
		// Import auto-match marked it paid but never linked the row, and the
		// card then offered to book the payment a second time
		$this->bill(['nextDueDate' => '2026-09-15', 'accountId' => null]);
		$this->transactions->method('findTransaction')->willReturn($this->bankRow(77, 3, '2026-09-14'));
		$this->transactions->expects($this->once())->method('linkBillAsAccountOwner')
			->willReturnCallback(fn () => $this->bankRow(77, 3, '2026-09-14'));

		$this->service->markPaid(1, 'user1', null, false, 77);
	}

	public function testALinkThatFailsLeavesTheBillWhereItWas(): void {
		// The failure was only logged: the pre-booked row was already gone
		// and the bill moved on with nothing linked
		$this->bill(['nextDueDate' => '2026-09-15']);
		$this->transactions->method('findTransaction')->willReturn($this->bankRow(77, 3, '2026-09-14'));
		$this->transactions->method('linkBillAsAccountOwner')->willThrowException(new \InvalidArgumentException('This transaction already pays another bill'));

		try {
			$this->service->markPaid(1, 'user1', null, false, 77);
			$this->fail('The failed link was swallowed');
		} catch (\InvalidArgumentException $e) {
		}

		$this->assertSame('2026-09-15', $this->stored->getNextDueDate());
		$this->assertNotContains('drop-pending', $this->calls);
	}

	public function testARowFromAnotherAccountIsNotLinked(): void {
		// The id comes from the browser: it could name anyone's transaction
		$this->bill(['nextDueDate' => '2026-09-15']);
		$this->transactions->method('findTransaction')->willReturn($this->bankRow(77, 99, '2026-09-14'));
		$this->transactions->expects($this->never())->method('linkBillAsAccountOwner');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->markPaid(1, 'user1', null, false, 77);
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

	public function testPayingAYearlyBillWithNoDayOrMonthKeepsItsStartDate(): void {
		// Created due 14 March 2027 from its start date; Mark Paid moved it
		// to 1 January 2028
		$bill = $this->create('yearly', null, ['startDate' => '2027-03-14']);
		$this->assertSame('2027-03-14', $bill->getNextDueDate());

		$this->service->markPaid(1, 'user1', self::TODAY, false);

		$this->assertSame('2028-03-14', $this->stored->getNextDueDate());
	}

	public function testSkippingAMonthlyBillWithNoDayKeepsItsStartDay(): void {
		// Started on the 20th with no day set: Skip moved it to the 1st
		$this->bill(['dueDay' => null, 'startDate' => '2026-08-20', 'nextDueDate' => '2026-10-20']);

		$this->service->skipPayment(1, 'user1');

		$this->assertSame('2026-11-20', $this->stored->getNextDueDate());
	}

	public function testPayingADateSavedBefore30SettlesItsMonth(): void {
		// 2.54.0 saved a bill with no day on the 1st. Paying 1 October moved
		// it to 20 October, so October was paid twice
		$this->bill(['dueDay' => null, 'startDate' => '2026-03-20', 'nextDueDate' => '2026-10-01']);

		$this->service->markPaid(1, 'user1', self::TODAY, false);

		$this->assertSame('2026-11-20', $this->stored->getNextDueDate());
	}

	public function testSkippingADateSavedBefore30SkipsItsMonth(): void {
		$this->bill(['dueDay' => null, 'startDate' => '2026-03-20', 'nextDueDate' => '2026-10-01']);

		$this->service->skipPayment(1, 'user1');

		$this->assertSame('2026-11-20', $this->stored->getNextDueDate());
	}

	public function testAutoPayPaysAMonthSavedBefore30Once(): void {
		// Auto-pay booked 1 September and 20 September for one monthly bill
		$bill = $this->bill(['dueDay' => null, 'startDate' => '2026-03-20', 'nextDueDate' => '2026-09-01']);
		$bill->setAutoPayEnabled(true);

		$result = $this->service->processAutoPay(1, 'user1');

		$payments = array_values(array_filter($this->calls, fn (string $c) => str_starts_with($c, 'create:20')));
		$this->assertSame(['create:2026-09-01'], $payments);
		$this->assertSame(1, $result['count']);
		$this->assertSame('2026-10-20', $this->stored->getNextDueDate());
	}

	public function testAutoPayCatchesUpEveryOwedOccurrenceOnItsOwnDate(): void {
		// A weekly bill three weeks behind paid one occurrence per run, every
		// row dated the day of the run
		$bill = $this->bill(['frequency' => 'weekly', 'dueDay' => 1, 'nextDueDate' => '2026-09-07']);
		$bill->setAutoPayEnabled(true);

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertTrue($result['success']);
		$this->assertSame(4, $result['count']);
		$payments = array_values(array_filter($this->calls, fn (string $c) => str_starts_with($c, 'create:20')));
		$this->assertSame(['create:2026-09-07', 'create:2026-09-14', 'create:2026-09-21', 'create:2026-09-28'], $payments);
		$this->assertSame('2026-10-05', $this->stored->getNextDueDate());
		$this->assertSame('2026-09-28', $this->stored->getLastPaidDate());
	}

	public function testAutoPayLeavesABillAnotherRunHasAlreadyPaid(): void {
		// Two runs at once (a forced run during cron): the second found the
		// bill already paid by the first and paid the next occurrence early
		$bill = $this->bill(['nextDueDate' => '2026-10-15']);
		$bill->setAutoPayEnabled(true);

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertFalse($result['success']);
		$this->assertFalse($result['disabled']);
		$this->assertSame([], array_filter($this->calls, fn (string $c) => str_starts_with($c, 'create:')));
		$this->assertSame('2026-10-15', $this->stored->getNextDueDate());
		$this->assertTrue($this->stored->getAutoPayEnabled());
	}

	public function testAutoPayRacedByAnotherRunIsNotAFailure(): void {
		// The other run paid 15 September between this run's look and its
		// payment: "already recorded" switched auto-pay off and told the user
		// it had failed
		$bill = $this->bill(['nextDueDate' => '2026-09-15']);
		$bill->setAutoPayEnabled(true);
		$finds = 0;
		$this->beforeFind = function () use (&$finds) {
			if (++$finds === 2) {
				$this->stored->setNextDueDate('2026-10-15');
			}
		};

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertFalse($result['success']);
		$this->assertFalse($result['disabled']);
		$this->assertTrue($this->stored->getAutoPayEnabled());
		$this->assertFalse($this->stored->getAutoPayFailed());
	}

	public function testAutoPayCatchUpLinksTheBankRowsAlreadyThere(): void {
		// Three of the four weeks auto-pay fell behind on were already in the
		// account from a statement: it booked four more rows beside them
		$bill = $this->bill(['frequency' => 'weekly', 'dueDay' => 1, 'nextDueDate' => '2026-09-07']);
		$bill->setAutoPayEnabled(true);
		$bill->setAutoDetectPattern('RENT');
		$rows = [];
		foreach (['2026-09-07', '2026-09-15', '2026-09-21'] as $i => $date) {
			$rows[80 + $i] = $this->bankRow(80 + $i, 3, $date);
			$rows[80 + $i]->setDescription('RENT PAYMENT');
		}
		$this->transactions->method('findUnclaimedDebits')->willReturnCallback(
			fn (int $account, string $date, int $days) => array_values(array_filter(
				$rows,
				fn (Transaction $row) => $row->getBillId() === null && abs(strtotime($row->getDate()) - strtotime($date)) <= $days * 86400
			))
		);
		$this->transactions->method('findTransaction')->willReturnCallback(fn (int $id) => $rows[$id] ?? null);
		$this->transactions->method('linkBillAsAccountOwner')->willReturnCallback(function (int $id, Bill $bill) use ($rows) {
			$rows[$id]->setBillId($bill->getId());
			return $rows[$id];
		});

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertTrue($result['success']);
		$this->assertSame(4, $result['count']);
		$payments = array_values(array_filter($this->calls, fn (string $c) => str_starts_with($c, 'create:20')));
		$this->assertSame(['create:2026-09-28'], $payments, 'Only the week with no bank row is booked');
		$this->assertSame([1, 1, 1], array_map(fn (Transaction $row) => $row->getBillId(), array_values($rows)));
		$this->assertSame('2026-10-05', $this->stored->getNextDueDate());
	}

	public function testAutoPayCatchUpLeavesARowAnotherBillPaysAlone(): void {
		$bill = $this->bill(['frequency' => 'weekly', 'dueDay' => 1, 'nextDueDate' => '2026-09-28']);
		$bill->setAutoPayEnabled(true);
		$bill->setAutoDetectPattern('RENT');
		$row = $this->bankRow(80, 3, '2026-09-28');
		$row->setDescription('RENT PAYMENT');
		$row->setBillId(44);
		$this->transactions->method('findUnclaimedDebits')->willReturn([$row]);
		$this->transactions->expects($this->never())->method('linkBillAsAccountOwner');

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertTrue($result['success']);
		$this->assertSame(['create:2026-09-28'], array_values(array_filter($this->calls, fn (string $c) => str_starts_with($c, 'create:20'))));
	}

	public function testAutoPayCatchUpStopsAtItsCap(): void {
		// A daily bill a year behind books at most 60 rows in one run
		$bill = $this->bill(['frequency' => 'daily', 'dueDay' => null, 'nextDueDate' => '2025-09-01']);
		$bill->setAutoPayEnabled(true);

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertSame(60, $result['count']);
		$this->assertSame('2025-10-31', $this->stored->getNextDueDate());
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
