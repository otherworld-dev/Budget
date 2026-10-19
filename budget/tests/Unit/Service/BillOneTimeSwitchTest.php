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
 * Switching a paid bill to One-Time keeps it paid (#399).
 *
 * A one-time bill's date is its whole schedule, and the switch put the bill
 * on that date as owed whatever had been paid: two CembraPay bills, Custom
 * September and paid on 27 September, were switched to One-Time 28 September,
 * came back Overdue with Mark Paid, and were paid a second time on 15 October.
 * A date the bill has already paid, by its last payment or a payment
 * recorded for it within the due window, is now settled; an unpaid or
 * skipped one stays due.
 */
class BillOneTimeSwitchTest extends TestCase {
	private const TODAY = '2026-10-19';

	private BillService $service;
	private TransactionService $transactions;
	private ?Bill $stored = null;
	/** @var Transaction[] the bill's recorded (non-scheduled) payments */
	private array $rows = [];
	/** @var string[] */
	private array $calls = [];
	/** @var int[] rows Mark Unpaid deleted */
	private array $deleted = [];

	protected function setUp(): void {
		$mapper = $this->createMock(BillMapper::class);
		$mapper->method('update')->willReturnCallback(function (Bill $bill) {
			$this->stored = $bill;
			return $bill;
		});
		$mapper->method('find')->willReturnCallback(fn () => clone $this->stored);
		$mapper->method('updateFields')->willReturnCallback(function (int $id, string $user, array $fields) {
			foreach ($fields as $column => $value) {
				$this->stored->{'set' . str_replace('_', '', ucwords($column, '_'))}($value);
			}
		});

		$this->transactions = $this->createMock(TransactionService::class);
		$this->transactions->method('findRecordedBillTransactions')->willReturnCallback(fn () => $this->rows);
		$this->transactions->method('createFromBill')->willReturnCallback(function (string $user, Bill $bill, ?string $date = null) {
			$this->calls[] = 'create:' . ($date ?? 'next:' . $bill->getNextDueDate());
			$tx = new Transaction();
			$tx->setId(500 + count($this->calls));
			return $tx;
		});
		$this->transactions->method('deleteScheduledBillTransactions')->willReturnCallback(function () {
			$this->calls[] = 'drop-pending';
		});
		$this->transactions->method('deleteAsAccountOwner')->willReturnCallback(function (int $id) {
			$this->deleted[] = $id;
			return true;
		});

		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->service = new BillService(
			$mapper,
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
		$this->rows = [];
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('user1');
		$bill->setName('CembraPay Temu');
		$bill->setAmount(156.39);
		$bill->setAmountType('fixed');
		$bill->setFrequency($fields['frequency'] ?? 'monthly');
		$bill->setDueDay($fields['dueDay'] ?? 20);
		$bill->setDueMonth($fields['dueMonth'] ?? null);
		$bill->setCustomRecurrencePattern($fields['pattern'] ?? null);
		$bill->setIsActive($fields['isActive'] ?? true);
		$bill->setAccountId(3);
		$bill->setNextDueDate(array_key_exists('nextDueDate', $fields) ? $fields['nextDueDate'] : '2026-11-20');
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? null);
		$bill->setStartDate($fields['startDate'] ?? null);
		$bill->setAutoPayEnabled(false);
		$bill->setAutoPayFailed(false);
		$bill->setIsTransfer(false);
		$bill->setCreateTransaction(true);
		$this->stored = $bill;
		return $bill;
	}

	/** A recorded payment of the bill */
	private function payment(int $id, string $date): Transaction {
		$row = new Transaction();
		$row->setId($id);
		$row->setBillId(1);
		$row->setAccountId(3);
		$row->setDate($date);
		$row->setAmount(156.39);
		$row->setType('debit');
		$row->setStatus('cleared');
		$this->rows[] = $row;
		return $row;
	}

	/**
	 * The undo snapshot Mark Paid leaves behind for a payment on $paidDate
	 * of the occurrence due $occurrence
	 */
	private function paidSnapshot(string $paidDate, string $occurrence, array $createdIds, ?string $startDate = null): string {
		return json_encode([
			'previousState' => [
				'lastPaidDate' => null,
				'nextDueDate' => $occurrence,
				'remainingPayments' => null,
				'isActive' => true,
				'autoPayFailed' => false,
				'amount' => 156.39,
				'startDate' => $startDate,
			],
			'createdTransactionIds' => $createdIds,
			'scheduledTransactionIds' => [],
			'linkedTransactionId' => null,
			'hadScheduledTransaction' => false,
			'paidDate' => $paidDate,
		]);
	}

	/** What the bill form sends for a one-time bill on $date */
	private function oneTime(string $date): array {
		return [
			'frequency' => 'one-time',
			'startDate' => $date,
			'dueDay' => (int)substr($date, 8, 2),
			'dueMonth' => (int)substr($date, 5, 2),
			'customRecurrencePattern' => null,
		];
	}

	/** The reporter's CembraPay bill as it was on 29 September: Custom September, paid on the 27th */
	private function paidCustomSeptemberBill(): Bill {
		$bill = $this->bill([
			'frequency' => 'custom', 'pattern' => '{"months":[9]}', 'dueDay' => 28,
			'nextDueDate' => '2027-09-28', 'lastPaidDate' => '2026-09-27',
		]);
		$this->payment(901, '2026-09-27');
		$bill->setPaidUndoState($this->paidSnapshot('2026-09-27', '2026-09-28', [901]));
		return $bill;
	}

	// ── the switch ──────────────────────────────────────────────────

	public function testPaidCustomSwitchedToOneTimeOnItsPaidDateIsCompleted(): void {
		$this->paidCustomSeptemberBill();

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-09-28'));

		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
		$this->assertSame('2026-09-27', $bill->getLastPaidDate());
		$this->assertSame('2026-09-28', $bill->getStartDate());
		// Mark Unpaid still takes the 27 September payment back
		$this->assertTrue($bill->canMarkUnpaid());
		$snapshot = json_decode((string)$bill->getPaidUndoState(), true);
		$this->assertSame([901], $snapshot['createdTransactionIds']);
		$this->assertSame('2026-09-27', $snapshot['paidDate']);
		// Its pre-booked 2027 row goes, and none is booked for the date it paid
		$this->assertSame(['drop-pending'], $this->calls);
	}

	public function testUnpaidCustomSwitchedToOneTimeStaysDue(): void {
		$this->bill([
			'frequency' => 'custom', 'pattern' => '{"months":[9]}', 'dueDay' => 28,
			'nextDueDate' => '2026-09-28',
		]);

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-09-28'));

		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-09-28', $bill->getNextDueDate());
	}

	public function testMonthlyPaidLastMonthSwitchedToOneTimeNextMonthStaysDue(): void {
		// The October payment doesn't pay a final invoice in November
		$bill = $this->bill(['nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-10-19']);
		$this->payment(902, '2026-10-19');
		$bill->setPaidUndoState($this->paidSnapshot('2026-10-19', '2026-10-20', [902]));

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-11-20'));

		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-11-20', $bill->getNextDueDate());
		$this->assertSame([], $this->calls, 'The row pre-booked for 20 November still fits');
		// Undoing October's payment would put the bill back on 20 October
		$this->assertFalse($bill->canMarkUnpaid());
	}

	public function testMonthlySwitchedToOneTimeOnTheOccurrenceItPaidIsCompleted(): void {
		$bill = $this->bill(['nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-10-19']);
		$this->payment(902, '2026-10-19');
		$bill->setPaidUndoState($this->paidSnapshot('2026-10-19', '2026-10-20', [902]));

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-10-20'));

		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
		$this->assertSame('2026-10-19', $bill->getLastPaidDate());
		$this->assertTrue($bill->canMarkUnpaid());
	}

	public function testASkippedOccurrenceSwitchedToOneTimeStaysDue(): void {
		// Paid in September, October skipped: the next due date is past
		// 20 October, but nothing paid it
		$this->bill(['nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-09-19']);
		$this->payment(903, '2026-09-19');

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-10-20'));

		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-10-20', $bill->getNextDueDate());
	}

	public function testAPaymentMovedToItsBankDateSettlesThatDate(): void {
		// Marked paid on 15 October, its row then moved to the 28 September
		// the bank took it. The click date is too far off; the row isn't.
		$bill = $this->bill([
			'frequency' => 'custom', 'pattern' => '{"months":[9]}', 'dueDay' => 28,
			'nextDueDate' => '2027-09-28', 'lastPaidDate' => '2026-10-15',
		]);
		$this->payment(904, '2026-09-28');
		$bill->setPaidUndoState($this->paidSnapshot('2026-10-15', '2026-09-28', [904]));

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-09-28'));

		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
		$this->assertTrue($bill->canMarkUnpaid(), 'That payment is still the one Mark Unpaid takes back');
	}

	public function testAnOlderPaymentNearTheDateSettlesItButCannotBeUndoneFromALaterOne(): void {
		// Paid on 19 September (a row) and again on 19 October (no row);
		// switched to One-Time 20 September. September's row settles the
		// date, but the snapshot is October's: undoing it would reopen a
		// date its own row paid
		$bill = $this->bill(['nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-10-19']);
		$this->payment(905, '2026-09-19');
		$bill->setPaidUndoState($this->paidSnapshot('2026-10-19', '2026-10-20', []));

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-09-20'));

		$this->assertFalse($bill->getIsActive());
		$this->assertFalse($bill->canMarkUnpaid());
	}

	public function testMarkUnpaidAfterTheSwitchReopensTheBillOnItsNewDate(): void {
		// The snapshot was taken under the Custom schedule, which had no start
		// date: restored as it was, the one-time bill lost its date
		$this->paidCustomSeptemberBill();
		$this->service->update(1, 'user1', $this->oneTime('2026-09-28'));
		$this->calls = [];

		$bill = $this->service->markUnpaid(1, 'user1');

		$this->assertSame([901], $this->deleted);
		$this->assertTrue($bill->getIsActive());
		$this->assertSame('one-time', $bill->getFrequency());
		$this->assertSame('2026-09-28', $bill->getNextDueDate());
		$this->assertSame('2026-09-28', $bill->getStartDate());
		$this->assertNull($bill->getLastPaidDate());
		$this->assertContains('create:next:2026-09-28', $this->calls);
	}

	public function testAnAmountChangedWithTheSwitchStillSpendsTheSnapshot(): void {
		// Mark Unpaid would put the old amount back over the new one
		$this->paidCustomSeptemberBill();

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-09-28') + ['amount' => 151.67]);

		$this->assertFalse($bill->getIsActive());
		$this->assertFalse($bill->canMarkUnpaid());
	}

	// ── a one-time bill the switch already reopened ─────────────────

	/** The CembraPay bill as the switch left it on dev: One-Time, due again on the date it paid */
	private function reopenedOneTimeBill(): Bill {
		$bill = $this->bill([
			'frequency' => 'one-time', 'dueDay' => 28, 'dueMonth' => 9, 'startDate' => '2026-09-28',
			'nextDueDate' => '2026-09-28', 'lastPaidDate' => '2026-09-27',
		]);
		$this->payment(901, '2026-09-27');
		return $bill;
	}

	public function testReopenedOneTimeBillIsSettledByAnyEdit(): void {
		$this->reopenedOneTimeBill();

		$bill = $this->service->update(1, 'user1', ['amount' => 151.67]);

		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
		$this->assertSame('2026-09-27', $bill->getLastPaidDate());
		$this->assertSame(['drop-pending'], $this->calls, 'Its pre-booked row goes with it');
	}

	public function testReopenedOneTimeBillIsSettledByARename(): void {
		$this->reopenedOneTimeBill();

		$bill = $this->service->update(1, 'user1', ['name' => 'CembraPay Temu September']);

		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
	}

	// ── a one-time bill that was paid as one ────────────────────────

	private function paidOneTimeBill(): Bill {
		$bill = $this->bill([
			'frequency' => 'one-time', 'dueDay' => 28, 'dueMonth' => 9, 'startDate' => '2026-09-28',
			'isActive' => false, 'nextDueDate' => null, 'lastPaidDate' => '2026-09-27',
		]);
		$this->payment(901, '2026-09-27');
		$bill->setPaidUndoState($this->paidSnapshot('2026-09-27', '2026-09-28', [901], '2026-09-28'));
		return $bill;
	}

	public function testOtherEditsOfAPaidOneTimeBillKeepItPaid(): void {
		$this->paidOneTimeBill();

		$bill = $this->service->update(1, 'user1', ['amount' => 151.67]);
		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
		$this->assertSame('2026-09-27', $bill->getLastPaidDate());

		$this->paidOneTimeBill();
		$bill = $this->service->update(1, 'user1', ['accountId' => 4]);
		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
		$this->assertSame('2026-09-27', $bill->getLastPaidDate());
		$this->assertNotContains('create:next:2026-09-28', $this->calls, 'Nothing is pre-booked for the date it paid');

		$this->paidOneTimeBill();
		$bill = $this->service->update(1, 'user1', ['startDate' => '2026-09-28']);
		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
		$this->assertSame('2026-09-27', $bill->getLastPaidDate());
		$this->assertTrue($bill->canMarkUnpaid(), 'The same date is no change');
	}

	public function testSwitchingOnAPaidOneTimeBillKeepsItPaid(): void {
		// Turning it back on put the invoice it had paid back on the list as
		// owed. Mark Unpaid is the way to reopen a payment.
		$this->paidOneTimeBill();

		$bill = $this->service->update(1, 'user1', ['isActive' => true]);

		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
		$this->assertTrue($bill->canMarkUnpaid());
	}

	public function testSwitchingOnAnUnpaidOneTimeBillMakesItDue(): void {
		$this->bill([
			'frequency' => 'one-time', 'dueDay' => 28, 'dueMonth' => 9, 'startDate' => '2026-09-28',
			'isActive' => false, 'nextDueDate' => '2026-09-28',
		]);

		$bill = $this->service->update(1, 'user1', ['isActive' => true]);

		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-09-28', $bill->getNextDueDate());
	}
}
