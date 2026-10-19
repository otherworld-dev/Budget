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
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The payment a bill's pending occurrence already has in the ledger (#399).
 *
 * Mark Paid never looked at the bill's own rows: the reporter's CembraPay
 * bills, switched to One-Time and so due again, were paid a second time
 * with one click, and nothing asked. The occurrence's payment is now found
 * first, and Mark Paid asks before booking another.
 */
class BillRecordedPaymentTest extends TestCase {
	private const TODAY = '2026-10-19';

	private BillService $service;
	private TransactionService $transactions;
	private ?Bill $stored = null;
	/** @var Transaction[] the rows of the bill in the ledger, placeholders included */
	private array $rows = [];
	/** @var string[] payments booked */
	private array $booked = [];
	/** @var array<string, int[]> user => accounts they can't write to (every other one they can) */
	private array $unwritable = [];

	protected function setUp(): void {
		$mapper = $this->createMock(BillMapper::class);
		$mapper->method('find')->willReturnCallback(fn () => clone $this->stored);
		$mapper->method('findAll')->willReturnCallback(fn () => [clone $this->stored]);
		$mapper->method('update')->willReturnCallback(function (Bill $bill) {
			foreach (array_keys($bill->getUpdatedFields()) as $property) {
				$this->stored->{'set' . ucfirst($property)}($bill->{'get' . ucfirst($property)}());
			}
			return $bill;
		});
		$write = function (array $fields): void {
			foreach ($fields as $column => $value) {
				$this->stored->{'set' . str_replace('_', '', ucwords($column, '_'))}($value);
			}
		};
		$mapper->method('updateFields')->willReturnCallback(fn (int $id, string $user, array $fields) => $write($fields));
		$mapper->method('claimOccurrence')->willReturnCallback(function (int $id, string $user, string $expected, array $fields) use ($write) {
			if (!$this->stored->getIsActive() || $this->stored->getNextDueDate() !== $expected) {
				return false;
			}
			$write($fields);
			return true;
		});

		$transactions = $this->createMock(TransactionService::class);
		$this->transactions = $transactions;
		$transactions->method('findRecordedBillTransactions')->willReturnCallback(fn () => $this->rows);
		$transactions->method('createFromBill')->willReturnCallback(function (string $user, Bill $bill, ?string $date = null) {
			$this->booked[] = $date ?? 'pre-book';
			$tx = new Transaction();
			$tx->setId(900 + count($this->booked));
			return $tx;
		});

		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('canWrite')->willReturnCallback(
			fn (string $user, string $type, int $id) => !in_array($id, $this->unwritable[$user] ?? [], true)
		);

		$this->service = new BillService(
			$mapper,
			new FrequencyCalculator(),
			$this->createMock(RecurringBillDetector::class),
			$transactions,
			$l,
			$this->createMock(AccountMapper::class),
			$this->createMock(CurrencyConversionService::class),
			$this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class),
			null,
			$this->createMock(RecurringIncomeMapper::class),
			$shares,
			$clock,
		);
	}

	private function bill(array $fields): Bill {
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('user1');
		$bill->setName('CembraPay Temu');
		$bill->setAmount($fields['amount'] ?? 151.67);
		$bill->setFrequency($fields['frequency'] ?? 'monthly');
		$bill->setDueDay($fields['dueDay'] ?? 28);
		$bill->setDueMonth($fields['dueMonth'] ?? null);
		$bill->setIsActive($fields['isActive'] ?? true);
		$bill->setAccountId(array_key_exists('accountId', $fields) ? $fields['accountId'] : 3);
		$bill->setNextDueDate(array_key_exists('nextDueDate', $fields) ? $fields['nextDueDate'] : '2026-10-28');
		$bill->setLastPaidDate(array_key_exists('lastPaidDate', $fields) ? $fields['lastPaidDate'] : '2026-09-28');
		$bill->setStartDate($fields['startDate'] ?? null);
		$bill->setAutoPayEnabled(false);
		$bill->setAutoPayFailed(false);
		$bill->setIsTransfer($fields['isTransfer'] ?? false);
		$bill->setDestinationAccountId($fields['destinationAccountId'] ?? null);
		$bill->setCreateTransaction($fields['createTransaction'] ?? false);
		$bill->setPaidUndoState($fields['paidUndoState'] ?? null);
		$bill->resetUpdatedFields();
		$this->stored = $bill;
		return $bill;
	}

	private function row(int $id, string $date, float $amount = 151.67, array $fields = []): Transaction {
		$row = new Transaction();
		$row->setId($id);
		$row->setBillId(1);
		$row->setAccountId($fields['accountId'] ?? 3);
		$row->setDate($date);
		$row->setAmount($amount);
		$row->setType($fields['type'] ?? 'debit');
		$row->setStatus($fields['status'] ?? 'cleared');
		$row->setNotes('Auto-generated from bill: CembraPay Temu');
		$this->rows[] = $row;
		return $row;
	}

	/** The reporter's CembraPay bill after Mark Unpaid: One-Time 28 September, due again, its 27 September payment still there */
	private function reopenedOneTimeBill(): void {
		$this->bill(['frequency' => 'one-time', 'nextDueDate' => '2026-09-28', 'startDate' => '2026-09-28', 'lastPaidDate' => '2026-09-27']);
		$this->row(1350727, '2026-09-27');
	}

	public function testAReopenedOneTimeBillReportsThePaymentItHas(): void {
		$this->reopenedOneTimeBill();

		$this->assertSame(
			['id' => 1350727, 'date' => '2026-09-27', 'amount' => 151.67],
			$this->service->recordedPaymentForPendingOccurrence(1, 'user1')
		);
	}

	public function testAMonthlyBillSwitchedToOneTimeForNextMonthIsNotFlagged(): void {
		// Paid on 19 October, then switched to One-Time on 20 November for
		// its last payment (the edit spent the snapshot). October's payment
		// isn't November's: offered as "Use that payment", the last payment
		// was never recorded.
		$this->bill(['frequency' => 'one-time', 'dueDay' => 20, 'nextDueDate' => '2026-11-20', 'startDate' => '2026-11-20', 'lastPaidDate' => '2026-10-19']);
		$this->row(500, '2026-10-19');

		$this->assertNull($this->service->recordedPaymentForPendingOccurrence(1, 'user1', '2026-11-20'));
	}

	public function testAOneTimeBillPaidEarlyInItsMonthReportsThatPayment(): void {
		// An invoice due on the 30th, paid when it arrived on the 2nd
		$this->bill(['frequency' => 'one-time', 'dueDay' => 30, 'nextDueDate' => '2026-11-30', 'startDate' => '2026-11-30', 'lastPaidDate' => null]);
		$this->row(500, '2026-11-02');

		$this->assertSame(500, $this->service->recordedPaymentForPendingOccurrence(1, 'user1', '2026-11-30')['id']);
	}

	public function testLatePaymentOfThePreviousOccurrenceIsNotFlagged(): void {
		// Due on the 28th, September paid on the 30th: that payment is
		// September's, not October's
		$this->bill(['lastPaidDate' => '2026-09-30', 'nextDueDate' => '2026-10-28']);
		$this->row(500, '2026-09-30');

		$this->assertNull($this->service->recordedPaymentForPendingOccurrence(1, 'user1'));
	}

	public function testALatePaymentInsideTheNextWindowIsStillThePreviousOccurrences(): void {
		// September's, due on the 28th, paid on 14 October: inside October's
		// window, and no snapshot left to name it (an edit spent it). Taken
		// for October's, "Use that payment" would leave October unpaid.
		$this->bill(['lastPaidDate' => '2026-10-14', 'nextDueDate' => '2026-10-28']);
		$this->row(500, '2026-10-14');

		$this->assertNull($this->service->recordedPaymentForPendingOccurrence(1, 'user1', '2026-10-28'));
	}

	public function testAPaymentSinceTheLastOneNearTheDueDateIsReported(): void {
		// October's payment, entered by hand and put on the bill
		$this->bill(['lastPaidDate' => '2026-09-28', 'nextDueDate' => '2026-10-28']);
		$this->row(500, '2026-09-28');
		$this->row(501, '2026-10-20', 150.0);

		$this->assertSame(['id' => 501, 'date' => '2026-10-20', 'amount' => 150.0], $this->service->recordedPaymentForPendingOccurrence(1, 'user1'));
	}

	public function testAPaymentLongBeforeTheDueDateIsNotTheOccurrences(): void {
		$this->bill(['lastPaidDate' => '2026-08-28', 'nextDueDate' => '2026-10-28']);
		$this->row(500, '2026-09-30');

		$this->assertNull($this->service->recordedPaymentForPendingOccurrence(1, 'user1'));
	}

	public function testAPreBookedRowIsNoPayment(): void {
		$this->bill(['nextDueDate' => '2026-10-28']);
		$this->row(500, '2026-10-28', 151.67, ['status' => 'scheduled']);

		$this->assertNull($this->service->recordedPaymentForPendingOccurrence(1, 'user1'));
	}

	public function testTheLastPaymentsOwnRowIsNotTakenForTheNextOne(): void {
		// October paid early on the 12th, its row moved to the bank's date in
		// November afterwards: it is still October's
		$snapshot = json_encode(['previousState' => ['nextDueDate' => '2026-10-28'], 'createdTransactionIds' => [77],
			'scheduledTransactionIds' => [], 'linkedTransactionId' => null, 'paidDate' => '2026-10-12']);
		$this->bill(['lastPaidDate' => '2026-10-12', 'nextDueDate' => '2026-11-28', 'paidUndoState' => $snapshot]);
		$this->row(77, '2026-11-14');

		$this->assertNull($this->service->recordedPaymentForPendingOccurrence(1, 'user1'));
	}

	public function testATransferReportsItsWithdrawal(): void {
		// Both legs carry the transfer
		$this->bill(['isTransfer' => true, 'destinationAccountId' => 9]);
		$this->row(81, '2026-10-27', 100.0, ['accountId' => 9, 'type' => 'credit']);
		$this->row(80, '2026-10-27', 100.0);

		$this->assertSame(80, $this->service->recordedPaymentForPendingOccurrence(1, 'user1')['id']);
	}

	public function testOnlyARowThePersonAskingCanWriteToIsReported(): void {
		// A bill with no account, shared with bob: its payment sits in an
		// account of the owner's that bob was never given, and Mark Paid
		// told him its date and amount. The owner is still told.
		$this->bill(['accountId' => null]);
		$this->row(500, '2026-10-20', 150.0, ['accountId' => 12]);
		$this->unwritable = ['bob' => [12]];

		$this->assertNull($this->service->recordedPaymentForPendingOccurrence(1, 'user1', '2026-10-28', 'bob'));
		$this->assertSame(500, $this->service->recordedPaymentForPendingOccurrence(1, 'user1', '2026-10-28', 'user1')['id']);
	}

	public function testNothingIsReportedWhenNothingIsDue(): void {
		// A paid one-time bill: Mark Paid refuses it with its own reason
		$this->bill(['frequency' => 'one-time', 'isActive' => false, 'nextDueDate' => null, 'startDate' => '2026-09-28', 'lastPaidDate' => '2026-09-27']);
		$this->row(1350727, '2026-09-27');

		$this->assertNull($this->service->recordedPaymentForPendingOccurrence(1, 'user1'));
	}

	public function testNothingIsReportedForAnOccurrenceTheBillHasMovedPast(): void {
		// A stale page: Mark Paid refuses it as already recorded
		$this->reopenedOneTimeBill();

		$this->assertNull($this->service->recordedPaymentForPendingOccurrence(1, 'user1', '2026-08-28'));
		$this->assertNotNull($this->service->recordedPaymentForPendingOccurrence(1, 'user1', '2026-09-28'));
	}

	public function testUsingThePaymentAlreadyThereBooksNothingAndTheCardStaysQuiet(): void {
		// "Use that payment": marked paid on that payment's date with nothing
		// booked. The bill was last paid on that same day, so by dates alone
		// the card took the row for the earlier payment's and offered to book
		// it again; the snapshot now names the row.
		$this->reopenedOneTimeBill();

		$result = $this->service->markPaid(1, 'user1', '2026-09-27', false, null, '2026-09-28');

		$this->assertSame([], $this->booked);
		$this->assertTrue($result['paymentTransactionRecorded']);
		$this->assertFalse($this->stored->getIsActive());
		$this->assertSame('2026-09-27', $this->stored->getLastPaidDate());
		$this->assertSame([], $this->service->findUnrecordedPayments('user1'));
	}

	public function testUsingTheOccurrencesPaymentOnARecurringBillNamesIt(): void {
		// October's payment, typed in and put on the bill. Marked paid on its
		// date, it was taken for the payment before this one: the snapshot
		// named nothing and the answer said no payment was recorded.
		$this->bill(['lastPaidDate' => '2026-09-28', 'nextDueDate' => '2026-10-28']);
		$this->row(500, '2026-09-28');
		$this->row(501, '2026-10-20', 150.0);

		$result = $this->service->markPaid(1, 'user1', '2026-10-20', false, null, '2026-10-28');

		$this->assertSame([], $this->booked);
		$this->assertTrue($result['paymentTransactionRecorded']);
		$this->assertSame(501, json_decode((string)$this->stored->getPaidUndoState(), true)['usedTransactionId']);
		$this->assertSame('2026-11-28', $this->stored->getNextDueDate());
	}

	public function testUndoingTheUseOfAPaymentLeavesThePaymentAlone(): void {
		// The row was there before: Mark Unpaid neither deletes nor unlinks
		// it, and the bill is due again
		$this->reopenedOneTimeBill();
		$this->service->markPaid(1, 'user1', '2026-09-27', false, null, '2026-09-28');
		$this->transactions->expects($this->never())->method('deleteAsAccountOwner');
		$this->transactions->expects($this->never())->method('unlinkBillAsAccountOwner');

		$this->service->markUnpaid(1, 'user1');

		$this->assertTrue($this->stored->getIsActive());
		$this->assertSame('2026-09-28', $this->stored->getNextDueDate());
	}
}
