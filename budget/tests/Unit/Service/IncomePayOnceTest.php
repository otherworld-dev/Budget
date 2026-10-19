<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\UserClock;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Mark Received, Skip and auto-create settle each payment once (#399).
 *
 * Three simultaneous Mark Received booked three credits, and two skips of
 * one payment both went through. The occurrence is now claimed with one
 * conditional write before the credit is booked. The mapper here behaves
 * as the real one does (see BillPayOnceTest).
 */
class IncomePayOnceTest extends TestCase {
	private const TODAY = '2026-10-19';
	private const ALREADY_RECORDED = 'This payment was already recorded. Reload the page to see the next one.';

	private RecurringIncomeService $service;
	private ?RecurringIncome $stored = null;
	/** @var string[] credits booked, by date */
	private array $booked = [];
	/** Runs once, just before the next claim: another request getting in first */
	private ?\Closure $beforeClaim = null;
	/** Runs as the credit is booked */
	private ?\Closure $whileBooking = null;
	private ?\Throwable $bookingFails = null;

	protected function setUp(): void {
		$mapper = $this->createMock(RecurringIncomeMapper::class);
		$mapper->method('find')->willReturnCallback(fn () => clone $this->stored);
		$mapper->method('update')->willReturnCallback(function (RecurringIncome $income) {
			foreach (array_keys($income->getUpdatedFields()) as $property) {
				$this->stored->{'set' . ucfirst($property)}($income->{'get' . ucfirst($property)}());
			}
			return $income;
		});
		$mapper->method('updateFields')->willReturnCallback(fn (int $id, string $user, array $fields) => $this->write($fields));
		$mapper->method('claimOccurrence')->willReturnCallback(function (int $id, string $user, string $expected, array $fields) {
			if ($this->beforeClaim !== null) {
				$other = $this->beforeClaim;
				$this->beforeClaim = null;
				$other();
			}
			if (!$this->stored->getIsActive() || $this->stored->getNextExpectedDate() !== $expected) {
				return false;
			}
			$this->write($fields);
			return true;
		});

		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('createFromIncome')->willReturnCallback(function (string $user, RecurringIncome $income, ?string $date = null) {
			if ($this->whileBooking !== null) {
				($this->whileBooking)();
			}
			if ($this->bookingFails !== null) {
				throw $this->bookingFails;
			}
			$this->booked[] = (string)$date;
			$tx = new Transaction();
			$tx->setId(700 + count($this->booked));
			return $tx;
		});

		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnArgument(0);

		$this->service = new RecurringIncomeService(
			$mapper,
			new FrequencyCalculator(),
			$this->createMock(RecurringIncomeDetector::class),
			$transactions,
			$this->createMock(LoggerInterface::class),
			$l,
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

	private function income(array $fields = []): RecurringIncome {
		$income = new RecurringIncome();
		$income->setId(1);
		$income->setUserId('user1');
		$income->setName('Gutschrift SBB Abo Leon');
		$income->setAmount(100.0);
		$income->setFrequency($fields['frequency'] ?? 'monthly');
		$income->setExpectedDay(29);
		$income->setExpectedMonth(null);
		$income->setIsActive(true);
		$income->setAutoCreateEnabled($fields['autoCreateEnabled'] ?? false);
		$income->setAccountId(7);
		$income->setLastReceivedDate('2026-09-28');
		$income->setNextExpectedDate($fields['nextExpectedDate'] ?? '2026-09-29');
		$income->setStartDate(null);
		$income->setReceivedUndoState($fields['receivedUndoState'] ?? null);
		$income->resetUpdatedFields();
		$this->stored = $income;
		return $income;
	}

	// ── Mark Received ───────────────────────────────────────────────

	public function testAReceiptThatLosesTheClaimBooksNothing(): void {
		$this->income();
		$this->beforeClaim = function () {
			$this->stored->setNextExpectedDate('2026-10-29');
		};

		try {
			$this->service->markReceived(1, 'user1', self::TODAY, true, '2026-09-29');
			$this->fail('September was received a second time');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame(self::ALREADY_RECORDED, $e->getMessage());
		}

		$this->assertSame([], $this->booked);
	}

	public function testTwoSimultaneousReceiptsBookOneCredit(): void {
		$this->income();
		$this->beforeClaim = fn () => $this->service->markReceived(1, 'user1', self::TODAY, true, '2026-09-29');

		try {
			$this->service->markReceived(1, 'user1', self::TODAY, true, '2026-09-29');
			$this->fail('September was received twice');
		} catch (\InvalidArgumentException $e) {
		}

		$this->assertSame([self::TODAY], $this->booked);
		$this->assertSame('2026-10-29', $this->stored->getNextExpectedDate());
		$this->assertSame(self::TODAY, $this->stored->getLastReceivedDate());
	}

	public function testTheReceiptIsClaimedBeforeItsCreditIsBooked(): void {
		$this->income();
		$seen = null;
		$this->whileBooking = function () use (&$seen) {
			$seen = [$this->stored->getNextExpectedDate(), $this->stored->getLastReceivedDate()];
		};

		$this->service->markReceived(1, 'user1', self::TODAY, true, '2026-09-29');

		$this->assertSame(['2026-10-29', self::TODAY], $seen, 'The income had moved on while the credit booked');
		$this->assertNotNull($this->stored->getReceivedUndoState(), 'Mark Unreceived still has its snapshot');
	}

	public function testTheReceiptNeverWritesTheClaimedFieldsAgain(): void {
		// Written again with the snapshot, they would put back an income
		// another request had moved on while this one booked its credit
		$this->income();
		$this->whileBooking = function () {
			$this->stored->setNextExpectedDate('2026-11-29');
		};

		$this->service->markReceived(1, 'user1', self::TODAY, true, '2026-09-29');

		$this->assertSame('2026-11-29', $this->stored->getNextExpectedDate());
	}

	// ── Skip ────────────────────────────────────────────────────────

	public function testASkipOfAPaymentAlreadyMovedOnIsRefused(): void {
		$this->income(['nextExpectedDate' => '2026-10-29']);

		try {
			$this->service->skipPayment(1, 'user1', '2026-09-29');
			$this->fail('The stale skip went through');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame(self::ALREADY_RECORDED, $e->getMessage());
		}

		$this->assertSame('2026-10-29', $this->stored->getNextExpectedDate());
	}

	public function testTwoSkipsOfOnePaymentSkipItOnce(): void {
		$this->income();
		$this->beforeClaim = fn () => $this->service->skipPayment(1, 'user1', '2026-09-29');

		try {
			$this->service->skipPayment(1, 'user1', '2026-09-29');
			$this->fail('September was skipped twice');
		} catch (\InvalidArgumentException $e) {
			$this->assertSame(self::ALREADY_RECORDED, $e->getMessage());
		}

		$this->assertSame('2026-10-29', $this->stored->getNextExpectedDate());
	}

	public function testASkipWithoutADateSkipsThePendingPayment(): void {
		// Older pages send no date
		$this->income(['receivedUndoState' => '{"nextExpectedDate":"2026-08-29"}']);

		$result = $this->service->skipPayment(1, 'user1');

		$this->assertSame('2026-09-29', $result['previousNextExpectedDate']);
		$this->assertSame('2026-10-29', $this->stored->getNextExpectedDate());
		$this->assertNull($this->stored->getReceivedUndoState());
	}

	// ── auto-create ─────────────────────────────────────────────────

	public function testAutoCreateThatLosesTheClaimBooksNothingAndStaysOn(): void {
		// The owner pressed Mark Received while the job was booking it
		$this->income(['autoCreateEnabled' => true]);
		$this->beforeClaim = function () {
			$this->stored->setNextExpectedDate('2026-10-29');
		};

		$result = $this->service->processAutoCreate(1, 'user1');

		$this->assertFalse($result['success']);
		$this->assertFalse($result['disabled']);
		$this->assertSame([], $this->booked);
		$this->assertTrue($this->stored->getAutoCreateEnabled());
	}

	public function testAutoCreateWhoseCreditFailsHandsThePaymentBack(): void {
		$this->income(['autoCreateEnabled' => true]);
		$this->bookingFails = new \RuntimeException('account gone');

		$result = $this->service->processAutoCreate(1, 'user1');

		$this->assertFalse($result['success']);
		$this->assertTrue($result['disabled']);
		$this->assertSame('2026-09-29', $this->stored->getNextExpectedDate(), 'Still expected: nothing was booked');
		$this->assertSame('2026-09-28', $this->stored->getLastReceivedDate());
		$this->assertFalse($this->stored->getAutoCreateEnabled());
	}

	public function testAutoCreateBooksEachDuePaymentOnce(): void {
		$this->income(['autoCreateEnabled' => true, 'nextExpectedDate' => '2026-08-29']);

		$result = $this->service->processAutoCreate(1, 'user1');

		$this->assertSame(2, $result['count']);
		$this->assertSame(['2026-08-29', '2026-09-29'], $this->booked);
		$this->assertSame('2026-10-29', $this->stored->getNextExpectedDate());
		$this->assertSame('2026-09-29', $this->stored->getLastReceivedDate());
	}
}
