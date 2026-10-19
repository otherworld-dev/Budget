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
 * The credit an income's expected payment already has in the ledger (#399).
 *
 * The reporter's SBB Abo was left by 2.54.0 still expecting 29 September
 * with that day's credit booked, and one Mark Received booked another 100.
 * The credit is now found first, and Mark Received asks before booking one.
 */
class IncomeRecordedReceiptTest extends TestCase {
	private const TODAY = '2026-10-19';
	private const NOTE = 'Auto-generated from income: Gutschrift SBB Abo Leon';

	private RecurringIncomeService $service;
	private ?RecurringIncome $stored = null;
	/** @var Transaction[] credits the app booked in the account */
	private array $credits = [];
	/** @var string[] credits booked by the test */
	private array $booked = [];
	/** @var string[] who can write to the income's account */
	private array $writers = ['user1'];

	protected function setUp(): void {
		$mapper = $this->createMock(RecurringIncomeMapper::class);
		$mapper->method('find')->willReturnCallback(fn () => clone $this->stored);
		$mapper->method('update')->willReturnCallback(function (RecurringIncome $income) {
			foreach (array_keys($income->getUpdatedFields()) as $property) {
				$this->stored->{'set' . ucfirst($property)}($income->{'get' . ucfirst($property)}());
			}
			return $income;
		});
		$mapper->method('claimOccurrence')->willReturnCallback(function (int $id, string $user, string $expected, array $fields) {
			if (!$this->stored->getIsActive() || $this->stored->getNextExpectedDate() !== $expected) {
				return false;
			}
			foreach ($fields as $column => $value) {
				$this->stored->{'set' . str_replace('_', '', ucwords($column, '_'))}($value);
			}
			return true;
		});

		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findGeneratedIncomeCredits')->willReturnCallback(
			fn (int $account, string $from, string $to) => array_values(array_filter(
				$this->credits,
				fn (Transaction $c) => $c->getAccountId() === $account && $c->getDate() >= $from && $c->getDate() <= $to
			))
		);
		$transactions->method('createFromIncome')->willReturnCallback(function (string $user, RecurringIncome $income, ?string $date = null) {
			$this->booked[] = (string)$date;
			$tx = new Transaction();
			$tx->setId(800 + count($this->booked));
			return $tx;
		});

