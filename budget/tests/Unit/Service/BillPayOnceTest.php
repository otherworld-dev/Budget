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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mark Paid, Skip and auto-pay settle each occurrence once (#399).
 *
 * Each read the bill, checked it was still due and then wrote it: two
 * requests at once both passed the check, and both booked. Live, two or
 * three simultaneous Mark Paid all succeeded and each booked a payment, a
 * second Skip skipped the same month again, and a payment whose booking
 * failed half way left the bill moved on. The occurrence is now claimed
 * with one conditional write before anything is booked.
 *
 * The mapper here behaves as the real one does: claimOccurrence() writes
 * only while the bill is active and due on the expected date, and update()
 * writes only the fields the entity marks changed.
 */
class BillPayOnceTest extends TestCase {
	private const TODAY = '2026-10-19';
	private const ALREADY_RECORDED = 'This payment was already recorded. Reload the page to see the next one.';
	private const ALREADY_SKIPPED = 'This date was already paid or skipped. Reload the page to see the next one.';

	private BillService $service;
	private TransactionService $transactions;
	private ?Bill $stored = null;
	/** @var string[] what reached the ledger, in order */
	private array $calls = [];
	/** Runs once, just before the next claim: another request getting in first */
	private ?\Closure $beforeClaim = null;
	/** Runs as each row is booked, with its date (null for a pre-booked row) */
	private ?\Closure $whileBooking = null;
	/** Thrown when the bill's pre-booked rows are dropped */
	private ?\Throwable $dropFails = null;

	protected function setUp(): void {
		$mapper = $this->createMock(BillMapper::class);
		$mapper->method('find')->willReturnCallback(fn () => clone $this->stored);
		$mapper->method('update')->willReturnCallback(function (Bill $bill) {
			foreach (array_keys($bill->getUpdatedFields()) as $property) {
				$this->stored->{'set' . ucfirst($property)}($bill->{'get' . ucfirst($property)}());
			}
			return $bill;
		});
		$mapper->method('updateFields')->willReturnCallback(fn (int $id, string $user, array $fields) => $this->write($fields));
		$mapper->method('claimOccurrence')->willReturnCallback(function (int $id, string $user, string $expected, array $fields) {
			if ($this->beforeClaim !== null) {
				$other = $this->beforeClaim;
				$this->beforeClaim = null;
				$other();
			}
			if (!$this->stored->getIsActive() || $this->stored->getNextDueDate() !== $expected) {
				return false;
			}
			$this->write($fields);
			return true;
		});

		$this->transactions = $this->createMock(TransactionService::class);
		$this->transactions->method('createFromBill')->willReturnCallback(function (string $user, Bill $bill, ?string $date = null) {
			if ($this->whileBooking !== null) {
				($this->whileBooking)($date);
			}
			$this->calls[] = $date === null ? 'pre-book:' . $bill->getNextDueDate() : 'pay:' . $date;
			$tx = new Transaction();
			$tx->setId(500 + count($this->calls));
			return $tx;
		});
		$this->transactions->method('clearScheduledBillTransaction')->willReturnCallback(function () {
			$this->calls[] = 'clear-pre-booked';
			return null;
		});
		$this->transactions->method('deleteScheduledBillTransactions')->willReturnCallback(function () {
			if ($this->dropFails !== null) {
				throw $this->dropFails;
			}
			$this->calls[] = 'drop-pre-booked';
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

	/** @param array<string, mixed> $fields column => value */
	private function write(array $fields): void {
		foreach ($fields as $column => $value) {
			$this->stored->{'set' . str_replace('_', '', ucwords($column, '_'))}($value);
		}
	}

	private function bill(array $fields = []): Bill {
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('user1');
		$bill->setName('Phone');
		$bill->setAmount(30.0);
		$bill->setFrequency($fields['frequency'] ?? 'monthly');
		$bill->setDueDay(array_key_exists('dueDay', $fields) ? $fields['dueDay'] : 15);
		$bill->setDueMonth(null);
		$bill->setIsActive($fields['isActive'] ?? true);
		$bill->setAccountId(3);
		$bill->setNextDueDate(array_key_exists('nextDueDate', $fields) ? $fields['nextDueDate'] : '2026-10-15');
		$bill->setLastPaidDate(array_key_exists('lastPaidDate', $fields) ? $fields['lastPaidDate'] : '2026-09-15');
		$bill->setStartDate($fields['startDate'] ?? null);
		$bill->setEndDate($fields['endDate'] ?? null);
		$bill->setRemainingPayments($fields['remainingPayments'] ?? null);
		$bill->setAutoPayEnabled($fields['autoPayEnabled'] ?? false);
		$bill->setAutoPayFailed(false);
		$bill->setIsTransfer(false);
		$bill->setCreateTransaction($fields['createTransaction'] ?? true);
		$bill->setPaidUndoState(null);
		$bill->resetUpdatedFields();
		$this->stored = $bill;
		return $bill;
	}

	private function bankRow(int $id): Transaction {
		$row = new Transaction();
		$row->setId($id);
		$row->setAccountId(3);
		$row->setDate('2026-10-14');
		$row->setAmount(30.0);
		$row->setType('debit');
		$row->setIsSplit(false);
		return $row;
	}

	// ── Mark Paid ───────────────────────────────────────────────────

	public function testAPaymentThatLosesTheClaimBooksNothing(): void {
		// Another request paid October between this one's look and its write
		$this->bill();
		$this->beforeClaim = function () {
			$this->stored->setNextDueDate('2026-11-15');
			$this->stored->setLastPaidDate(self::TODAY);
		};

		try {
			$this->service->markPaid(1, 'user1', self::TODAY, true, null, '2026-10-15');
			$this->fail('October was paid a second time');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame(self::ALREADY_RECORDED, $e->getMessage());
		}

		$this->assertSame([], $this->calls, 'Nothing is booked, cleared or dropped');
	}

	public function testTwoSimultaneousPaymentsOfAPreBookingBillBookOnce(): void {
		// Both requests read October as due; the other one claims it first
		$this->bill();
		$this->beforeClaim = fn () => $this->service->markPaid(1, 'user1', self::TODAY, true, null, '2026-10-15');

		try {
			$this->service->markPaid(1, 'user1', self::TODAY, true, null, '2026-10-15');
			$this->fail('The second payment of October went through');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame(self::ALREADY_RECORDED, $e->getMessage());
		}

		$this->assertSame(['clear-pre-booked', 'pay:' . self::TODAY, 'pre-book:2026-11-15'], $this->calls, 'One payment and one row for November');
		$this->assertSame('2026-11-15', $this->stored->getNextDueDate());
	}

	public function testTheClaimSettlesTheOccurrenceBeforeAnythingIsBooked(): void {
		$this->bill(['remainingPayments' => 3]);
		$seen = null;
		$this->whileBooking = function (?string $date) use (&$seen) {
			if ($date !== null) {
				$seen = [$this->stored->getNextDueDate(), $this->stored->getRemainingPayments(), $this->stored->getLastPaidDate()];
			}
		};

		$this->service->markPaid(1, 'user1', self::TODAY, true, null, '2026-10-15');

		$this->assertSame(['2026-11-15', 2, self::TODAY], $seen, 'The bill had moved on while the payment booked');
		$this->assertSame('2026-11-15', $this->stored->getNextDueDate());
		$this->assertSame(self::TODAY, $this->stored->getLastPaidDate());
	}

	public function testThePaymentNeverWritesTheClaimedFieldsAgain(): void {
		// Written again after booking, they would put back a bill another
		// request had moved on in the meantime (paid November, say)
		$this->bill();
		$this->whileBooking = function (?string $date) {
			if ($date === null) {
				$this->stored->setNextDueDate('2026-12-15');
			}
		};

		$this->service->markPaid(1, 'user1', self::TODAY, true, null, '2026-10-15');

		$this->assertSame('2026-12-15', $this->stored->getNextDueDate());
		$this->assertNotNull($this->stored->getPaidUndoState(), 'The snapshot is still written');
	}

	public function testALinkThatFailsAfterTheClaimHandsTheOccurrenceBack(): void {
		$this->bill();
		$this->transactions->method('findTransaction')->willReturn($this->bankRow(77));
		$this->transactions->method('linkBillAsAccountOwner')->willThrowException(new \InvalidArgumentException('already linked'));

		try {
			$this->service->markPaid(1, 'user1', null, false, 77, '2026-10-15');
			$this->fail('The failed link was swallowed');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame('That transaction already pays another bill', $e->getMessage());
		}

		$this->assertTrue($this->stored->getIsActive());
		$this->assertSame('2026-10-15', $this->stored->getNextDueDate());
		$this->assertSame('2026-09-15', $this->stored->getLastPaidDate());
		$this->assertSame([], $this->calls);
	}

	public function testAOneTimeBillWhoseLinkFailsIsDueAgain(): void {
		// The claim switched it off with no date: it gets both back
		$this->bill(['frequency' => 'one-time', 'dueDay' => 28, 'nextDueDate' => '2026-10-28', 'startDate' => '2026-10-28', 'lastPaidDate' => null]);
		$this->transactions->method('findTransaction')->willReturn($this->bankRow(77));
		$this->transactions->method('linkBillAsAccountOwner')->willThrowException(new \InvalidArgumentException('already linked'));

		try {
			$this->service->markPaid(1, 'user1', null, false, 77, '2026-10-28');
			$this->fail('The failed link was swallowed');
		} catch (\InvalidArgumentException $e) {
		}

		$this->assertTrue($this->stored->getIsActive());
		$this->assertSame('2026-10-28', $this->stored->getNextDueDate());
		$this->assertNull($this->stored->getLastPaidDate());
	}

	public function testABookingErrorAfterTheClaimHandsTheOccurrenceBackAndIsReported(): void {
		$this->bill(['remainingPayments' => 3]);
		$this->dropFails = new \RuntimeException('database gone');

		try {
			$this->service->markPaid(1, 'user1', self::TODAY, false, null, '2026-10-15');
			$this->fail('The error was swallowed');
		} catch (\RuntimeException $e) {
			$this->assertSame('database gone', $e->getMessage());
		}

		$this->assertSame('2026-10-15', $this->stored->getNextDueDate());
		$this->assertSame('2026-09-15', $this->stored->getLastPaidDate());
		$this->assertSame(3, $this->stored->getRemainingPayments());
	}

	public function testAutoPayThatLosesTheClaimToAClickIsNotAFailure(): void {
		// The owner pressed Mark Paid while the job was paying the bill
		$this->bill(['autoPayEnabled' => true]);
		$this->beforeClaim = function () {
			$this->stored->setNextDueDate('2026-11-15');
			$this->stored->setLastPaidDate(self::TODAY);
		};

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertFalse($result['success']);
		$this->assertFalse($result['disabled']);
		$this->assertSame([], $this->calls);
		$this->assertTrue($this->stored->getAutoPayEnabled());
		$this->assertFalse($this->stored->getAutoPayFailed());
	}

	// ── Skip ────────────────────────────────────────────────────────

	public function testASkipOfAnOccurrenceAlreadyMovedOnIsRefused(): void {
		// A stale tab or a second click: November must not be skipped too
		$this->bill(['nextDueDate' => '2026-11-15']);

		try {
			$this->service->skipPayment(1, 'user1', '2026-10-15');
			$this->fail('The stale skip went through');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame(self::ALREADY_SKIPPED, $e->getMessage());
		}

		$this->assertSame('2026-11-15', $this->stored->getNextDueDate());
		$this->assertSame([], $this->calls);
	}

	public function testTwoSkipsOfOneOccurrenceSkipItOnce(): void {
		$this->bill();
		$this->beforeClaim = fn () => $this->service->skipPayment(1, 'user1', '2026-10-15');

		try {
			$this->service->skipPayment(1, 'user1', '2026-10-15');
			$this->fail('October was skipped twice');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame(self::ALREADY_SKIPPED, $e->getMessage());
		}

		$this->assertSame('2026-11-15', $this->stored->getNextDueDate());
		$this->assertSame(['drop-pre-booked', 'pre-book:2026-11-15'], $this->calls);
	}

	public function testASkipThatLosesTheClaimLeavesThePreBookedRowAlone(): void {
		// Dropped first, the row the other request just booked for the next
		// occurrence went with it
		$this->bill();
		$this->beforeClaim = function () {
			$this->stored->setNextDueDate('2026-11-15');
		};

		try {
			$this->service->skipPayment(1, 'user1', '2026-10-15');
			$this->fail('The skip went through');
		} catch (\InvalidArgumentException $e) {
		}

		$this->assertSame([], $this->calls);
	}

	public function testASkipSpendsTheUndoSnapshotInItsClaim(): void {
		$this->bill();
		$this->stored->setPaidUndoState('{"paidDate":"2026-09-15"}');

		$this->service->skipPayment(1, 'user1', '2026-10-15');

		$this->assertNull($this->stored->getPaidUndoState());
		$this->assertSame('2026-11-15', $this->stored->getNextDueDate());
	}

	// ── Undo skip ───────────────────────────────────────────────────

	public function testUndoingASkipPutsTheOccurrenceBeforeTheNextOneBack(): void {
		// What the reporter was told to run for CSX Pro (#399): monthly on
		// the 28th, no pre-booked rows, due 28 November after a double payment
		$this->bill(['dueDay' => 28, 'nextDueDate' => '2026-11-28', 'lastPaidDate' => '2026-09-30', 'createTransaction' => false]);

		$bill = $this->service->undoSkip(1, 'user1', '2026-10-28');

		$this->assertSame('2026-10-28', $bill->getNextDueDate());
		$this->assertSame('2026-10-28', $this->stored->getNextDueDate());
		$this->assertTrue($this->stored->getIsActive());
		$this->assertSame([], array_filter($this->calls, fn (string $c) => !str_starts_with($c, 'drop')), 'Nothing is booked');
	}

	public function testUndoingASkipRefusesADayTheBillIsNeverDue(): void {
		// Any string was stored as the due date: a bad one made the annual
		// overview fail for the whole user
		$this->bill(['dueDay' => 28, 'nextDueDate' => '2026-11-28']);

		try {
			$this->service->undoSkip(1, 'user1', '2026-10-15');
			$this->fail('A day the bill is never due was stored');
		} catch (\InvalidArgumentException $e) {
		}

		$this->assertSame('2026-11-28', $this->stored->getNextDueDate());
		$this->assertSame([], $this->calls);
	}

	public function testUndoingASkipRefusesADateAfterTheNextDueDate(): void {
		$this->bill(['dueDay' => 28, 'nextDueDate' => '2026-11-28']);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->undoSkip(1, 'user1', '2026-12-28');
	}

	public function testUndoingASkipRefusesAOneTimeBill(): void {
		// A one-time bill is never skipped: its date and state stay as they are
		$this->bill(['frequency' => 'one-time', 'isActive' => false, 'nextDueDate' => null, 'startDate' => '2026-09-28', 'dueDay' => 28]);

		try {
			$this->service->undoSkip(1, 'user1', '2026-09-28');
			$this->fail('A one-time bill took a skipped date');
		} catch (\InvalidArgumentException $e) {
		}

		$this->assertNull($this->stored->getNextDueDate());
		$this->assertFalse($this->stored->getIsActive());
	}

	public function testUndoingTheSkipThatEndedABillBringsItBack(): void {
		// Skipping its last occurrence ended the bill; undoing it is still allowed
		$this->bill(['dueDay' => 28, 'isActive' => false, 'nextDueDate' => null, 'endDate' => '2026-10-31']);

		$bill = $this->service->undoSkip(1, 'user1', '2026-10-28');

		$this->assertTrue($bill->getIsActive());
		$this->assertSame('2026-10-28', $bill->getNextDueDate());
	}

	public function testUndoingASkipRefusesADateAfterTheEndOfAnEndedBill(): void {
		$this->bill(['dueDay' => 28, 'isActive' => false, 'nextDueDate' => null, 'endDate' => '2026-10-31']);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->undoSkip(1, 'user1', '2026-11-28');
	}

	public function testUndoingAWeeklySkipCountsTheWeeksFromTheNextDueDate(): void {
		// With no start date a weekly bill counts on from its pending date,
		// as paying and skipping do
		$this->bill(['frequency' => 'weekly', 'dueDay' => 3, 'nextDueDate' => '2026-10-21', 'createTransaction' => false]);

		$this->assertSame('2026-10-14', $this->service->undoSkip(1, 'user1', '2026-10-14')->getNextDueDate());

		$this->bill(['frequency' => 'weekly', 'dueDay' => 3, 'nextDueDate' => '2026-10-21', 'createTransaction' => false]);
		$this->expectException(\InvalidArgumentException::class);
		$this->service->undoSkip(1, 'user1', '2026-10-15');
	}

	public function testUndoingASkipLeavesAPausedBillPaused(): void {
		// Undo used to switch on any inactive bill it was given
		$this->bill(['dueDay' => 28, 'isActive' => false, 'nextDueDate' => '2026-11-28']);

		$bill = $this->service->undoSkip(1, 'user1', '2026-10-28');

		$this->assertFalse($bill->getIsActive());
		$this->assertSame('2026-10-28', $bill->getNextDueDate());
		$this->assertSame(['drop-pre-booked'], $this->calls, 'Nothing is pre-booked for a paused bill');
	}

	public function testUndoingASkipRefusesABillWhosePaymentsAreUsedUp(): void {
		// Ended by its last payment, not by a skip: nothing to put back
		$this->bill(['dueDay' => 28, 'isActive' => false, 'nextDueDate' => null, 'remainingPayments' => 0]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->undoSkip(1, 'user1', '2026-10-28');
	}

	/**
	 * Due dates 2.x stored that the schedule no longer falls on: no day fell
	 * on the 1st, no month in January (the first month of each calendar
	 * quarter), and a semi-monthly second date stopped at the 28th
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: string}>
	 */
	public static function datesTwoXStored(): array {
		return [
			'monthly with no day, on the 1st' => [['frequency' => 'monthly', 'dueDay' => null, 'startDate' => '2026-01-20'], '2026-11-01'],
			'quarterly with no month, in October' => [['frequency' => 'quarterly', 'dueDay' => 10, 'startDate' => '2026-02-10'], '2026-10-10'],
			'semi-monthly, stopped at the 28th' => [['frequency' => 'semi-monthly', 'dueDay' => 15], '2026-10-28'],
			'yearly with no month, in January' => [['frequency' => 'yearly', 'dueDay' => 14, 'startDate' => '2025-03-14'], '2027-01-14'],
		];
	}

	#[DataProvider('datesTwoXStored')]
	public function testTheUndoOfASkipPutsBackADateTwoXStored(array $schedule, string $stored): void {
		// The toast's Undo, right after the Skip: refused, the user lost the
		// occurrence with no way back
		$this->bill($schedule + ['nextDueDate' => $stored, 'createTransaction' => false]);
		$skipped = $this->service->skipPayment(1, 'user1', $stored);

		$this->service->undoSkip(1, 'user1', $skipped['previousNextDueDate']);

		$this->assertSame($stored, $this->stored->getNextDueDate());
		$this->assertTrue($this->stored->getIsActive());
	}

	public function testOnlyTheDateASkipLeftFromIsPutBackOffTheSchedule(): void {
		// Due 20 December after skipping 1 November: an earlier 1st is no
		// date the bill was due on
		$this->bill(['dueDay' => null, 'startDate' => '2026-01-20', 'nextDueDate' => '2026-12-20']);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->undoSkip(1, 'user1', '2026-10-01');
	}

	public function testTheUndoOfTheSkipThatEndedAFortnightlyBillWithNoStartDateBringsItBack(): void {
		// It counted its fortnights from the date it was due, and the skip
		// ended it: its weekday is all that is left to go by
		$this->bill(['frequency' => 'biweekly', 'dueDay' => 2, 'endDate' => '2026-10-25', 'nextDueDate' => '2026-10-20', 'createTransaction' => false]);
		$this->service->skipPayment(1, 'user1', '2026-10-20');
		$this->assertFalse($this->stored->getIsActive());

		$this->service->undoSkip(1, 'user1', '2026-10-20');

		$this->assertTrue($this->stored->getIsActive());
		$this->assertSame('2026-10-20', $this->stored->getNextDueDate());
	}

	public function testAnEndedFortnightlyBillTakesBackNoDayOfAnotherWeekday(): void {
		// Due on Tuesdays: a Wednesday was never one of its dates
		$this->bill(['frequency' => 'biweekly', 'dueDay' => 2, 'isActive' => false, 'nextDueDate' => null, 'endDate' => '2026-10-25']);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->undoSkip(1, 'user1', '2026-10-21');
	}
}
