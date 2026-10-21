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
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Bills by Month across the turn of the year, the cases the first round
 * missed (#399). A payment of the other year that Mark Paid would use for
 * a date of this one left that date open, so another payment near it was
 * dropped from both years; and a date paid late, weeks into the next year,
 * was owed in one year and paid in the other. Every active recurring bill
 * due last year, this year or next now has the payment of its due date
 * chosen from all of its rows, and a date paid in the other year still
 * takes a second payment made near it.
 */
class BillCalendarYearBoundaryLateTest extends TestCase {
	private const TODAY = '2026-10-20';

	private BillService $service;
	/** @var array<int, Bill> */
	private array $stored = [];
	/** @var Transaction[] */
	private array $rows = [];
	/** @var list<int[]> the bill ids of each findRecordedBillTransactions() call */
	private array $allRowQueries = [];

	protected function setUp(): void {
		$bills = $this->createMock(BillMapper::class);
		$bills->method('find')->willReturnCallback(fn (int $id) => $this->stored[$id]);
		$bills->method('findByType')->willReturnCallback(fn () => array_values($this->stored));

		// As the mapper reads them: no placeholders; the year's in date order.
		// Each query hydrates its own entities, so the calendar can't match
		// rows by identity.
		$recorded = fn (array $ids): array => array_map(fn (Transaction $tx) => clone $tx, array_values(array_filter(
			$this->rows,
			fn (Transaction $tx): bool => in_array($tx->getBillId(), $ids, true) && $tx->getStatus() !== 'scheduled'
		)));
		$transactions = $this->createMock(TransactionService::class);
		$transactions->method('findRecordedBillTransactions')->willReturnCallback(function (array $ids) use ($recorded): array {
			$this->allRowQueries[] = $ids;
			return $recorded($ids);
		});
		$transactions->method('findBillPaymentsInYear')->willReturnCallback(function (array $ids, int $year) use ($recorded): array {
			$rows = array_values(array_filter($recorded($ids), fn (Transaction $tx): bool => (int)substr($tx->getDate(), 0, 4) === $year));
			usort($rows, fn (Transaction $a, Transaction $b): int => strcmp($a->getDate(), $b->getDate()));
			return $rows;
		});

		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([]);
		$accounts->method('findByIds')->willReturn([]);
		$currency = $this->createMock(CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturn('CHF');
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn(self::TODAY);

		$this->service = new BillService(
			$bills, new FrequencyCalculator(), $this->createMock(RecurringBillDetector::class),
			$transactions, $this->createMock(IL10N::class), $accounts, $currency,
			$this->createMock(TransactionSplitService::class), $this->createMock(LoggerInterface::class),
			$this->createMock(DismissedSuggestionMapper::class), null, $this->createMock(RecurringIncomeMapper::class),
			null, $clock,
		);
	}

	private function bill(array $fields): Bill {
		$bill = new Bill();
		$bill->setId($fields['id']);
		$bill->setUserId('t399');
		$bill->setName($fields['name']);
		$bill->setAmount($fields['amount']);
		$bill->setFrequency($fields['frequency'] ?? 'monthly');
		$bill->setDueDay($fields['dueDay'] ?? null);
		$bill->setDueMonth(null);
		$bill->setStartDate(null);
		$bill->setEndDate(null);
		$bill->setNextDueDate($fields['nextDueDate'] ?? null);
		$bill->setLastPaidDate($fields['lastPaidDate'] ?? null);
		$bill->setIsActive(true);
		$bill->setIsTransfer(false);
		$bill->setAccountId(1);
		$bill->setDestinationAccountId(null);
		$bill->setRemainingPayments(null);
		$bill->setCustomRecurrencePattern(null);
		$bill->setPaidUndoState(null);
		$bill->setCreatedAt($fields['createdAt']);
		$this->stored[$fields['id']] = $bill;
		return $bill;
	}

	private function payment(int $id, int $billId, string $date, float $amount): void {
		$tx = new Transaction();
		$tx->setId($id);
		$tx->setBillId($billId);
		$tx->setAccountId(1);
		$tx->setDate($date);
		$tx->setAmount($amount);
		$tx->setType('debit');
		$tx->setStatus('cleared');
		$this->rows[] = $tx;
	}

	private function overview(int $year): array {
		return $this->service->getAnnualOverview('t399', $year, false, 'active');
	}

	private function calendarRow(array $overview, string $name): array {
		foreach ($overview['bills'] as $row) {
			if ($row['name'] === $name) {
				return $row;
			}
		}
		$this->fail("no row for $name");
	}

	private function rounded(array $amounts): array {
		return array_map(fn ($amount) => round($amount, 2), $amounts);
	}

	private function markPaidWouldUse(int $billId): ?int {
		return $this->service->recordedPaymentForPendingOccurrence($billId, 't399')['id'] ?? null;
	}

	/** Garage Miete: due on the 5th, paid monthly from June, last paid 4 December, next due 5 January 2027 */
	private function garage(): void {
		$this->bill(['id' => 8, 'name' => 'Garage Miete', 'amount' => 150.0, 'dueDay' => 5,
			'nextDueDate' => '2027-01-05', 'lastPaidDate' => '2026-12-04', 'createdAt' => '2026-06-01 10:00:00']);
		foreach (['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-04', '2026-10-05', '2026-11-05', '2026-12-04'] as $i => $date) {
			$this->payment(800 + $i, 8, $date, 150.0);
		}
	}

	/**
	 * 28 December pays 5 January (Mark Paid's choice), and 14 January paid it
	 * again: January 2027 shows the second payment, "2×", with 28 December
	 * named beside it, and counts 150 once. It was in neither year.
	 */
	public function testASecondPaymentInTheNewYearIsCounted(): void {
		$this->garage();
		$this->payment(899, 8, '2026-12-28', 150.0);
		$this->payment(900, 8, '2027-01-14', 150.0);

		$this->assertSame(899, $this->markPaidWouldUse(8));

		$view2026 = $this->overview(2026);
		$row2026 = $this->calendarRow($view2026, 'Garage Miete');
		$this->assertSame(['2026-12-05', '2027-01-05'], array_column($row2026['paidDetails'][12], 'due'));
		$this->assertEqualsWithDelta(300.0, $view2026['monthlyTotals'][12], 0.001);

		$view2027 = $this->overview(2027);
		$row2027 = $this->calendarRow($view2027, 'Garage Miete');
		$this->assertSame([['date' => '2027-01-14', 'amount' => 150.0, 'due' => '2027-01-05']], $row2027['paidDetails'][1]);
		$this->assertSame([1 => [['date' => '2026-12-28', 'amount' => 150.0, 'due' => '2027-01-05']]], $row2027['paidElsewhere']);
		$this->assertSame([1 => 2], $row2027['paidMoreThanOnce']);
		$this->assertArrayNotHasKey(1, $row2027['owedAmounts']);
		$this->assertSame([], $row2027['unrecordedMonths']);
		$this->assertEqualsWithDelta(150.0, $view2027['monthlyTotals'][1], 0.001, 'the 14 January payment, once');
	}

	/**
	 * Due on 10 December and paid 26 days late, on 5 January: 2026 shows
	 * 10 December paid in 2027, not owed; 2027 shows the payment as the one
	 * for 10 December, beside 10 January still owed.
	 */
	public function testAMonthlyBillPaidLateInTheNewYear(): void {
		$this->bill(['id' => 10, 'name' => 'Strom', 'amount' => 100.0, 'dueDay' => 10,
			'nextDueDate' => '2026-12-10', 'lastPaidDate' => '2026-11-10', 'createdAt' => '2026-09-01 10:00:00']);
		$this->payment(1001, 10, '2026-09-10', 100.0);
		$this->payment(1002, 10, '2026-10-10', 100.0);
		$this->payment(1003, 10, '2026-11-10', 100.0);
		$this->payment(1004, 10, '2027-01-05', 100.0);

		$this->assertSame(1004, $this->markPaidWouldUse(10));

		$this->allRowQueries = [];
		$view2026 = $this->overview(2026);
		$row2026 = $this->calendarRow($view2026, 'Strom');
		$this->assertSame([[10]], $this->allRowQueries);
		$this->assertSame([12 => [['date' => '2027-01-05', 'amount' => 100.0, 'due' => '2026-12-10']]], $row2026['paidElsewhere']);
		$this->assertSame([], $row2026['owedAmounts']);
		$this->assertEqualsWithDelta(0.0, $view2026['monthlyTotals'][12], 0.001);

		$view2027 = $this->overview(2027);
		$row2027 = $this->calendarRow($view2027, 'Strom');
		$this->assertSame([['date' => '2027-01-05', 'amount' => 100.0, 'due' => '2026-12-10']], $row2027['paidDetails'][1]);
		$this->assertSame([], $row2027['paidElsewhere']);
		$this->assertEqualsWithDelta(100.0, $row2027['owedAmounts'][1], 0.001, '10 January');
		$this->assertEqualsWithDelta(200.0, $view2027['monthlyTotals'][1], 0.001);
	}

	/** A weekly bill due Friday 1 January and paid on 30 December: January 2027 owes only its other Fridays. */
	public function testAWeeklyBillPaidInDecemberForNewYearsDay(): void {
		$this->bill(['id' => 11, 'name' => 'Putzfrau', 'amount' => 40.0, 'frequency' => 'weekly',
			'nextDueDate' => '2027-01-01', 'lastPaidDate' => '2026-12-25', 'createdAt' => '2026-12-01 10:00:00']);
		foreach (['2026-12-04', '2026-12-11', '2026-12-18', '2026-12-25'] as $i => $date) {
			$this->payment(1100 + $i, 11, $date, 40.0);
		}
		$this->payment(1199, 11, '2026-12-30', 40.0);

		$this->assertSame(1199, $this->markPaidWouldUse(11));

		$row2026 = $this->calendarRow($this->overview(2026), 'Putzfrau');
		$this->assertSame(['date' => '2026-12-30', 'amount' => 40.0, 'due' => '2027-01-01'], end($row2026['paidDetails'][12]));

		$view2027 = $this->overview(2027);
		$row2027 = $this->calendarRow($view2027, 'Putzfrau');
		$this->assertSame([1 => [['date' => '2026-12-30', 'amount' => 40.0, 'due' => '2027-01-01']]], $row2027['paidElsewhere']);
		$this->assertEqualsWithDelta(160.0, $row2027['owedAmounts'][1], 0.001, 'four Fridays still to pay');
		$this->assertEqualsWithDelta(160.0, $view2027['monthlyTotals'][1], 0.001);
	}

	/**
	 * A weekly bill due Saturday 26 December and paid a week late, on
	 * 2 January: 2026 owes nothing for 26 December, and 2027 shows the
	 * payment as the one for it.
	 */
	public function testAWeeklyBillPaidLateInTheNewYear(): void {
		$this->bill(['id' => 12, 'name' => 'Gärtner', 'amount' => 40.0, 'frequency' => 'weekly',
			'nextDueDate' => '2026-12-26', 'lastPaidDate' => '2026-12-19', 'createdAt' => '2026-12-01 10:00:00']);
		foreach (['2026-12-05', '2026-12-12', '2026-12-19'] as $i => $date) {
			$this->payment(1200 + $i, 12, $date, 40.0);
		}
		$this->payment(1299, 12, '2027-01-02', 40.0);

		$this->assertSame(1299, $this->markPaidWouldUse(12));

		$view2026 = $this->overview(2026);
		$row2026 = $this->calendarRow($view2026, 'Gärtner');
		$this->assertSame([12 => [['date' => '2027-01-02', 'amount' => 40.0, 'due' => '2026-12-26']]], $row2026['paidElsewhere']);
		$this->assertSame([], $row2026['owedAmounts']);
		$this->assertEqualsWithDelta(120.0, $view2026['monthlyTotals'][12], 0.001, 'the three December payments');

		$view2027 = $this->overview(2027);
		$row2027 = $this->calendarRow($view2027, 'Gärtner');
		$this->assertSame([['date' => '2027-01-02', 'amount' => 40.0, 'due' => '2026-12-26']], $row2027['paidDetails'][1]);
		$this->assertSame([], $row2027['paidElsewhere']);
	}

	/** One-time bills, and recurring ones due years from the viewed one, are not read beyond it. */
	public function testOnlyRecurringBillsDueNearTheYearAreReadInFull(): void {
		$this->bill(['id' => 13, 'name' => 'Far', 'amount' => 10.0, 'dueDay' => 1,
			'nextDueDate' => '2029-01-01', 'createdAt' => '2026-01-01 10:00:00']);
		$this->bill(['id' => 14, 'name' => 'Near', 'amount' => 10.0, 'dueDay' => 1,
			'nextDueDate' => '2025-11-01', 'createdAt' => '2025-01-01 10:00:00']);
		$this->bill(['id' => 15, 'name' => 'Once', 'amount' => 10.0, 'frequency' => 'one-time', 'dueDay' => 1,
			'nextDueDate' => '2026-11-01', 'createdAt' => '2026-01-01 10:00:00']);

		$this->overview(2026);

		$this->assertSame([[14]], $this->allRowQueries);
	}
}
