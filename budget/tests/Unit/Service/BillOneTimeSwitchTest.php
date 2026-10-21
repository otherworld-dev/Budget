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
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Switching a paid bill to One-Time keeps it paid (#399).
 *
 * A one-time bill's date is its whole schedule, and the switch put the bill
 * on that date as owed whatever had been paid: two CembraPay bills, Custom
 * September and paid on 27 September, were switched to One-Time 28 September,
 * came back Overdue with Mark Paid, and were paid a second time on 15 October.
 * A date the bill has already paid is now settled (see OneTimeSettlement):
 * its last payment, a payment recorded for it or the occurrence its last
 * payment settled is within three days of the date, or within the old
 * schedule's due window and not after that occurrence. An unpaid or skipped
 * date, or one after the occurrence paid, stays due, and later edits leave
 * it due.
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
	/** How many times the bill's reminder and overdue notices were taken down */
	private int $withdrawn = 0;

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
			$this->rows = array_values(array_filter($this->rows, fn (Transaction $row) => $row->getId() !== $id));
			return true;
		});

		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(fn () => $this->createMock(INotification::class));
		$notifications->method('markProcessed')->willReturnCallback(function () {
			$this->withdrawn++;
		});

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
			$notifications,
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

	// ── the occurrence the last payment settled ─────────────────────

	public function testAnOccurrencePaidEarlySwitchedToOneTimeIsCompleted(): void {
		// Due on the 20th, marked paid on the 1st, the day it was entered.
		// Nineteen days is outside the window, but the payment's snapshot
		// says it paid 20 October.
		$bill = $this->bill(['nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-10-01']);
		$this->payment(906, '2026-10-01');
		$bill->setPaidUndoState($this->paidSnapshot('2026-10-01', '2026-10-20', [906]));

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-10-20'));

		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
		$this->assertTrue($bill->canMarkUnpaid(), 'Mark Unpaid still takes that payment back');
	}

	public function testACustomBillPaidEarlySwitchedToOneTimeIsCompleted(): void {
		// Custom September, paid on 1 September for the 28th
		$bill = $this->bill([
			'frequency' => 'custom', 'pattern' => '{"months":[9]}', 'dueDay' => 28,
			'nextDueDate' => '2027-09-28', 'lastPaidDate' => '2026-09-01',
		]);
		$this->payment(907, '2026-09-01');
		$bill->setPaidUndoState($this->paidSnapshot('2026-09-01', '2026-09-28', [907]));

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-09-28'));

		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
	}

	public function testAFinalInvoiceAfterThePaidOccurrenceStaysDue(): void {
		// October paid on the 19th, then cancelled. The final invoice, due 2
		// November, is a payment of its own, though October's is near it.
		$bill = $this->bill(['nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-10-19']);
		$this->payment(908, '2026-10-19');
		$bill->setPaidUndoState($this->paidSnapshot('2026-10-19', '2026-10-20', [908]));

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-11-02'));

		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-11-02', $bill->getNextDueDate());
	}

	public function testAFinalInvoiceAfterThePaidOccurrenceStaysDueWithNoSnapshot(): void {
		// Without a snapshot the occurrence October's payment settled is the
		// one before the next due date
		$this->bill(['nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-10-19']);
		$this->payment(908, '2026-10-19');

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-11-02'));

		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-11-02', $bill->getNextDueDate());
	}

	public function testADateADayAfterThePaidOccurrenceIsStillPaid(): void {
		// Paid on its due date, 20 October; the invoice says the 21st
		$bill = $this->bill(['nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-10-20']);
		$this->payment(909, '2026-10-20');
		$bill->setPaidUndoState($this->paidSnapshot('2026-10-20', '2026-10-20', [909]));

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-10-21'));

		$this->assertFalse($bill->getIsActive());
		$this->assertTrue($bill->canMarkUnpaid());
	}

	// ── a bill the switch left due ──────────────────────────────────

	public function testAWeeklyBillTheSwitchLeftDueStaysDueWhenRenamed(): void {
		// Paid on the 12th for the 13th. A one-off invoice on the 20th is
		// owed, and stays owed when a later edit judges it again: once it is
		// one-time, a payment a week off no longer counts.
		$this->bill(['frequency' => 'weekly', 'dueDay' => 2, 'startDate' => '2026-09-01', 'nextDueDate' => '2026-10-20', 'lastPaidDate' => '2026-10-12']);
		$this->payment(910, '2026-10-12');

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-10-20'));
		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-10-20', $bill->getNextDueDate());

		$bill = $this->service->update(1, 'user1', ['name' => 'Final cleaning invoice']);

		$this->assertTrue($bill->getIsActive(), 'A rename marked the invoice paid');
		$this->assertSame('2026-10-20', $bill->getNextDueDate());
	}

	public function testABiweeklyBillTheSwitchLeftDueStaysDueWhenItsAmountChanges(): void {
		$this->bill(['frequency' => 'biweekly', 'dueDay' => 4, 'startDate' => '2026-09-10', 'nextDueDate' => '2026-10-22', 'lastPaidDate' => '2026-10-08']);
		$this->payment(911, '2026-10-08');

		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-10-20'));
		$this->assertTrue($bill->getIsActive());

		$bill = $this->service->update(1, 'user1', ['amount' => 120.0]);

		$this->assertTrue($bill->getIsActive(), 'An amount edit marked the invoice paid');
		$this->assertSame('2026-10-20', $bill->getNextDueDate());
	}

	public function testABillMarkUnpaidReopenedAfterTheSwitchStaysDueWhenEdited(): void {
		// Weekly, paid on the 5th and the 12th, switched to One-Time on the
		// 13th, which the 12th paid. Mark Unpaid takes that payment back; the
		// 5th, a week before, doesn't pay the 13th on the next edit.
		$bill = $this->bill(['frequency' => 'weekly', 'dueDay' => 2, 'startDate' => '2026-09-01', 'nextDueDate' => '2026-10-20', 'lastPaidDate' => '2026-10-12']);
		$this->payment(912, '2026-10-05');
		$this->payment(913, '2026-10-12');
		$snapshot = json_decode($this->paidSnapshot('2026-10-12', '2026-10-13', [913]), true);
		$snapshot['previousState']['lastPaidDate'] = '2026-10-05';
		$bill->setPaidUndoState(json_encode($snapshot));
		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-10-13'));
		$this->assertFalse($bill->getIsActive(), 'The 12th paid the 13th');
		$bill = $this->service->markUnpaid(1, 'user1');
		$this->assertSame([913], $this->deleted);
		$this->assertSame('2026-10-13', $bill->getNextDueDate());

		$bill = $this->service->update(1, 'user1', ['name' => 'Cleaning, last week']);

		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-10-13', $bill->getNextDueDate());
		$this->assertSame('2026-10-05', $bill->getLastPaidDate());
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

	/** The reporter's Dentakont 4584: due 27 June, paid on 26 May, a month early */
	private function oneTimeBillPaidAMonthEarly(): Bill {
		$bill = $this->bill([
			'frequency' => 'one-time', 'dueDay' => 27, 'dueMonth' => 6, 'startDate' => '2026-06-27',
			'isActive' => false, 'nextDueDate' => null, 'lastPaidDate' => '2026-05-26',
		]);
		$this->payment(920, '2026-05-26');
		$bill->setPaidUndoState($this->paidSnapshot('2026-05-26', '2026-06-27', [920], '2026-06-27'));
		return $bill;
	}

	public function testOtherEditsOfAOneTimeBillPaidAMonthEarlyKeepItPaid(): void {
		// Its payment is nowhere near its date: an edit that judged the date
		// again would reopen it. Amount and account edits keep the payment
		// and the paid state, and spend Mark Unpaid, which would put the old
		// amount back (#365 review).
		foreach ([['amount' => 151.67], ['accountId' => 4]] as $edit) {
			$this->oneTimeBillPaidAMonthEarly();
			$bill = $this->service->update(1, 'user1', $edit);
			$this->assertFalse($bill->getIsActive(), json_encode($edit));
			$this->assertNull($bill->getNextDueDate());
			$this->assertSame('2026-05-26', $bill->getLastPaidDate());
			$this->assertFalse($bill->canMarkUnpaid(), json_encode($edit));
		}

		$this->oneTimeBillPaidAMonthEarly();
		$bill = $this->service->update(1, 'user1', $this->oneTime('2026-06-27'));
		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
		$this->assertSame('2026-05-26', $bill->getLastPaidDate());
		$this->assertTrue($bill->canMarkUnpaid(), 'The same date is no change');
	}

	public function testSwitchingOnAOneTimeBillPaidAMonthEarlyKeepsItPaid(): void {
		// Its snapshot says the payment paid 27 June, however early it was
		$this->oneTimeBillPaidAMonthEarly();

		$bill = $this->service->update(1, 'user1', ['isActive' => true]);

		$this->assertFalse($bill->getIsActive());
		$this->assertNull($bill->getNextDueDate());
		$this->assertTrue($bill->canMarkUnpaid());
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

	// ── reminders ───────────────────────────────────────────────────

	public function testAnEditThatSettlesABillTakesItsNoticesDown(): void {
		// The reopened CembraPay bills sat Overdue for weeks, and their
		// overdue notices stayed up once an edit had settled them
		$this->reopenedOneTimeBill();

		$this->service->update(1, 'user1', ['name' => 'CembraPay Temu September']);

		$this->assertSame(1, $this->withdrawn);
	}

	public function testAnEditThatEndsABillTakesItsNoticesDown(): void {
		$this->bill(['nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-10-19']);

		$bill = $this->service->update(1, 'user1', ['endDate' => '2026-10-31']);

		$this->assertFalse($bill->getIsActive());
		$this->assertSame(1, $this->withdrawn);
	}

	public function testAnEditThatLeavesABillDueKeepsItsNotices(): void {
		$this->bill(['nextDueDate' => '2026-11-20', 'lastPaidDate' => '2026-10-19']);

		$this->service->update(1, 'user1', ['name' => 'Renamed', 'amount' => 160.0]);

		$this->assertSame(0, $this->withdrawn);
	}
}
