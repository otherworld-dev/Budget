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
 * Undo after a Skip brings Mark Unpaid back (#399).
 *
 * Skipping the date after a payment spent that payment's undo snapshot, as
 * Mark Unpaid restored after a skip would bring back a date from before it.
 * The toast's Undo then put the skipped date back, but Mark Unpaid stayed
 * gone and the payment could no longer be taken back. The skip now sets the
 * snapshot aside: Mark Unpaid is off while the skip stands, and undoing the
 * skip puts it back, still reverting that payment. Income works the same.
 */
class SkipKeepsMarkUnpaidTest extends TestCase {
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

	/** Paid on 29 October: due next on 29 November */
	private function paidBill(): BillService {
		$service = $this->bills();
		$service->markPaid(1, 'user1', '2026-10-29', true, null, '2026-10-29');
		$this->assertTrue($this->bill->canMarkUnpaid());
		return $service;
	}

	public function testASkipTakesMarkUnpaidAwayWhileItStands(): void {
		$service = $this->paidBill();

		$service->skipPayment(1, 'user1', '2026-11-29');

		$this->assertSame('2026-12-29', $this->bill->getNextDueDate());
		$this->assertFalse($this->bill->canMarkUnpaid());
		$this->assertFalse($this->bill->jsonSerialize()['canMarkUnpaid']);
		$this->expectException(\InvalidArgumentException::class);
		$service->markUnpaid(1, 'user1');
	}

	public function testUndoingTheSkipBringsMarkUnpaidBack(): void {
		$service = $this->paidBill();
		$service->skipPayment(1, 'user1', '2026-11-29');

		$service->undoSkip(1, 'user1', '2026-11-29');

		$this->assertSame('2026-11-29', $this->bill->getNextDueDate());
		$this->assertTrue($this->bill->canMarkUnpaid());
		$this->assertTrue($this->bill->jsonSerialize()['canMarkUnpaid']);
	}

	public function testMarkUnpaidAfterTheUndoRevertsThePayment(): void {
		$service = $this->paidBill();
		$this->deleted = [];
		$service->skipPayment(1, 'user1', '2026-11-29');
		$service->undoSkip(1, 'user1', '2026-11-29');

		$service->markUnpaid(1, 'user1');

		$this->assertSame('2026-10-29', $this->bill->getNextDueDate());
		$this->assertSame('2026-09-27', $this->bill->getLastPaidDate());
		$this->assertContains(900, $this->deleted, 'the payment it recorded goes');
		$this->assertFalse($this->bill->canMarkUnpaid());
	}

	public function testTwoSkipsAreUndoneOneByOne(): void {
		$service = $this->paidBill();
		$service->skipPayment(1, 'user1', '2026-11-29');
		$service->skipPayment(1, 'user1', '2026-12-29');

		$service->undoSkip(1, 'user1', '2026-12-29');
		$this->assertFalse($this->bill->canMarkUnpaid(), 'November is still skipped');

		$service->undoSkip(1, 'user1', '2026-11-29');
		$this->assertTrue($this->bill->canMarkUnpaid());
	}

	public function testASkipWithNoPaymentToUndoLeavesNothingBehind(): void {
		$service = $this->bills();

		$service->skipPayment(1, 'user1', '2026-10-29');
		$service->undoSkip(1, 'user1', '2026-10-29');

		$this->assertNull($this->bill->getPaidUndoState());
		$this->assertFalse($this->bill->canMarkUnpaid());
	}

	// ── Income ──────────────────────────────────────────────────────

	/** Received on 29 September: expected next on 29 October */
	private function receivedIncome(): RecurringIncomeService {
		$service = $this->incomes();
		$service->markReceived(1, 'user1', '2026-09-29', true, '2026-09-29');
		$this->assertTrue($this->income->canUndoReceived());
		return $service;
	}

	public function testASkipTakesMarkUnreceivedAwayWhileItStands(): void {
		$service = $this->receivedIncome();

		$service->skipPayment(1, 'user1', '2026-10-29');

		$this->assertSame('2026-11-29', $this->income->getNextExpectedDate());
		$this->assertFalse($this->income->canUndoReceived());
		$this->expectException(\InvalidArgumentException::class);
		$service->markUnreceived(1, 'user1');
	}

	public function testUndoingAnIncomeSkipBringsMarkUnreceivedBack(): void {
		$service = $this->receivedIncome();
		$service->skipPayment(1, 'user1', '2026-10-29');

		$service->undoSkip(1, 'user1', '2026-10-29');

		$this->assertSame('2026-10-29', $this->income->getNextExpectedDate());
		$this->assertTrue($this->income->canUndoReceived());

		$service->markUnreceived(1, 'user1');

		$this->assertSame('2026-09-29', $this->income->getNextExpectedDate());
		$this->assertSame('2026-08-29', $this->income->getLastReceivedDate());
		$this->assertContains(700, $this->deleted, 'the credit it booked goes');
	}
}