		$shares = $this->createMock(GranularShareService::class);
		$shares->method('canWrite')->willReturnCallback(fn (string $user) => in_array($user, $this->writers, true));
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
			$shares,
		);
	}

	private function income(array $fields = []): RecurringIncome {
		$income = new RecurringIncome();
		$income->setId(1);
		$income->setUserId('user1');
		$income->setName('Gutschrift SBB Abo Leon');
		$income->setAmount(100.0);
		$income->setFrequency($fields['frequency'] ?? 'monthly');
		$income->setExpectedDay(29);
		$income->setIsActive($fields['isActive'] ?? true);
		$income->setAccountId(7);
		$income->setLastReceivedDate(array_key_exists('lastReceivedDate', $fields) ? $fields['lastReceivedDate'] : '2026-09-28');
		$income->setNextExpectedDate(array_key_exists('nextExpectedDate', $fields) ? $fields['nextExpectedDate'] : '2026-09-29');
		$income->setStartDate($fields['startDate'] ?? null);
		$income->setReceivedUndoState($fields['receivedUndoState'] ?? null);
		$income->resetUpdatedFields();
		$this->stored = $income;
		return $income;
	}

	private function credit(int $id, string $date, array $fields = []): Transaction {
		$credit = new Transaction();
		$credit->setId($id);
		$credit->setAccountId(7);
		$credit->setDate($date);
		$credit->setAmount($fields['amount'] ?? 100.0);
		$credit->setType('credit');
		$credit->setStatus($fields['status'] ?? 'cleared');
		$credit->setNotes($fields['notes'] ?? self::NOTE);
		$this->credits[] = $credit;
		return $credit;
	}

	public function testTheCreditOf2540sEarlyReceiptIsReported(): void {
		// Received on the 28th for the 29th, the credit dated the 29th, the
		// date never moved on
		$this->income();
		$this->credit(1350741, '2026-09-29');

		$this->assertSame(
			['id' => 1350741, 'date' => '2026-09-29', 'amount' => 100.0],
			$this->service->recordedReceiptForPendingOccurrence(1, 'user1')
		);
	}

	public function testOnlyThisIncomesCreditsCount(): void {
		$this->income();
		$this->credit(1350741, '2026-09-29', ['notes' => 'Auto-generated from income: Gutschrift Swisscom Abo Leon']);

		$this->assertNull($this->service->recordedReceiptForPendingOccurrence(1, 'user1'));
	}

	public function testTheLastReceiptsCreditIsNotTheNextOnes(): void {
		$this->income(['lastReceivedDate' => '2026-09-29', 'nextExpectedDate' => '2026-10-29']);
		$this->credit(1350741, '2026-09-29');

		$this->assertNull($this->service->recordedReceiptForPendingOccurrence(1, 'user1'));
	}

	public function testACreditTheLastReceiptBookedIsNotReported(): void {
		// Received early on the 12th for the 29th, its credit moved to the
		// 16th: it is October's, as the snapshot says
		$snapshot = json_encode(['nextExpectedDate' => '2026-10-29', 'transactionIds' => [55]]);
		$this->income(['lastReceivedDate' => '2026-10-12', 'nextExpectedDate' => '2026-11-29', 'receivedUndoState' => $snapshot]);
		$this->credit(55, '2026-11-16');

		$this->assertNull($this->service->recordedReceiptForPendingOccurrence(1, 'user1'));
	}

	public function testAScheduledCreditIsNoReceipt(): void {
		$this->income();
		$this->credit(1350741, '2026-09-29', ['status' => 'scheduled']);

		$this->assertNull($this->service->recordedReceiptForPendingOccurrence(1, 'user1'));
	}

	public function testAOneTimeIncomeReportsAnyCreditItHas(): void {
		$this->income(['frequency' => 'one-time', 'startDate' => '2026-09-29', 'lastReceivedDate' => '2026-09-29']);
		$this->credit(1350741, '2026-09-29');

		$this->assertSame(1350741, $this->service->recordedReceiptForPendingOccurrence(1, 'user1')['id']);
	}

	public function testAMonthlyIncomeSwitchedToOneTimeForNextMonthIsNotFlagged(): void {
		// Received on 19 October, then switched to One-Time on 20 November:
		// October's credit isn't November's
		$this->income(['frequency' => 'one-time', 'startDate' => '2026-11-20', 'lastReceivedDate' => '2026-10-19', 'nextExpectedDate' => '2026-11-20']);
		$this->credit(1350741, '2026-10-19');

		$this->assertNull($this->service->recordedReceiptForPendingOccurrence(1, 'user1', '2026-11-20'));
	}

	public function testNothingIsReportedForAStalePageOrAnIncomeNotExpected(): void {
		$this->income();
		$this->credit(1350741, '2026-09-29');
		$this->assertNull($this->service->recordedReceiptForPendingOccurrence(1, 'user1', '2026-08-29'));

		$this->income(['isActive' => false, 'nextExpectedDate' => null]);
		$this->assertNull($this->service->recordedReceiptForPendingOccurrence(1, 'user1'));
	}

	public function testNothingIsShownToSomeoneWhoCannotWriteToTheAccount(): void {
		// Mark Received refuses them, and the credit is in a ledger they
		// may not see
		$this->income();
		$this->credit(1350741, '2026-09-29');

		$this->assertNull($this->service->recordedReceiptForPendingOccurrence(1, 'user1', null, 'bob'));
		$this->writers[] = 'bob';
		$this->assertNotNull($this->service->recordedReceiptForPendingOccurrence(1, 'user1', null, 'bob'));
	}

	public function testUsingTheCreditAlreadyThereBooksNothing(): void {
		$this->income();
		$this->credit(1350741, '2026-09-29');

		$this->service->markReceived(1, 'user1', '2026-09-29', false, '2026-09-29');

		$this->assertSame([], $this->booked);
		$this->assertSame('2026-10-29', $this->stored->getNextExpectedDate());
		$this->assertSame('2026-09-29', $this->stored->getLastReceivedDate());
	}
}
