<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\DismissedSuggestionMapper;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Bill\RecurringBillDetector;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The server says when the occurrence before the next one was skipped
 * (#399 review).
 *
 * The page judged a row settled by the dates alone, and a skip looks just
 * like a payment made early: the last payment within the past cycle. Read
 * the other way, a bill paid early offered Mark Paid and paid twice. The
 * bill and the income now carry lastOccurrenceSkipped, read off the dates a
 * skip lists in the undo snapshot (UndoSnapshotSkips), and a skip with no
 * payment snapshot to list them in leaves one holding only the dates.
 * Undoing the skips out of order leaves nothing set aside either.
 */
class SkipFlagReviewTest extends TestCase {
	private ?Bill $bill = null;
	private ?RecurringIncome $income = null;
	/** Runs once, just before the next claim: another request getting in first */
	private ?\Closure $beforeClaim = null;
	private int $nextId = 900;
	/** @var int[] */
	private array $deleted = [];
	/** @var array<int, Transaction> */
	private array $credits = [];

	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return $l;
	}

	private function clock(): UserClock {
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn('2026-10-19');
		return $clock;
	}

	/** The other request's claim, made before this one's */
	private function otherRequestFirst(): void {
		if ($this->beforeClaim !== null) {
			$other = $this->beforeClaim;
			$this->beforeClaim = null;
			$other();
		}
	}

	/** @param array<string, mixed> $fields column => value */
	private static function write(object $row, array $fields): void {
		foreach ($fields as $column => $value) {
			$row->{'set' . str_replace('_', '', ucwords($column, '_'))}($value);
		}
	}

	/** An update writes only the fields the entity marks changed */
	private static function update(object $stored, object $entity): object {
		foreach (array_keys($entity->getUpdatedFields()) as $property) {
			$stored->{'set' . ucfirst($property)}($entity->{'get' . ucfirst($property)}());
		}
		return $entity;
	}

	private function bills(): BillService {
		$this->bill = new Bill();
		$this->bill->setId(1);
		$this->bill->setUserId('user1');
		$this->bill->setName('KPT Prämienrechnung');
		$this->bill->setAmount(484.75);
		$this->bill->setFrequency('monthly');
		$this->bill->setDueDay(29);
		$this->bill->setDueMonth(null);
		$this->bill->setIsActive(true);
		$this->bill->setAccountId(3);
		$this->bill->setNextDueDate('2026-10-29');
		$this->bill->setLastPaidDate('2026-09-27');
		$this->bill->setCreateTransaction(false);
		$this->bill->setPaidUndoState(null);
		$this->bill->resetUpdatedFields();

		$mapper = $this->createMock(BillMapper::class);
		$mapper->method('find')->willReturnCallback(fn () => clone $this->bill);
		$mapper->method('update')->willReturnCallback(fn (Bill $bill) => self::update($this->bill, $bill));
		$mapper->method('updateFields')->willReturnCallback(fn (int $id, string $user, array $fields) => self::write($this->bill, $fields));
		$mapper->method('claimOccurrence')->willReturnCallback(function (int $id, string $user, string $expected, array $fields) {
			$this->otherRequestFirst();
			if (!$this->bill->getIsActive() || $this->bill->getNextDueDate() !== $expected) {
				return false;
			}
			self::write($this->bill, $fields);
			return true;
		});

		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('createFromBill')->willReturnCallback(function () {
			$tx = new Transaction();
			$tx->setId($this->nextId++);
			return $tx;
		});
		$transactions->method('deleteAsAccountOwner')->willReturnCallback(function (int $id) {
			$this->deleted[] = $id;
			return true;
		});

		return new BillService(
			$mapper,
			new FrequencyCalculator(),
			$this->createMock(RecurringBillDetector::class),
			$transactions,
			$this->l10n(),
			$this->createMock(AccountMapper::class),
			$this->createMock(CurrencyConversionService::class),
			$this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class),
			null,
			$this->createMock(RecurringIncomeMapper::class),
			null,
			$this->clock(),
		);
	}

	private function incomes(): RecurringIncomeService {
		$this->income = new RecurringIncome();
		$this->income->setId(1);
		$this->income->setUserId('user1');
		$this->income->setName('Gutschrift SBB Abo Leon');
		$this->income->setAmount(100.0);
		$this->income->setFrequency('monthly');
		$this->income->setExpectedDay(29);
		$this->income->setExpectedMonth(null);
		$this->income->setIsActive(true);
		$this->income->setAutoCreateEnabled(false);
		$this->income->setAccountId(7);
		$this->income->setLastReceivedDate('2026-08-29');
		$this->income->setNextExpectedDate('2026-09-29');
		$this->income->setStartDate(null);
		$this->income->setReceivedUndoState(null);
		$this->income->resetUpdatedFields();

		$mapper = $this->createMock(RecurringIncomeMapper::class);
		$mapper->method('find')->willReturnCallback(fn () => clone $this->income);
		$mapper->method('update')->willReturnCallback(fn (RecurringIncome $income) => self::update($this->income, $income));
		$mapper->method('updateFields')->willReturnCallback(fn (int $id, string $user, array $fields) => self::write($this->income, $fields));
		$mapper->method('claimOccurrence')->willReturnCallback(function (int $id, string $user, string $expected, array $fields) {
			$this->otherRequestFirst();
			if (!$this->income->getIsActive() || $this->income->getNextExpectedDate() !== $expected) {
				return false;
			}
			self::write($this->income, $fields);
			return true;
		});

		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('createFromIncome')->willReturnCallback(function () {
			$tx = new Transaction();
			$tx->setId(700);
			$tx->setAccountId(7);
			$tx->setType('credit');
			$tx->setNotes('Auto-generated from income: Gutschrift SBB Abo Leon');
			$this->credits[700] = $tx;
			return $tx;
		});
		$transactions->method('findTransaction')->willReturnCallback(fn (int $id) => $this->credits[$id] ?? null);
		$transactions->method('deleteAsAccountOwner')->willReturnCallback(function (int $id) {
			$this->deleted[] = $id;
			return true;
		});

		return new RecurringIncomeService(
			$mapper,
			new FrequencyCalculator(),
			$this->createMock(RecurringIncomeDetector::class),
			$transactions,
			$this->createMock(LoggerInterface::class),
			$this->l10n(),
			null,
			$this->clock(),
		);
	}

	// ── Bills ───────────────────────────────────────────────────────

	public function testAPaidBillSaysWhenItsNextDateWasSkipped(): void {
		$service = $this->bills();
		$service->markPaid(1, 'user1', '2026-10-29', true, null, '2026-10-29');
		$this->assertFalse($this->bill->jsonSerialize()['lastOccurrenceSkipped']);

		$service->skipPayment(1, 'user1', '2026-11-29');
		$this->assertTrue($this->bill->jsonSerialize()['lastOccurrenceSkipped']);

		$service->undoSkip(1, 'user1', '2026-11-29');
		$this->assertFalse($this->bill->jsonSerialize()['lastOccurrenceSkipped']);
		$this->assertTrue($this->bill->canMarkUnpaid());
	}

	public function testASkipWithNoPaymentToUndoStillSaysSo(): void {
		// Paid before the snapshot existed, or its snapshot spent by an edit
		$service = $this->bills();

		$service->skipPayment(1, 'user1', '2026-10-29');

		$this->assertSame(['skippedDates' => ['2026-10-29']], json_decode((string)$this->bill->getPaidUndoState(), true));
		$json = $this->bill->jsonSerialize();
		$this->assertTrue($json['lastOccurrenceSkipped']);
		$this->assertFalse($json['canMarkUnpaid']);
		$this->assertFalse($json['lastPaymentUsedExisting']);
		try {
			$service->markUnpaid(1, 'user1');
			$this->fail('Mark Unpaid ran from a snapshot holding only skipped dates');
		} catch (\InvalidArgumentException $e) {
		}
		$this->assertSame('2026-11-29', $this->bill->getNextDueDate());

		$service->undoSkip(1, 'user1', '2026-10-29');

		$this->assertNull($this->bill->getPaidUndoState());
		$this->assertFalse($this->bill->jsonSerialize()['lastOccurrenceSkipped']);
	}

	public function testTwoSkipsUndoneOutOfOrderLeaveNothingSetAside(): void {
		// The first toast's Undo pressed before the second's: the bill goes
		// back to the first skipped date, which undoes both skips
		$service = $this->bills();
		$service->markPaid(1, 'user1', '2026-10-29', true, null, '2026-10-29');
		$service->skipPayment(1, 'user1', '2026-11-29');
		$service->skipPayment(1, 'user1', '2026-12-29');

		$service->undoSkip(1, 'user1', '2026-11-29');

		$this->assertSame('2026-11-29', $this->bill->getNextDueDate());
		$this->assertTrue($this->bill->canMarkUnpaid());
		$this->assertFalse($this->bill->jsonSerialize()['lastOccurrenceSkipped']);
		// The second toast's Undo is refused: that date is after the one now due
		$this->expectException(\InvalidArgumentException::class);
		$service->undoSkip(1, 'user1', '2026-12-29');
	}

	public function testMarkUnpaidAfterOutOfOrderUndoesRevertsThePayment(): void {
		$service = $this->bills();
		$service->markPaid(1, 'user1', '2026-10-29', true, null, '2026-10-29');
		$service->skipPayment(1, 'user1', '2026-11-29');
		$service->skipPayment(1, 'user1', '2026-12-29');
		$service->undoSkip(1, 'user1', '2026-11-29');

		$service->markUnpaid(1, 'user1');

		$this->assertSame('2026-10-29', $this->bill->getNextDueDate());
		$this->assertSame('2026-09-27', $this->bill->getLastPaidDate());
	}

	// ── Income ──────────────────────────────────────────────────────

	public function testAnIncomeSaysWhenItsNextDateWasSkipped(): void {
		$service = $this->incomes();
		$service->markReceived(1, 'user1', '2026-09-29', true, '2026-09-29');
		$this->assertFalse($this->income->jsonSerialize()['lastOccurrenceSkipped']);

		$service->skipPayment(1, 'user1', '2026-10-29');
		$this->assertTrue($this->income->jsonSerialize()['lastOccurrenceSkipped']);

		$service->undoSkip(1, 'user1', '2026-10-29');
		$this->assertFalse($this->income->jsonSerialize()['lastOccurrenceSkipped']);
		$this->assertTrue($this->income->canUndoReceived());
	}

	public function testAnIncomeSkipWithNoReceiptToUndoStillSaysSo(): void {
		// Auto-create books the credit without leaving a snapshot
		$service = $this->incomes();

		$service->skipPayment(1, 'user1', '2026-09-29');

		$json = $this->income->jsonSerialize();
		$this->assertTrue($json['lastOccurrenceSkipped']);
		$this->assertFalse($json['canUndoReceived']);
		try {
			$service->markUnreceived(1, 'user1');
			$this->fail('Mark Unreceived ran from a snapshot holding only skipped dates');
		} catch (\InvalidArgumentException $e) {
		}

		$service->undoSkip(1, 'user1', '2026-09-29');

		$this->assertNull($this->income->getReceivedUndoState());
		$this->assertSame('2026-09-29', $this->income->getNextExpectedDate());
	}
}
