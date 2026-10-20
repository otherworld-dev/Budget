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
 * A refused Mark Paid or Mark Received says the date was paid or skipped
 * (#399).
 *
 * Skip on Claude by Anthropic in one tab, then Mark Paid on it in another
 * that still showed 24 October, was refused with "This payment was already
 * recorded", though nothing had been paid: the date was skipped. Every
 * refusal of an occurrence already settled, whether a stale page named it or
 * another request claimed it first, now says what a refused Skip already
 * says. The mappers here claim as the real ones do (see BillPayOnceTest):
 * only while the row is still due on the date expected.
 */
class SettledOccurrenceRefusalTest extends TestCase {
	private const ALREADY_SETTLED = 'This date was already paid or skipped. Reload the page to see the next one.';

	private ?Bill $bill = null;
	private ?RecurringIncome $income = null;
	/** Runs once, just before the next claim: another request getting in first */
	private ?\Closure $beforeClaim = null;
	/** @var string[] the rows booked, by their date */
	private array $booked = [];

	private function l10n(): IL10N {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);
		return $l;
	}

	private function clock(): UserClock {
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn('2026-10-20');
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
		$this->bill->setName('Claude by Anthropic');
		$this->bill->setAmount(21.6);
		$this->bill->setFrequency('monthly');
		$this->bill->setDueDay(24);
		$this->bill->setDueMonth(null);
		$this->bill->setIsActive(true);
		$this->bill->setAccountId(3);
		$this->bill->setNextDueDate('2026-10-24');
		$this->bill->setLastPaidDate('2026-09-24');
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
		$transactions->method('createFromBill')->willReturnCallback(function (string $user, Bill $bill, ?string $date = null) {
			$this->booked[] = (string)$date;
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
		$transactions->method('createFromIncome')->willReturnCallback(function (string $user, RecurringIncome $income, string $date) {
			$this->booked[] = $date;
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

	public function testAMarkPaidOfADateSkippedInAnotherTabSaysItWasPaidOrSkipped(): void {
		// Skipped in tab A; tab B still shows 24 October and sends that date
		$service = $this->bills();
		$service->skipPayment(1, 'user1', '2026-10-24');

		$this->assertSame(self::ALREADY_SETTLED, $this->refusal(fn () => $service->markPaid(1, 'user1', null, true, null, '2026-10-24')));
		$this->assertSame('2026-11-24', $this->bill->getNextDueDate());
		$this->assertSame([], $this->booked);
	}

	public function testAMarkPaidThatLosesToASkipAtOnceSaysTheSame(): void {
		$service = $this->bills();
		$this->beforeClaim = fn () => $service->skipPayment(1, 'user1', '2026-10-24');

		$this->assertSame(self::ALREADY_SETTLED, $this->refusal(fn () => $service->markPaid(1, 'user1', null, true, null, '2026-10-24')));
		$this->assertSame('2026-11-24', $this->bill->getNextDueDate());
		$this->assertSame([], $this->booked);
	}

	// ── Income ──────────────────────────────────────────────────────

	public function testAMarkReceivedOfADateSkippedInAnotherTabSaysItWasPaidOrSkipped(): void {
		$service = $this->incomes();
		$service->skipPayment(1, 'user1', '2026-09-29');

		$this->assertSame(self::ALREADY_SETTLED, $this->refusal(fn () => $service->markReceived(1, 'user1', '2026-10-20', true, '2026-09-29')));
		$this->assertSame('2026-10-29', $this->income->getNextExpectedDate());
		$this->assertSame([], $this->booked);
	}

	public function testAMarkReceivedThatLosesToASkipAtOnceSaysTheSame(): void {
		$service = $this->incomes();
		$this->beforeClaim = fn () => $service->skipPayment(1, 'user1', '2026-09-29');

		$this->assertSame(self::ALREADY_SETTLED, $this->refusal(fn () => $service->markReceived(1, 'user1', '2026-10-20', true, '2026-09-29')));
		$this->assertSame('2026-10-29', $this->income->getNextExpectedDate());
		$this->assertSame([], $this->booked);
	}
}
