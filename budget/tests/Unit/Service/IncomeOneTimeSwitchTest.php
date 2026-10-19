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
 * Switching a received income to One-Time keeps it received (#399), as for
 * bills: the switch put the income on its new date as expected whatever had
 * arrived, and Mark Received then booked the money a second time. A date the
 * income has already received, by its last receipt or a credit it booked
 * within the expected window, is now settled; one still to come stays
 * expected.
 */
class IncomeOneTimeSwitchTest extends TestCase {
	private const TODAY = '2026-10-19';

	private RecurringIncomeService $service;
	private ?RecurringIncome $stored = null;
	/** @var Transaction[] credits the app booked into the income's account */
	private array $credits = [];
	/** @var int[] credits Mark Unreceived deleted */
	private array $deleted = [];

	protected function setUp(): void {
		$mapper = $this->createMock(RecurringIncomeMapper::class);
		$mapper->method('update')->willReturnCallback(function (RecurringIncome $income) {
			$this->stored = $income;
			return $income;
		});
		$mapper->method('find')->willReturnCallback(fn () => $this->stored);
		$mapper->method('updateFields')->willReturnCallback(function (int $id, string $user, array $fields) {
			foreach ($fields as $column => $value) {
				$this->stored->{'set' . str_replace('_', '', ucwords($column, '_'))}($value);
			}
		});

		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findGeneratedIncomeCredits')->willReturnCallback(
			fn (int $account, string $from, string $to) => array_values(array_filter(
				$this->credits,
				fn (Transaction $tx) => $tx->getAccountId() === $account && $tx->getDate() >= $from && $tx->getDate() <= $to
			))
		);
		$transactions->method('findTransaction')->willReturnCallback(function (int $id) {
			foreach ($this->credits as $tx) {
				if ($tx->getId() === $id) {
					return $tx;
				}
			}
			return null;
		});
		$transactions->method('deleteAsAccountOwner')->willReturnCallback(function (int $id) {
			$this->deleted[] = $id;
			return true;
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

	private function income(array $fields): RecurringIncome {
		$this->credits = [];
		$income = new RecurringIncome();
		$income->setId(1);
		$income->setUserId('user1');
		$income->setName('Swisscom Abo Leon');
		$income->setAmount(14.90);
		$income->setFrequency($fields['frequency'] ?? 'monthly');
		$income->setExpectedDay($fields['expectedDay'] ?? 20);
		$income->setExpectedMonth(null);
		$income->setIsActive($fields['isActive'] ?? true);
		$income->setAutoCreateEnabled(false);
		$income->setAccountId(7);
		$income->setLastReceivedDate($fields['lastReceivedDate'] ?? null);
		$income->setNextExpectedDate(array_key_exists('nextExpectedDate', $fields) ? $fields['nextExpectedDate'] : '2026-11-20');
		$income->setStartDate($fields['startDate'] ?? null);
		$this->stored = $income;
		return $income;
	}

	/** A credit the app booked for an income, as Mark Received or auto-create do */
	private function credit(int $id, string $date, string $incomeName = 'Swisscom Abo Leon'): Transaction {
		$tx = new Transaction();
		$tx->setId($id);
		$tx->setAccountId(7);
		$tx->setDate($date);
		$tx->setAmount(14.90);
		$tx->setType('credit');
		$tx->setStatus('cleared');
		$tx->setNotes('Auto-generated from income: ' . $incomeName);
		$this->credits[] = $tx;
		return $tx;
	}

	/** The undo snapshot Mark Received leaves for the occurrence due $occurrence */
	private function receivedSnapshot(string $occurrence, array $transactionIds): string {
		return json_encode([
			'nextExpectedDate' => $occurrence,
			'lastReceivedDate' => null,
			'isActive' => true,
			'startDate' => null,
			'transactionIds' => $transactionIds,
		]);
	}

	/** What the income form sends for a one-time income on $date */
	private function oneTime(string $date): array {
		return ['frequency' => 'one-time', 'startDate' => $date, 'expectedDay' => 20, 'expectedMonth' => null];
	}

	/** Monthly on the 20th, October's payment received on the 19th */
	private function receivedInOctober(): RecurringIncome {
		$income = $this->income(['lastReceivedDate' => '2026-10-19', 'nextExpectedDate' => '2026-11-20']);
		$this->credit(301, '2026-10-19');
		$income->setReceivedUndoState($this->receivedSnapshot('2026-10-20', [301]));
		return $income;
	}

	public function testSwitchedToOneTimeOnTheOccurrenceItReceivedIsCompleted(): void {
		$this->receivedInOctober();

		$income = $this->service->update(1, 'user1', $this->oneTime('2026-10-20'));

		$this->assertFalse($income->getIsActive());
		$this->assertNull($income->getNextExpectedDate());
		$this->assertSame('2026-10-19', $income->getLastReceivedDate());
		$this->assertSame('2026-10-20', $income->getStartDate());
		$this->assertTrue($income->canUndoReceived(), 'Undo still takes the 19 October credit back');
	}

	public function testSwitchedToOneTimeNextMonthStaysExpected(): void {
		$this->receivedInOctober();

		$income = $this->service->update(1, 'user1', $this->oneTime('2026-11-20'));

		$this->assertTrue($income->getIsActive());
		$this->assertSame('2026-11-20', $income->getNextExpectedDate());
		// Undoing October's receipt would put the income back on 20 October
		$this->assertFalse($income->canUndoReceived());
	}

	public function testAnIncomeNeverReceivedSwitchedToOneTimeIsExpected(): void {
		$this->income(['nextExpectedDate' => '2026-10-20']);

		$income = $this->service->update(1, 'user1', $this->oneTime('2026-10-20'));

		$this->assertTrue($income->getIsActive());
		$this->assertSame('2026-10-20', $income->getNextExpectedDate());
	}

	public function testACreditMovedToItsBankDateSettlesThatDate(): void {
		// Marked received on 19 October, the credit then moved to the 3rd the
		// bank shows. The click date is too far off; the credit isn't.
		$income = $this->income(['expectedDay' => 3, 'lastReceivedDate' => '2026-10-19', 'nextExpectedDate' => '2026-11-03']);
		$this->credit(302, '2026-10-03');
		$income->setReceivedUndoState($this->receivedSnapshot('2026-10-03', [302]));

		$income = $this->service->update(1, 'user1', ['frequency' => 'one-time', 'startDate' => '2026-10-03']);

		$this->assertFalse($income->getIsActive());
		$this->assertNull($income->getNextExpectedDate());
		$this->assertTrue($income->canUndoReceived());
	}

	public function testAnotherIncomesCreditSettlesNothing(): void {
		$this->income(['expectedDay' => 3, 'lastReceivedDate' => '2026-08-03', 'nextExpectedDate' => '2026-10-03']);
		$this->credit(303, '2026-10-03', 'Gutschrift SBB Abo Leon');

		$income = $this->service->update(1, 'user1', ['frequency' => 'one-time', 'startDate' => '2026-10-03']);

		$this->assertTrue($income->getIsActive());
		$this->assertSame('2026-10-03', $income->getNextExpectedDate());
	}

	public function testUndoAfterTheSwitchReopensTheIncomeOnItsNewDate(): void {
		// The snapshot was taken under the monthly schedule, which had no
		// start date: restored as it was, the one-time income lost its date
		$this->receivedInOctober();
		$this->service->update(1, 'user1', $this->oneTime('2026-10-20'));

		$income = $this->service->markUnreceived(1, 'user1');

		$this->assertSame([301], $this->deleted);
		$this->assertTrue($income->getIsActive());
		$this->assertSame('one-time', $income->getFrequency());
		$this->assertSame('2026-10-20', $income->getNextExpectedDate());
		$this->assertSame('2026-10-20', $income->getStartDate());
		$this->assertNull($income->getLastReceivedDate());
	}
}
