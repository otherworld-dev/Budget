<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Recurring income against the real schedule, on a fixed day (2026-09-28).
 *
 * The next expected date is always the first occurrence not yet received or
 * skipped. Mark Received settles exactly that one and dates the money on the
 * day it arrived; auto-create books each due occurrence once; an edit that
 * leaves the schedule alone leaves the date alone.
 */
class RecurringIncomeLifecycleTest extends TestCase {
	private const TODAY = '2026-09-28';

	private RecurringIncomeService $service;
	private RecurringIncomeMapper $mapper;
	private TransactionService $transactions;
	private GranularShareService $shares;
	private ?RecurringIncome $stored = null;
	/** @var list<array{date: string, status: ?string}> */
	private array $booked = [];
	/** @var array<int, Transaction> */
	private array $rows = [];
	private bool $accountWritable = true;

	protected function setUp(): void {
		$this->mapper = $this->createMock(RecurringIncomeMapper::class);
		$this->mapper->method('insert')->willReturnArgument(0);
		$this->mapper->method('update')->willReturnCallback(function (RecurringIncome $income) {
			$this->stored = $income;
			return $income;
		});
		$this->mapper->method('find')->willReturnCallback(fn () => $this->stored);
		$this->mapper->method('updateFields')->willReturnCallback(function (int $id, string $user, array $fields) {
			foreach ($fields as $column => $value) {
				$this->stored->{'set' . str_replace('_', '', ucwords($column, '_'))}($value);
			}
		});

		$this->transactions = $this->createMock(TransactionService::class);
		$this->transactions->method('createFromIncome')->willReturnCallback(
			function (string $user, RecurringIncome $income, ?string $date = null, ?string $status = null) {
				$this->booked[] = ['date' => $date, 'status' => $status];
				$tx = new Transaction();
				$tx->setId(100 + count($this->booked));
				$tx->setAccountId($income->getAccountId());
				$tx->setType('credit');
				$tx->setNotes('Auto-generated from income: ' . $income->getName());
				$this->rows[$tx->getId()] = $tx;
				return $tx;
			}
		);

		$this->transactions->method('findTransaction')->willReturnCallback(fn (int $id) => $this->rows[$id] ?? null);

		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);
		$this->shares = $this->createMock(GranularShareService::class);
		$this->shares->method('canWrite')->willReturnCallback(fn () => $this->accountWritable);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->service = new RecurringIncomeService(
			$this->mapper,
			new FrequencyCalculator(),
			$this->createMock(RecurringIncomeDetector::class),
			$this->transactions,
			$this->createMock(LoggerInterface::class),
			$l,
			null,
			$clock,
			$this->shares,
		);
	}

	private function income(array $fields): RecurringIncome {
		$income = new RecurringIncome();
		$income->setId(1);
		$income->setUserId('user1');
		$income->setName('Salary');
		$income->setAmount(14.90);
		$income->setFrequency($fields['frequency'] ?? 'monthly');
		$income->setExpectedDay($fields['expectedDay'] ?? 3);
		$income->setExpectedMonth($fields['expectedMonth'] ?? null);
		$income->setIsActive($fields['isActive'] ?? true);
		$income->setAutoCreateEnabled($fields['autoCreateEnabled'] ?? false);
		$income->setAccountId(array_key_exists('accountId', $fields) ? $fields['accountId'] : 7);
		$income->setLastReceivedDate($fields['lastReceivedDate'] ?? null);
		$income->setNextExpectedDate(array_key_exists('nextExpectedDate', $fields) ? $fields['nextExpectedDate'] : '2026-10-03');
		$income->setStartDate($fields['startDate'] ?? null);
		$this->stored = $income;
		return $income;
	}

	// ── create ──────────────────────────────────────────────────────

	public function testAOneTimeIncomeNeedsADate(): void {
		// Without one the schedule invented 1 January next year (#399)
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('user1', 'Refund', 50.0, 'one-time');
	}

	public function testAOneTimeIncomeIsExpectedOnItsDateEvenInThePast(): void {
		$income = $this->service->create('user1', 'Refund', 50.0, 'one-time', null, null, null, null, null, null, null, false, null, false, '2026-09-03');

		$this->assertSame('2026-09-03', $income->getNextExpectedDate());
	}

	public function testCustomIsRefusedForIncome(): void {
		// Income has no pattern to run a custom schedule on: it was expected
		// today, every day, and auto-create booked it every six hours
		$this->expectException(\InvalidArgumentException::class);
		$this->service->create('user1', 'Odd', 50.0, 'custom', 3);
	}

	public function testAMonthlyIncomeDueTodayIsExpectedToday(): void {
		$income = $this->service->create('user1', 'Salary', 50.0, 'monthly', 28);

		$this->assertSame(self::TODAY, $income->getNextExpectedDate());
	}

	// ── mark received ───────────────────────────────────────────────

	public function testTheMoneyIsDatedTheDayItArrived(): void {
		// The credit was dated on the stored expected date, so a stale date
		// filed September's payment under August (#399)
		$this->income(['nextExpectedDate' => '2026-08-03']);

		$income = $this->service->markReceived(1, 'user1', '2026-09-22', true);

		$this->assertSame([['date' => '2026-09-22', 'status' => 'cleared']], $this->booked);
		$this->assertSame('2026-09-03', $income->getNextExpectedDate(), 'One receipt settles one occurrence');
		$this->assertSame('2026-09-22', $income->getLastReceivedDate());
	}

	public function testAnEarlyReceiptMovesOnToTheNextOccurrence(): void {
		// Received the day before it was due: the date stayed put, the
		// credit was booked for tomorrow, and the list said Received beside it
		$this->income(['nextExpectedDate' => '2026-09-29', 'expectedDay' => 29]);

		$income = $this->service->markReceived(1, 'user1', self::TODAY, true);

		$this->assertSame('2026-10-29', $income->getNextExpectedDate());
		$this->assertSame([['date' => self::TODAY, 'status' => 'cleared']], $this->booked);
	}

	public function testAStaleOrDoubleClickIsRefused(): void {
		// The page names the occurrence it showed; a second click (or a stale
		// tab) names one already settled, and booked the salary twice
		$this->income(['nextExpectedDate' => '2026-10-03']);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->markReceived(1, 'user1', self::TODAY, true, '2026-09-03');
	}

	public function testAnInactiveIncomeCannotBeReceivedAgain(): void {
		$this->income(['isActive' => false, 'nextExpectedDate' => null]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->markReceived(1, 'user1', self::TODAY, true);
	}

	public function testAOneTimeIncomeIsCompletedAndKeepsItsDate(): void {
		$this->income(['frequency' => 'one-time', 'nextExpectedDate' => '2026-09-03', 'startDate' => null]);

		$income = $this->service->markReceived(1, 'user1', self::TODAY, true);

		$this->assertFalse($income->getIsActive());
		$this->assertNull($income->getNextExpectedDate());
		$this->assertSame('2026-09-03', $income->getStartDate(), 'Its date is all a completed income has left to show');
	}

	public function testUndoPutsEverythingBack(): void {
		// Undo only reset the last received date: the credit stayed in the
		// ledger and the expected date stayed moved on
		$this->income(['nextExpectedDate' => '2026-10-03', 'lastReceivedDate' => '2026-09-03']);
		$this->service->markReceived(1, 'user1', self::TODAY, true);
		$this->transactions->expects($this->once())->method('deleteAsAccountOwner')->with(101);

		$income = $this->service->markUnreceived(1, 'user1');

		$this->assertSame('2026-10-03', $income->getNextExpectedDate());
		$this->assertSame('2026-09-03', $income->getLastReceivedDate());
		$this->assertTrue($income->getIsActive());
	}

	public function testUndoOnlyRemovesTheIncomesOwnCredit(): void {
		// The snapshot holds transaction ids. After a backup restore they
		// can name anyone's rows, so only this income's own credit goes
		$this->income(['nextExpectedDate' => '2026-10-03']);
		$this->stored->setReceivedUndoState(json_encode([
			'nextExpectedDate' => '2026-10-03', 'lastReceivedDate' => null, 'isActive' => true,
			'transactionIds' => [55],
		]));
		$stranger = new Transaction();
		$stranger->setId(55);
		$stranger->setAccountId(99);
		$stranger->setType('debit');
		$this->rows[55] = $stranger;
		$this->transactions->expects($this->never())->method('deleteAsAccountOwner');

		$this->service->markUnreceived(1, 'user1');
	}

	public function testReceivingIntoAnAccountNoLongerWritableIsRefused(): void {
		$this->accountWritable = false;
		$this->income([]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->markReceived(1, 'user1', self::TODAY, true);
	}

	// ── auto-create ─────────────────────────────────────────────────

	public function testAutoCreateBooksAOneTimeIncomeOnce(): void {
		// It was never switched off, so the job booked it every six hours
		$this->income(['frequency' => 'one-time', 'nextExpectedDate' => '2026-09-03', 'startDate' => '2026-09-03', 'autoCreateEnabled' => true]);

		$this->service->processAutoCreate(1, 'user1');
		$this->service->processAutoCreate(1, 'user1');

		$this->assertCount(1, $this->booked);
		$this->assertFalse($this->stored->getIsActive());
	}

	public function testAutoCreateCatchesUpEveryMissedOccurrence(): void {
		// Only the oldest was booked and the schedule jumped past the rest
		$this->income(['nextExpectedDate' => '2026-07-03', 'autoCreateEnabled' => true]);

		$this->service->processAutoCreate(1, 'user1');

		$this->assertSame(['2026-07-03', '2026-08-03', '2026-09-03'], array_column($this->booked, 'date'));
		$this->assertSame('2026-10-03', $this->stored->getNextExpectedDate());
	}

	public function testAutoCreateThatCannotBookSwitchesItselfOff(): void {
		// A missing account failed and notified every six hours, forever
		$this->income(['nextExpectedDate' => '2026-09-03', 'autoCreateEnabled' => true, 'accountId' => null]);

		$result = $this->service->processAutoCreate(1, 'user1');

		$this->assertFalse($result['success']);
		$this->assertFalse($this->stored->getAutoCreateEnabled());
	}

	// ── imported credits ────────────────────────────────────────────

	private function bankCredit(array $fields): Transaction {
		$tx = new Transaction();
		$tx->setId($fields['id'] ?? 900);
		$tx->setAccountId($fields['accountId'] ?? 7);
		$tx->setType($fields['type'] ?? 'credit');
		$tx->setStatus('cleared');
		$tx->setDate($fields['date'] ?? '2026-10-02');
		$tx->setAmount($fields['amount'] ?? 14.90);
		$tx->setDescription($fields['description'] ?? 'SWISSCOM REFUND 1234');
		return $tx;
	}

	public function testAnImportedPaymentMarksTheIncomeReceived(): void {
		// Nothing matched imported income: it stayed expected, and Mark
		// Received or auto-create then booked a second credit
		$income = $this->income(['nextExpectedDate' => '2026-10-03']);
		$income->setAutoDetectPattern('SWISSCOM');
		$this->mapper->method('findActive')->willReturnCallback(fn () => [$this->stored]);

		$matched = $this->service->autoMatchReceivedFromImport('user1', [$this->bankCredit([])]);

		$this->assertSame(1, $matched);
		$this->assertSame('2026-10-02', $this->stored->getLastReceivedDate());
		$this->assertSame('2026-11-03', $this->stored->getNextExpectedDate());
		$this->assertSame([], $this->booked, 'The bank row is the payment; nothing more is booked');
	}

	public function testAnImportedPaymentReplacesTheCreditAutoCreateBooked(): void {
		// Auto-create booked it on the expected date and moved on, then the
		// bank's row came in beside it
		$income = $this->income(['nextExpectedDate' => '2026-10-03', 'lastReceivedDate' => '2026-09-03', 'autoCreateEnabled' => true]);
		$income->setAutoDetectPattern('SWISSCOM');
		$this->mapper->method('findActive')->willReturnCallback(fn () => [$this->stored]);
		$generated = $this->bankCredit(['id' => 300, 'date' => '2026-09-03', 'description' => '']);
		$generated->setNotes('Auto-generated from income: Salary');
		$this->transactions->method('findGeneratedIncomeCredits')->willReturn([$generated]);
		$this->transactions->expects($this->once())->method('deleteAsAccountOwner')->with(300);

		$matched = $this->service->autoMatchReceivedFromImport('user1', [$this->bankCredit(['date' => '2026-09-04'])]);

		$this->assertSame(1, $matched);
		$this->assertSame('2026-10-03', $this->stored->getNextExpectedDate(), 'Not received a second time');
	}

	public function testAnImportedCreditThatDoesNotFitIsLeftAlone(): void {
		$income = $this->income(['nextExpectedDate' => '2026-10-03']);
		$income->setAutoDetectPattern('SWISSCOM');
		$this->mapper->method('findActive')->willReturnCallback(fn () => [$this->stored]);

		$this->assertSame(0, $this->service->autoMatchReceivedFromImport('user1', [
			$this->bankCredit(['amount' => 99.0]),
			$this->bankCredit(['type' => 'debit']),
			$this->bankCredit(['accountId' => 8]),
			$this->bankCredit(['date' => '2026-12-01']),
		]));
		$this->assertSame('2026-10-03', $this->stored->getNextExpectedDate());
	}

	// ── edit ────────────────────────────────────────────────────────

	public function testAnEditThatKeepsTheScheduleKeepsTheDate(): void {
		// Every edit recalculated from today: an overdue payment vanished and
		// a skip was undone
		$this->income(['nextExpectedDate' => '2026-08-03']);

		$income = $this->service->update(1, 'user1', ['name' => 'Pay', 'frequency' => 'monthly', 'expectedDay' => 3]);

		$this->assertSame('2026-08-03', $income->getNextExpectedDate());
	}

	public function testChangingTheDayMovesThePendingOccurrenceWithinItsMonth(): void {
		$this->income(['nextExpectedDate' => '2026-10-03']);
		$this->assertSame('2026-10-25', $this->service->update(1, 'user1', ['expectedDay' => 25])->getNextExpectedDate());

		$this->income(['nextExpectedDate' => '2026-10-03']);
		$this->assertSame('2026-10-01', $this->service->update(1, 'user1', ['expectedDay' => 1])->getNextExpectedDate());
	}

	public function testEditingACompletedOneTimeIncomeLeavesItCompleted(): void {
		$this->income(['frequency' => 'one-time', 'isActive' => false, 'nextExpectedDate' => null, 'startDate' => '2026-09-03']);

		$income = $this->service->update(1, 'user1', ['name' => 'Refund', 'startDate' => '2026-09-03']);

		$this->assertNull($income->getNextExpectedDate());
	}
}
