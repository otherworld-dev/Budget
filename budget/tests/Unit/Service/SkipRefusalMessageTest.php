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
 * A refused Skip says what happened (#399).
 *
 * Two Skips of one occurrence (two tabs, or two requests at once) leave one
 * refused, and it said "This payment was already recorded" though nothing
 * had been paid: the occurrence had been skipped. A skip that comes too late
 * now says the date was already paid or skipped, and so do Mark Paid and Mark
 * Received, as their date may have been skipped too (see
 * SettledOccurrenceRefusalTest). The mappers here claim as the real ones do
 * (see BillPayOnceTest): only while the row is still due on the date expected.
 */
class SkipRefusalMessageTest extends TestCase {
	private const ALREADY_SKIPPED = 'This date was already paid or skipped. Reload the page to see the next one.';

	private ?Bill $bill = null;
	private ?RecurringIncome $income = null;
	/** Runs once, just before the next claim: another request getting in first */
	private ?\Closure $beforeClaim = null;

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
			$tx->setId(900);
			return $tx;
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
			return $tx;
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

	/** @return string the message the call was refused with */
	private function refusal(callable $call): string {
		try {
			$call();
		} catch (\InvalidArgumentException $e) {
			return $e->getMessage();
		}
		$this->fail('It went through');
	}

	// ── Bills ───────────────────────────────────────────────────────

	public function testASecondSkipOfABillSaysTheDateWasAlreadyPaidOrSkipped(): void {
		// KPT skipped in another tab: this tab still shows 29 October
		$service = $this->bills();
		$service->skipPayment(1, 'user1', '2026-10-29');

		$this->assertSame(self::ALREADY_SKIPPED, $this->refusal(fn () => $service->skipPayment(1, 'user1', '2026-10-29')));
		$this->assertSame('2026-11-29', $this->bill->getNextDueDate());
	}

	public function testASkipOfABillThatLosesToAnotherAtOnceSaysTheSame(): void {
		$service = $this->bills();
		$this->beforeClaim = fn () => $service->skipPayment(1, 'user1', '2026-10-29');

		$this->assertSame(self::ALREADY_SKIPPED, $this->refusal(fn () => $service->skipPayment(1, 'user1', '2026-10-29')));
		$this->assertSame('2026-11-29', $this->bill->getNextDueDate());
	}

	public function testASkipOfABillAlreadyPaidSaysTheSame(): void {
		// Paid in another tab, then Skip pressed in this one
		$service = $this->bills();
		$service->markPaid(1, 'user1', null, false, null, '2026-10-29');

		$this->assertSame(self::ALREADY_SKIPPED, $this->refusal(fn () => $service->skipPayment(1, 'user1', '2026-10-29')));
	}

	public function testASecondPaymentOfABillSaysTheSame(): void {
		$service = $this->bills();
		$service->markPaid(1, 'user1', null, false, null, '2026-10-29');

		$this->assertSame(self::ALREADY_SKIPPED, $this->refusal(fn () => $service->markPaid(1, 'user1', null, false, null, '2026-10-29')));
	}

	// ── Income ──────────────────────────────────────────────────────

	public function testASecondSkipOfAnIncomeSaysTheDateWasAlreadyPaidOrSkipped(): void {
		$service = $this->incomes();
		$service->skipPayment(1, 'user1', '2026-09-29');

		$this->assertSame(self::ALREADY_SKIPPED, $this->refusal(fn () => $service->skipPayment(1, 'user1', '2026-09-29')));
		$this->assertSame('2026-10-29', $this->income->getNextExpectedDate());
	}

	public function testASkipOfAnIncomeThatLosesToAnotherAtOnceSaysTheSame(): void {
		$service = $this->incomes();
		$this->beforeClaim = fn () => $service->skipPayment(1, 'user1', '2026-09-29');

		$this->assertSame(self::ALREADY_SKIPPED, $this->refusal(fn () => $service->skipPayment(1, 'user1', '2026-09-29')));
		$this->assertSame('2026-10-29', $this->income->getNextExpectedDate());
	}

	public function testASecondReceiptOfAnIncomeSaysTheSame(): void {
		$service = $this->incomes();
		$service->markReceived(1, 'user1', '2026-09-29', false, '2026-09-29');

		$this->assertSame(self::ALREADY_SKIPPED, $this->refusal(fn () => $service->markReceived(1, 'user1', '2026-09-29', false, '2026-09-29')));
	}
}
