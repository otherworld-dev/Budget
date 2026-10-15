<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
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
use OCP\IL10N;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BillServiceTest extends TestCase {
	private BillService $service;
	private BillMapper $mapper;
	private FrequencyCalculator $frequencyCalculator;
	private RecurringBillDetector $recurringDetector;
	private TransactionService $transactionService;
	private AccountMapper $accountMapper;
	private DismissedSuggestionMapper $dismissedMapper;
	private RecurringIncomeMapper $incomeMapper;

	protected function setUp(): void {
		$this->mapper = $this->createMock(BillMapper::class);
		$this->frequencyCalculator = $this->createMock(FrequencyCalculator::class);
		$this->recurringDetector = $this->createMock(RecurringBillDetector::class);
		$this->transactionService = $this->createMock(TransactionService::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string)$param, $text);
			}
			return $text;
		});
		$this->accountMapper = $this->createMock(AccountMapper::class);
		$currencyConversion = $this->createMock(CurrencyConversionService::class);
		$splitService = $this->createMock(TransactionSplitService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$this->dismissedMapper = $this->createMock(DismissedSuggestionMapper::class);
		$this->incomeMapper = $this->createMock(RecurringIncomeMapper::class);
		// The pure schedule questions run for real; tests pin calculateNextDueDate
		foreach (['occurrenceOnOrAfter', 'occurrenceAfter', 'occurrenceBefore', 'occurrencesBetween', 'periodStart', 'reschedule'] as $method) {
			$this->frequencyCalculator->method($method)
				->willReturnCallback(fn (...$args) => (new FrequencyCalculator())->$method(...$args));
		}
		$this->service = new BillService(
			$this->mapper,
			$this->frequencyCalculator,
			$this->recurringDetector,
			$this->transactionService,
			$l,
			$this->accountMapper,
			$currencyConversion,
			$splitService,
			$logger,
			$this->dismissedMapper,
			null,
			$this->incomeMapper
		);
	}

	public function testSharedBillsArePricedFromTheirOwnersAccounts(): void {
		$usd = new Account();
		$usd->setId(5);
		$usd->setCurrency('USD');
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturnMap([['owner1', [$usd]], ['owner2', []]]);
		$currency = $this->createMock(CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturnMap([['owner1', 'GBP'], ['owner2', 'EUR']]);
		$service = new BillService(
			$this->mapper,
			$this->frequencyCalculator,
			$this->recurringDetector,
			$this->transactionService,
			$this->createMock(IL10N::class),
			$accounts,
			$currency,
			$this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class),
			$this->dismissedMapper,
		);

		// Each is priced as its owner sees it: their account, else their base
		$bills = $service->enrichSharedBillsWithCurrency([
			['id' => 1, 'userId' => 'owner1', 'accountId' => 5],
			['id' => 2, 'userId' => 'owner1', 'accountId' => null],
			['id' => 3, 'userId' => 'owner2', 'accountId' => 7],
		]);

		$this->assertSame(['USD', 'GBP', 'EUR'], array_column($bills, 'currency'));
	}

	/**
	 * The Bills page cards counted only the user's own bills while the list
	 * under them showed shared ones too, so a shared overdue bill was in the
	 * list but the Overdue card said 0.
	 */
	public function testMonthlySummaryCountsSharedBills(): void {
		$own = $this->makeBill(['id' => 1, 'userId' => 'user1', 'amount' => 10.0, 'nextDueDate' => date('Y-m-t'), 'accountId' => null]);
		$shared = $this->makeBill(['id' => 2, 'userId' => 'owner1', 'amount' => 120.0, 'nextDueDate' => date('Y-m-d', strtotime('-40 days')), 'accountId' => 5]);
		$inactive = $this->makeBill(['id' => 3, 'userId' => 'owner1', 'amount' => 99.0, 'isActive' => false, 'nextDueDate' => date('Y-m-d', strtotime('-40 days'))]);
		$usd = new Account();
		$usd->setId(5);
		$usd->setCurrency('USD');
		$mapper = $this->createMock(BillMapper::class);
		$mapper->method('findActive')->with('user1')->willReturn([$own]);
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturnMap([['user1', []], ['owner1', [$usd]]]);
		$currency = $this->createMock(CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturn('GBP');
		// The shared bill is priced in its owner's account currency, then
		// converted at the viewer's rates
		$currency->expects($this->atLeastOnce())->method('convertToBaseFloat')
			->with($this->anything(), 'USD', 'user1')->willReturnCallback(fn (float $amount) => $amount / 2);
		$frequency = $this->createMock(FrequencyCalculator::class);
		$frequency->method('getMonthlyEquivalent')->willReturnCallback(fn (Bill $bill) => (float)$bill->getAmount());
		$service = new BillService(
			$mapper,
			$frequency,
			$this->recurringDetector,
			$this->transactionService,
			$this->createMock(IL10N::class),
			$accounts,
			$currency,
			$this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class),
			$this->dismissedMapper,
		);

		$summary = $service->getMonthlySummary('user1', [$shared, $inactive]);

		$this->assertSame(2, $summary['billCount']);
		$this->assertSame(1, $summary['overdue']);
		$this->assertSame(70.0, $summary['monthlyTotal']);
	}

	private function makeBill(array $overrides = []): Bill {
		$bill = new Bill();
		$bill->setId($overrides['id'] ?? 1);
		$bill->setUserId($overrides['userId'] ?? 'user1');
		$bill->setName($overrides['name'] ?? 'Netflix');
		$bill->setAmount($overrides['amount'] ?? 15.99);
		$bill->setFrequency($overrides['frequency'] ?? 'monthly');
		$bill->setDueDay($overrides['dueDay'] ?? 15);
		$bill->setDueMonth($overrides['dueMonth'] ?? null);
		$bill->setIsActive($overrides['isActive'] ?? true);
		$bill->setAccountId($overrides['accountId'] ?? 1);
		$bill->setNextDueDate(array_key_exists('nextDueDate', $overrides) ? $overrides['nextDueDate'] : '2099-06-15');
		$bill->setAutoPayEnabled($overrides['autoPayEnabled'] ?? false);
		$bill->setAutoPayFailed($overrides['autoPayFailed'] ?? false);
		$bill->setLastPaidDate($overrides['lastPaidDate'] ?? null);
		$bill->setRemainingPayments($overrides['remainingPayments'] ?? null);
		$bill->setEndDate($overrides['endDate'] ?? null);
		$bill->setCustomRecurrencePattern($overrides['customRecurrencePattern'] ?? null);
		$bill->setIsTransfer($overrides['isTransfer'] ?? false);
		$bill->setDestinationAccountId($overrides['destinationAccountId'] ?? null);
		$bill->setAutoDetectPattern($overrides['autoDetectPattern'] ?? null);
		$bill->setStartDate($overrides['startDate'] ?? null);
		$bill->setCreatedAt($overrides['createdAt'] ?? '2024-01-01 00:00:00');
		if (array_key_exists('createTransaction', $overrides)) {
			$bill->setCreateTransaction($overrides['createTransaction']);
		}
		return $bill;
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateBasicBill(): void {
		$expected = (new FrequencyCalculator())->occurrenceOnOrAfter('monthly', 1, null, date('Y-m-d'));
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(fn (Bill $b) => $b);

		$bill = $this->service->create('user1', 'Netflix', 15.99, 'monthly', 1);

		$this->assertSame('Netflix', $bill->getName());
		$this->assertEqualsWithDelta(15.99, $bill->getAmount(), 0.001);
		$this->assertSame('monthly', $bill->getFrequency());
		$this->assertSame($expected, $bill->getNextDueDate());
		$this->assertTrue($bill->getIsActive());
	}

	public function testCreatePersistsStartDateAndFloorsNextDue(): void {
		// First call = next due from today (before start); second = from start date.
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnOnConsecutiveCalls('2099-07-01', '2099-09-01');
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(fn (Bill $b) => $b);

		$bill = $this->service->create(
			'user1', 'Rent', 1000.0, 'monthly', 1,
			null, null, null, null, null,
			null, null, null, false, null,
			false, false, null, null, [],
			null, null, null, '2099-09-01' // startDate (last arg)
		);

		$this->assertSame('2099-09-01', $bill->getStartDate());
		// next due is floored to the start date rather than the earlier 2099-07-01
		$this->assertSame('2099-09-01', $bill->getNextDueDate());
	}

	public function testMonthlyOccurrencesRespectStartDate(): void {
		$bill = new Bill();
		$bill->setFrequency('monthly');
		$bill->setDueDay(1);
		$bill->setStartDate('2026-06-01');

		$method = new \ReflectionMethod($this->service, 'calculateMonthlyOccurrences');
		$method->setAccessible(true);
		$occ = $method->invoke($this->service, $bill, 2026);

		// Months before June are excluded; June onward occur.
		for ($m = 1; $m <= 5; $m++) {
			$this->assertFalse($occ[$m], "month $m should be excluded (before start date)");
		}
		for ($m = 6; $m <= 12; $m++) {
			$this->assertTrue($occ[$m], "month $m should occur (on/after start date)");
		}
	}

	// ── one-time bills dated explicitly (#375) ──────────────────────

	/**
	 * The date is the whole schedule for a one-time bill: day and month follow
	 * it so the Bills Calendar lands it in the right month, and the stored due
	 * date is the date itself - here one that has already passed.
	 */
	public function testCreateOneTimeBillTakesItsDayAndMonthFromTheDate(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')
			->with('one-time', 26, 8, null, null, false, '2020-08-26')
			->willReturn('2020-08-26');
		$this->mapper->method('insert')->willReturnCallback(fn (Bill $b) => $b);

		// dueDay/dueMonth deliberately wrong in the request (1 January): the date wins
		$bill = $this->service->create('user1', 'Garage', 321.60, 'one-time', 1, 1, startDate: '2020-08-26');

		$this->assertSame(26, $bill->getDueDay());
		$this->assertSame(8, $bill->getDueMonth());
		$this->assertSame('2020-08-26', $bill->getNextDueDate());
	}

	/**
	 * Found live: a one-time bill dated in the past got a CLEARED row on
	 * creation - money booked as spent for an invoice nobody had paid -
	 * because createFromBill() reads an explicit past date as a payment.
	 * Creating a bill never records a payment, so the row is a placeholder.
	 */
	public function testCreatePreCreatesAScheduledPlaceholderEvenForAPastDate(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2020-08-26');
		$this->mapper->method('insert')->willReturnCallback(fn (Bill $b) => $b);

		$this->transactionService->expects($this->once())
			->method('createFromBill')
			->with('user1', $this->isInstanceOf(Bill::class), '2020-08-26', 'scheduled')
			->willReturn(new Transaction());

		$this->service->create('user1', 'Garage', 321.60, 'one-time', 26, 8, null, 1, startDate: '2020-08-26', createTransaction: true, transactionDate: '2020-08-26');
	}

	// ── the Bills Calendar is drawn from payments (#375) ─────────────

	private function attribute(array $occurrences, array $payments, array $billOverrides = []): array {
		$bill = $this->makeBill($billOverrides); // due day 15 unless overridden
		$method = new \ReflectionMethod($this->service, 'attributePayments');
		$method->setAccessible(true);
		return $method->invoke($this->service, $occurrences, $bill, $payments, 2026);
	}

	private function monthly(?array $months = null): array {
		$occ = array_fill(1, 12, false);
		foreach ($months ?? range(1, 12) as $m) {
			$occ[$m] = true;
		}
		return $occ;
	}

	/** Months the schedule puts a bill in, as the calendar projects them. */
	private function occurringMonths(array $billOverrides, int $year = 2026): array {
		$method = new \ReflectionMethod($this->service, 'calculateMonthlyOccurrences');
		$method->setAccessible(true);
		return array_keys(array_filter($method->invoke($this->service, $this->makeBill($billOverrides), $year)));
	}

	/** Every date the calendar puts a bill on in a year, by month. */
	private function occurrenceDates(array $billOverrides, int $year = 2026): array {
		$method = new \ReflectionMethod($this->service, 'occurrencesInYear');
		$method->setAccessible(true);
		return $method->invoke($this->service, $this->makeBill($billOverrides), $year);
	}

	public function testTheCalendarShowsSemiMonthlyBills(): void {
		// The calendar had no case for them: they never appeared
		$this->assertSame(range(1, 12), $this->occurringMonths(['frequency' => 'semi-monthly', 'dueDay' => 1]));
		$this->assertSame(['2026-02-01', '2026-02-16'], $this->occurrenceDates(['frequency' => 'semi-monthly', 'dueDay' => 1])[2]);
	}

	public function testTheCalendarWrapsAQuarterlyBillRoundTheYear(): void {
		// A November quarter showed only in November
		$this->assertSame([2, 5, 8, 11], $this->occurringMonths(['frequency' => 'quarterly', 'dueDay' => 1, 'dueMonth' => 11]));
	}

	public function testTheCalendarTakesAQuarterlyBillsMonthFromItsDueDate(): void {
		// With no month stored it fell back to January, whatever the bill said
		$this->assertSame([2, 5, 8, 11], $this->occurringMonths([
			'frequency' => 'quarterly', 'dueDay' => 10, 'dueMonth' => null, 'nextDueDate' => '2026-02-10',
		]));
	}

	public function testTheCalendarShowsAOneTimeBillOnlyInItsYear(): void {
		// It came back in the same month of every later year
		$bill = ['frequency' => 'one-time', 'dueDay' => 20, 'dueMonth' => 8, 'startDate' => '2025-08-20', 'isActive' => false, 'nextDueDate' => null];
		$this->assertSame([], $this->occurringMonths($bill, 2026));
		$this->assertSame([8], $this->occurringMonths($bill, 2025));
	}

	public function testTheCalendarShowsACustomDatesPattern(): void {
		$this->assertSame([1, 7], $this->occurringMonths([
			'frequency' => 'custom', 'customRecurrencePattern' => '{"dates":[{"month":1,"day":15},{"month":7,"day":31}]}',
		]));
	}

	public function testTheCalendarCountsEveryWeeklyPayment(): void {
		// A weekly bill counted once a month in the totals and projection
		$dates = $this->occurrenceDates(['frequency' => 'weekly', 'dueDay' => 5, 'startDate' => '2026-01-02']);
		$this->assertCount(5, $dates[1]);
		$this->assertCount(4, $dates[2]);
	}

	/** The old heuristic marked every month up to last_paid_date paid. Only months with a payment are. */
	public function testOnlyMonthsWithAPaymentArePaid(): void {
		[, $paid, $amounts] = $this->attribute($this->monthly(), [
			['date' => '2026-09-14', 'amount' => 15.99],
		]);

		$this->assertSame([9], $paid);
		$this->assertSame([9 => 15.99], $amounts);
	}

	/** Due on the 28th, paid on the 2nd: five days from October's due date, twenty-six from November's. */
	public function testALatePaymentBelongsToTheOccurrenceItIsNearest(): void {
		[, $paid] = $this->attribute($this->monthly(), [
			['date' => '2026-08-28', 'amount' => 50.0],
			['date' => '2026-09-28', 'amount' => 50.0],
			['date' => '2026-11-02', 'amount' => 50.0], // October's, paid late
		], ['dueDay' => 28]);

		$this->assertSame([8, 9, 10], $paid);
	}

	/** Paid the day before it is due is that month's payment, not last month's. */
	public function testAnEarlyPaymentBelongsToTheMonthItIsDue(): void {
		[, $paid] = $this->attribute($this->monthly(), [
			['date' => '2026-11-14', 'amount' => 50.0],
		], ['dueDay' => 15]);

		$this->assertSame([11], $paid);
	}

	/** Two payments in one month: the second fills the nearest unpaid neighbour, earlier on a tie. */
	public function testASecondPaymentInAMonthFillsTheNearestUnpaidSlot(): void {
		[, $paid] = $this->attribute($this->monthly(), [
			['date' => '2026-03-15', 'amount' => 10.0],
			['date' => '2026-03-15', 'amount' => 10.0],
		], ['dueDay' => 15]);

		// February's due date is 28 days away, April's is 31: February
		$this->assertSame([2, 3], $paid);
	}

	/** A one-time bill has one occurrence; whenever it was paid, that is the one. */
	public function testASingleOccurrenceTakesAnyPayment(): void {
		[$occ, $paid, $amounts] = $this->attribute($this->monthly([8]), [
			['date' => '2026-11-20', 'amount' => 321.60],
		], ['frequency' => 'one-time', 'dueMonth' => 8]);

		$this->assertSame([8], $paid);
		$this->assertSame([8 => 321.60], $amounts);
		$this->assertFalse($occ[11], 'no extra occurrence is invented for a single-slot bill');
	}

	/** A payment with no occurrence within reach is real money: it becomes an extra paid month. */
	public function testAPaymentFarFromAnyOccurrenceBecomesAnExtraPaidMonth(): void {
		[$occ, $paid] = $this->attribute($this->monthly([1, 7]), [
			['date' => '2026-04-10', 'amount' => 99.0],
		], ['dueDay' => 1]);

		$this->assertTrue($occ[4]);
		$this->assertSame([4], $paid);
		$this->assertFalse($occ[2]);
	}

	/**
	 * Straight from the reporter's ledger: a bill due on the 31st, paid a day
	 * or two early each month, and paid twice at the end of August. The old
	 * nearest-date rule pushed the second August payment onto September,
	 * while the Bills page - reading next_due_date - showed September as
	 * upcoming. Nothing on or after next_due_date may ever be marked paid.
	 */
	public function testPaymentsNeverMarkAnOccurrenceTheBillStillCountsAsOwed(): void {
		[$occ, $paid, $amounts] = $this->attribute($this->monthly(), [
			['date' => '2026-02-28', 'amount' => 168.55],
			['date' => '2026-03-29', 'amount' => 165.45],
			['date' => '2026-04-29', 'amount' => 166.25],
			['date' => '2026-05-31', 'amount' => 172.65],
			['date' => '2026-06-28', 'amount' => 172.65],
			['date' => '2026-07-25', 'amount' => 165.25],
			['date' => '2026-08-29', 'amount' => 161.25],
			['date' => '2026-08-30', 'amount' => 155.25],
		], ['dueDay' => 31, 'nextDueDate' => '2026-09-30']);

		$this->assertSame([2, 3, 4, 5, 6, 7, 8], $paid);
		$this->assertFalse(in_array(9, $paid, true), 'September is still owed');
		// The double payment shows as August's larger amount, not as a paid September
		$this->assertEqualsWithDelta(161.25 + 155.25, $amounts[8], 0.001);
		$this->assertTrue($occ[9], 'September still occurs, unpaid');
	}

	/** A bill paid ahead has advanced next_due_date, so those occurrences are closed and may be paid. */
	public function testAPrepaidOccurrenceIsPaidBecauseTheBillHasMovedPastIt(): void {
		[, $paid] = $this->attribute($this->monthly(), [
			['date' => '2026-08-30', 'amount' => 50.0],
			['date' => '2026-09-28', 'amount' => 50.0],
			['date' => '2026-10-30', 'amount' => 50.0],
		], ['dueDay' => 30, 'nextDueDate' => '2026-11-30']);

		$this->assertSame([8, 9, 10], $paid);
	}

	/**
	 * A payment for an occurrence the bill still counts as owed cannot mark
	 * that occurrence: the bill's own state wins. It lands on the nearest
	 * closed occurrence instead, so the money is still visible somewhere.
	 */
	public function testAPaymentBeforeAnOwedOccurrenceCannotMarkIt(): void {
		[$occ, $paid, $amounts] = $this->attribute($this->monthly(), [
			['date' => '2026-03-25', 'amount' => 40.0],
			['date' => '2026-04-25', 'amount' => 40.0],
			['date' => '2026-05-25', 'amount' => 40.0], // May is still owed on 28 May
		], ['dueDay' => 28, 'nextDueDate' => '2026-05-28']);

		$this->assertSame([3, 4], $paid);
		$this->assertEqualsWithDelta(80.0, $amounts[4], 0.001, 'the payment for the owed month folds into the nearest closed one');
		$this->assertTrue($occ[5], 'May still occurs, unpaid');
	}

	/** One-time bills sharing a name become one row, each month keeping its own invoice's amount. */
	public function testOneTimeBillsWithTheSameNameShareOneCalendarRow(): void {
		$method = new \ReflectionMethod($this->service, 'groupOneTimeBillsByName');
		$method->setAccessible(true);
		$row = fn (int $id, string $name, string $freq, int $month, float $amount, bool $paid, bool $active) => [
			'id' => $id, 'name' => $name, 'frequency' => $freq, 'currency' => 'CHF', 'amount' => $amount, 'isActive' => $active,
			'occurrences' => array_replace(array_fill(1, 12, false), [$month => true]),
			'paidMonths' => $paid ? [$month] : [], 'paidAmounts' => $paid ? [$month => $amount] : [],
			'expectedAmounts' => [$month => $amount],
		];

		$grouped = $method->invoke($this->service, [
			$row(50, 'Garage Brönnimann-Zemp GmbH', 'one-time', 4, 600.60, true, false),
			$row(79, 'Garage Brönnimann-Zemp GmbH', 'one-time', 7, 33.50, true, false),
			$row(90, 'Garage Brönnimann-Zemp GmbH', 'one-time', 9, 1894.90, false, true),
			$row(4, 'KPT', 'monthly', 8, 484.75, true, true),
			$row(5, 'KPT', 'monthly', 9, 484.75, false, true),
		]);

		$this->assertCount(3, $grouped, 'three garage invoices fold into one row; recurring rows are untouched');
		$garage = $grouped[0];
		$this->assertSame([50, 79, 90], $garage['billIds']);
		$this->assertSame([4, 7, 9], array_keys(array_filter($garage['occurrences'])));
		$this->assertSame([4, 7], $garage['paidMonths']);
		$this->assertSame([4 => 600.60, 7 => 33.50, 9 => 1894.90], $garage['expectedAmounts']);
		$this->assertTrue($garage['isActive'], 'still active while one invoice is unpaid');
	}

	public function testNoPaymentsLeavesTheScheduleUntouched(): void {
		$occ = $this->monthly([2, 5]);

		[$after, $paid, $amounts] = $this->attribute($occ, []);

		$this->assertSame($occ, $after);
		$this->assertSame([], $paid);
		$this->assertSame([], $amounts);
	}

	// ── occurrences the bill has moved past without a recorded payment (#333) ──

	/**
	 * A yearly bill marked paid with "Don't create any transaction" has no
	 * payment for the calendar to find, but its next due date is a year on,
	 * so the bill itself no longer owes that month. It must not read as due.
	 */
	public function testAClosedOccurrenceWithoutAPaymentIsReportedAsUnrecorded(): void {
		[, $paid, , $unrecorded] = $this->attribute($this->monthly([2]), [], [
			'frequency' => 'yearly', 'dueDay' => 24, 'dueMonth' => 2,
			'lastPaidDate' => '2026-02-25', 'nextDueDate' => '2027-02-24',
		]);

		$this->assertSame([], $paid);
		$this->assertSame([2], $unrecorded);
	}

	/** Only occurrences before the next due date are closed; the owed ones stay due. */
	public function testOwedOccurrencesAreNeverUnrecorded(): void {
		[, $paid, , $unrecorded] = $this->attribute($this->monthly([7, 8, 9, 10, 11, 12]), [
			['date' => '2026-09-08', 'amount' => 355.0],
		], ['dueDay' => 8, 'nextDueDate' => '2026-10-08']);

		$this->assertSame([9], $paid);
		$this->assertSame([7, 8], $unrecorded, 'closed and unpaid');
	}

	/** A one-time bill that is still active owes its only occurrence: never closed. */
	public function testAnActiveOneTimeBillStillOwesItsOccurrence(): void {
		[, $paid, , $unrecorded] = $this->attribute($this->monthly([9]), [], [
			'frequency' => 'one-time', 'dueDay' => 30, 'dueMonth' => 9, 'nextDueDate' => '2026-09-30',
		]);

		$this->assertSame([], $paid);
		$this->assertSame([], $unrecorded);
	}

	/** An inactive bill owes nothing, so an unpaid occurrence of it is closed. */
	public function testAnInactiveBillsUnpaidOccurrenceIsUnrecorded(): void {
		[, , , $unrecorded] = $this->attribute($this->monthly([8]), [], [
			'frequency' => 'one-time', 'dueDay' => 31, 'dueMonth' => 8, 'isActive' => false, 'nextDueDate' => null,
		]);

		$this->assertSame([8], $unrecorded);
	}

	/** A paid occurrence is paid, not unrecorded, even when it is closed. */
	public function testAPaidOccurrenceIsNotAlsoUnrecorded(): void {
		[, $paid, , $unrecorded] = $this->attribute($this->monthly([2]), [
			['date' => '2026-02-27', 'amount' => 672.30],
		], ['frequency' => 'custom', 'dueDay' => 27, 'customRecurrencePattern' => '{"months":[2]}', 'nextDueDate' => '2027-02-27']);

		$this->assertSame([2], $paid);
		$this->assertSame([], $unrecorded);
	}

	// ── months before the bill existed are not drawn (#333) ──────────

	/**
	 * A monthly bill created on 8 September was projected back to January,
	 * and once the cells came from payments those eight months read as owed.
	 * The old heuristic had struck them through as paid, which was no truer.
	 * Nothing was due before the bill existed, so nothing is drawn.
	 */
	public function testOccurrencesBeforeTheBillExistedAreNotDrawn(): void {
		$this->assertSame([9, 10, 11, 12], $this->occurringMonths(['dueDay' => 8, 'createdAt' => '2026-09-08 19:57:17']));
	}

	/** The comparison is by date, not month: created on the 8th, a bill due on the 5th first falls due in October. */
	public function testAnOccurrenceEarlierInTheCreationMonthIsNotDrawn(): void {
		$this->assertSame([10, 11, 12], $this->occurringMonths(['dueDay' => 5, 'createdAt' => '2026-09-08 19:57:17']));
	}

	public function testAYearlyBillCreatedAfterItsDateFirstOccursNextYear(): void {
		$overrides = ['frequency' => 'yearly', 'dueDay' => 27, 'dueMonth' => 1, 'createdAt' => '2026-02-01 20:01:06'];

		$this->assertSame([], $this->occurringMonths($overrides, 2026));
		$this->assertSame([1], $this->occurringMonths($overrides, 2027));
	}

	/** Weekly bills are pinned mid-month in the calendar, so the creation month is kept whole. */
	public function testAWeeklyBillKeepsItsWholeCreationMonth(): void {
		$this->assertSame([2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12], $this->occurringMonths(['frequency' => 'weekly', 'dueDay' => 3, 'createdAt' => '2026-02-20 10:00:00']));
	}

	/** A one-time bill dated in the past on purpose (#375) keeps its month: the date is the schedule. */
	public function testAOneTimeBillDatedBeforeItsCreationKeepsItsMonth(): void {
		$this->assertSame([8], $this->occurringMonths([
			'frequency' => 'one-time', 'dueDay' => 31, 'dueMonth' => 8, 'startDate' => '2026-08-31', 'createdAt' => '2026-09-05 21:48:43',
		]));
	}

	/** A start date is the user's word on when the schedule began, and it beats the creation date. */
	public function testAStartDateBeforeCreationGoverns(): void {
		$this->assertSame([3, 4, 5, 6, 7, 8, 9, 10, 11, 12], $this->occurringMonths([
			'dueDay' => 8, 'startDate' => '2026-03-01', 'createdAt' => '2026-09-08 19:57:17',
		]));
	}

	/** A yearly bill created after its date came round has nothing this year: no row, rather than a row of blanks. */
	public function testABillWithNothingInTheYearHasNoRow(): void {
		$water = $this->makeBill(['id' => 1, 'name' => 'Water', 'amount' => 1281.10, 'frequency' => 'yearly', 'dueDay' => 27, 'dueMonth' => 1, 'createdAt' => '2026-02-01 20:01:06', 'nextDueDate' => '2027-01-27']);
		$gas = $this->makeBill(['id' => 2, 'name' => 'Gas', 'amount' => 80.0, 'createdAt' => '2026-02-01 20:01:06', 'nextDueDate' => '2026-10-15']);
		$this->mapper->method('findByType')->willReturn([$water, $gas]);
		$this->transactionService->method('findBillPaymentsInYear')->willReturn([]);

		$names = fn (array $result) => array_column($result['bills'], 'name');

		$this->assertSame(['Gas'], $names($this->service->getAnnualOverview('user1', 2026)));
		$this->assertSame(['Water', 'Gas'], $names($this->service->getAnnualOverview('user1', 2027)));
	}

	public function testABillWithoutACreationDateIsProjectedAcrossTheYear(): void {
		$this->assertSame(range(1, 12), $this->occurringMonths(['dueDay' => 8, 'createdAt' => null]));
	}

	/** A transaction linked from before the bill existed is still real money: it shows as an extra paid month. */
	public function testAPaymentBeforeTheBillExistedStillShowsAsPaid(): void {
		[$occ, $paid, $amounts] = $this->attribute($this->monthly([9, 10, 11, 12]), [
			['date' => '2026-03-08', 'amount' => 355.0],
			['date' => '2026-09-08', 'amount' => 355.0],
		], ['dueDay' => 8, 'nextDueDate' => '2026-10-08', 'createdAt' => '2026-09-08 19:57:17']);

		$this->assertTrue($occ[3]);
		$this->assertSame([3, 9], $paid);
		$this->assertEqualsWithDelta(355.0, $amounts[3], 0.001);
	}

	public function testGroupingOneTimeBillsMergesUnrecordedMonths(): void {
		$method = new \ReflectionMethod($this->service, 'groupOneTimeBillsByName');
		$method->setAccessible(true);
		$row = fn (int $id, int $month, bool $paid, bool $unrecorded) => [
			'id' => $id, 'name' => 'Garage', 'frequency' => 'one-time', 'currency' => 'CHF', 'amount' => 100.0, 'isActive' => false,
			'occurrences' => array_replace(array_fill(1, 12, false), [$month => true]),
			'paidMonths' => $paid ? [$month] : [], 'paidAmounts' => $paid ? [$month => 100.0] : [],
			'unrecordedMonths' => $unrecorded ? [$month] : [],
			'expectedAmounts' => [$month => 100.0],
		];

		$grouped = $method->invoke($this->service, [
			$row(1, 7, false, true),
			$row(2, 4, true, false),
			$row(3, 9, false, false),
		]);

		$this->assertCount(1, $grouped);
		$this->assertSame([4], $grouped[0]['paidMonths']);
		$this->assertSame([7], $grouped[0]['unrecordedMonths']);
	}

	/**
	 * End to end: a paid one-time bill has deactivated itself, and under the
	 * default "active" filter it used to vanish from the year it was paid in.
	 * Cells and totals come from the recorded debit legs; a transfer's credit
	 * leg is the same money arriving, not a second payment.
	 */
	public function testAnnualOverviewDrawsCellsFromPaymentsAndKeepsPaidInactiveBills(): void {
		$monthly = $this->makeBill(['id' => 1, 'name' => 'Netflix', 'amount' => 15.99]);
		// Paid, so it has no next due date; from before one-time bills kept
		// their date, so only its month says when it was due
		$garage = $this->makeBill(['id' => 2, 'name' => 'Garage', 'amount' => 321.60, 'frequency' => 'one-time', 'dueMonth' => 8,
			'isActive' => false, 'nextDueDate' => null, 'lastPaidDate' => '2026-09-03']);
		$never = $this->makeBill(['id' => 3, 'name' => 'Old gym', 'amount' => 30.0, 'isActive' => false]);

		// "active" fetches everything and narrows afterwards
		$this->mapper->expects($this->once())->method('findByType')->with('user1', false, null)->willReturn([$monthly, $garage, $never]);

		$payment = function (int $billId, string $date, float $amount, string $type = 'debit'): Transaction {
			$tx = new Transaction();
			$tx->setBillId($billId);
			$tx->setDate($date);
			$tx->setAmount($amount);
			$tx->setType($type);
			$tx->setStatus('cleared');
			return $tx;
		};
		$this->transactionService->method('findBillPaymentsInYear')
			->with([1, 2, 3], 2026)
			->willReturn([
				$payment(1, '2026-01-14', 15.99),
				$payment(1, '2026-02-14', 17.99),      // price rise: the cell shows what was paid
				$payment(1, '2026-02-14', 17.99, 'credit'), // a credit leg must not count
				$payment(2, '2026-09-03', 321.60),     // August's invoice, paid in September
			]);

		$result = $this->service->getAnnualOverview('user1', 2026, false, 'active');

		$byName = [];
		foreach ($result['bills'] as $row) {
			$byName[$row['name']] = $row;
		}
		$this->assertArrayHasKey('Netflix', $byName, 'active bill is listed');
		$this->assertArrayHasKey('Garage', $byName, 'inactive bill paid this year stays in the year');
		$this->assertArrayNotHasKey('Old gym', $byName, 'inactive bill with no payment this year is filtered out');

		$this->assertSame([1, 2], $byName['Netflix']['paidMonths']);
		$this->assertSame([1 => 15.99, 2 => 17.99], $byName['Netflix']['paidAmounts']);
		$this->assertTrue($byName['Netflix']['occurrences'][3], 'unpaid months still occur');

		// The single August slot takes September's payment; no extra September cell
		$this->assertSame([8], $byName['Garage']['paidMonths']);
		$this->assertFalse($byName['Garage']['occurrences'][9]);

		// Totals: paid amount where paid, expected amount otherwise
		$this->assertEqualsWithDelta(15.99, $result['monthlyTotals'][1], 0.001);
		$this->assertEqualsWithDelta(17.99, $result['monthlyTotals'][2], 0.001);
		$this->assertEqualsWithDelta(15.99, $result['monthlyTotals'][3], 0.001);
		$this->assertEqualsWithDelta(15.99 + 321.60, $result['monthlyTotals'][8], 0.001);
	}

	// ── annual overview: projected balance (#393) ───────────────────
	// The running balance itself is worked out and tested in BalanceProjector;
	// these check what the overview hands it and when.

	private function makeAccount(int $id, string $name, string $currency = 'CHF'): Account {
		$account = new Account();
		$account->setId($id);
		$account->setUserId('user1');
		$account->setName($name);
		$account->setCurrency($currency);
		return $account;
	}

	/**
	 * With an account picked for this year, its balance today is carried
	 * from the current month to December; the months already gone are blank.
	 */
	public function testAnnualOverviewProjectsTheAccountThroughThisYear(): void {
		$this->accountMapper->method('findAll')->with('user1')->willReturn([$this->makeAccount(5, 'Current')]);
		$this->transactionService->method('getBalanceAsOf')->with(5, date('Y-m-d'))->willReturn(1000.0);
		$this->mapper->method('findByType')->willReturn([]);
		$this->transactionService->method('findBillPaymentsInYear')->willReturn([]);
		$this->incomeMapper->expects($this->once())->method('findActive')->with('user1')->willReturn([]);

		$result = $this->service->getAnnualOverview('user1', (int)date('Y'), false, 'active', 5);

		$this->assertSame(['id' => 5, 'name' => 'Current', 'currency' => 'CHF', 'balance' => 1000.0], $result['account']);
		$current = (int)date('n');
		for ($month = 1; $month <= 12; $month++) {
			if ($month < $current) {
				$this->assertNull($result['projectedBalance'][$month], "month $month has gone");
				$this->assertNull($result['projectedFlows'][$month]);
			} else {
				$this->assertEqualsWithDelta(1000.0, $result['projectedBalance'][$month], 0.001, "month $month");
			}
		}
		$this->assertSame(['bills' => 0.0, 'transfersIn' => 0.0, 'income' => 0.0], $result['projectedFlows'][$current]);
	}

	/**
	 * The money moves whatever the table shows: with transfers hidden, a
	 * transfer into the account is still projected. Nothing has been paid,
	 * so every month up to this one lands in this one.
	 */
	public function testAnnualOverviewProjectsTransfersTheTableHides(): void {
		$year = (int)date('Y');
		$this->accountMapper->method('findAll')->willReturn([$this->makeAccount(5, 'Current'), $this->makeAccount(7, 'Savings')]);
		$this->transactionService->method('getBalanceAsOf')->willReturn(1000.0);
		$topUp = $this->makeBill(['id' => 3, 'name' => 'Top-up', 'amount' => 100.0, 'accountId' => 7, 'isTransfer' => true, 'destinationAccountId' => 5, 'nextDueDate' => "$year-01-15"]);
		$this->mapper->method('findByType')->willReturnCallback(
			fn (string $userId, ?bool $isTransfer) => $isTransfer === false ? [] : [$topUp]
		);
		$this->transactionService->method('findBillPaymentsInYear')->willReturn([]);
		$this->incomeMapper->method('findActive')->willReturn([]);

		$result = $this->service->getAnnualOverview('user1', $year, false, 'active', 5);

		$this->assertSame([], $result['bills'], 'the table still hides it');
		$current = (int)date('n');
		$this->assertEqualsWithDelta(100.0 * $current, $result['projectedFlows'][$current]['transfersIn'], 0.001);
		$this->assertEqualsWithDelta(2200.0, $result['projectedBalance'][12], 0.001);
	}

	/**
	 * A scheduled pension contribution paid from the account comes out of
	 * its projected balance like a bill. It was left out, so the projection
	 * overstated the account by every contribution still to come.
	 */
	public function testAnnualOverviewTakesScheduledPensionContributionsOffTheBalance(): void {
		$current = (int)date('n');
		$byMonth = [$current => 200.0];
		if ($current < 12) {
			$byMonth[12] = 200.0;
		}
		$pensions = $this->createMock(\OCA\Budget\Service\PensionRecurringService::class);
		$pensions->expects($this->once())->method('upcomingDebitsByMonth')->with(5, date('Y-m-d'))->willReturn($byMonth);
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([$this->makeAccount(5, 'Current')]);
		$this->transactionService->method('getBalanceAsOf')->willReturn(1000.0);
		$this->mapper->method('findByType')->willReturn([]);
		$this->transactionService->method('findBillPaymentsInYear')->willReturn([]);
		$this->incomeMapper->method('findActive')->willReturn([]);
		$currency = $this->createMock(\OCA\Budget\Service\CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturn('CHF');
		$service = new BillService(
			$this->mapper,
			$this->frequencyCalculator,
			$this->recurringDetector,
			$this->transactionService,
			$this->createMock(IL10N::class),
			$accounts,
			$currency,
			$this->createMock(TransactionSplitService::class),
			$this->createMock(LoggerInterface::class),
			$this->dismissedMapper,
			null,
			$this->incomeMapper,
			pensionRecurringService: $pensions,
		);

		$result = $service->getAnnualOverview('user1', (int)date('Y'), false, 'active', 5);

		$this->assertEqualsWithDelta(200.0, $result['projectedFlows'][$current]['bills'], 0.001);
		$this->assertEqualsWithDelta(800.0, $result['projectedBalance'][$current], 0.001);
		$this->assertEqualsWithDelta(1000.0 - array_sum($byMonth), $result['projectedBalance'][12], 0.001);
	}

	/** Another year has no today to start from, so the account is named but nothing is projected. */
	public function testAnnualOverviewProjectsNothingForAnotherYear(): void {
		$this->accountMapper->method('findAll')->willReturn([$this->makeAccount(5, 'Current')]);
		$this->transactionService->method('getBalanceAsOf')->willReturn(1000.0);
		$this->mapper->method('findByType')->willReturn([]);
		$this->transactionService->method('findBillPaymentsInYear')->willReturn([]);
		$this->incomeMapper->expects($this->never())->method('findActive');

		$result = $this->service->getAnnualOverview('user1', (int)date('Y') + 1, false, 'active', 5);

		$this->assertSame('Current', $result['account']['name']);
		$this->assertNull($result['projectedBalance']);
		$this->assertNull($result['projectedFlows']);
	}

	public function testAnnualOverviewWithoutAnAccountHasNoProjection(): void {
		$this->mapper->method('findByType')->willReturn([$this->makeBill(['accountId' => 5])]);
		$this->transactionService->method('findBillPaymentsInYear')->willReturn([]);
		$this->transactionService->expects($this->never())->method('getBalanceAsOf');

		$result = $this->service->getAnnualOverview('user1', (int)date('Y'));

		$this->assertNull($result['account']);
		$this->assertNull($result['projectedBalance']);
	}

	/** An account id the user cannot see gives no balance, rather than someone else's. */
	public function testAnnualOverviewIgnoresAnAccountTheUserCannotSee(): void {
		$this->accountMapper->method('findAll')->willReturn([$this->makeAccount(5, 'Current')]);
		$this->transactionService->expects($this->never())->method('getBalanceAsOf');
		$this->mapper->method('findByType')->willReturn([]);
		$this->transactionService->method('findBillPaymentsInYear')->willReturn([]);

		$result = $this->service->getAnnualOverview('user1', (int)date('Y'), false, 'active', 99);

		$this->assertNull($result['account']);
		$this->assertNull($result['projectedBalance']);
	}

	public function testCreateAutoPayRequiresAccount(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Auto-pay requires an account');

		$this->service->create('user1', 'Test', 10.0, 'monthly', null, null, null, null, null, null, null, null, null, false, null, true);
	}

	public function testCreateTransferRequiresDestination(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Transfer requires a destination');

		$this->service->create(
			'user1', 'Transfer', 100.0, 'monthly', null, null, null, 1,
			null, null, null, null, null, false, null, false,
			true, null // isTransfer=true, destinationAccountId=null
		);
	}

	public function testCreateTransferRejectsSameAccount(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Cannot transfer to the same account');

		$this->service->create(
			'user1', 'Transfer', 100.0, 'monthly', null, null, null, 5,
			null, null, null, null, null, false, null, false,
			true, 5 // isTransfer=true, destinationAccountId=same as accountId
		);
	}

	public function testCreateWithTransaction(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-01');
		$this->mapper->method('insert')->willReturnCallback(function (Bill $b) {
			$b->setId(42);
			return $b;
		});
		$this->transactionService->expects($this->once())
			->method('createFromBill')
			->with('user1', $this->isInstanceOf(Bill::class), '2024-06-15');

		$this->service->create(
			'user1', 'Test', 50.0, 'monthly', null, null, null, 1,
			null, null, null, null, null,
			true, '2024-06-15' // createTransaction=true, transactionDate
		);
	}

	// ── createFromDetected (#278) ───────────────────────────────────

	public function testCreateFromDetectedAcrossFrequencies(): void {
		// Regression for #278: createFromDetected drifted its positional args
		// into create(), pushing `false` into ?string customRecurrencePattern
		// and 500-ing every detect-and-add. Exercise detector-shaped items.
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-01');
		$this->mapper->method('insert')->willReturnCallback(function (Bill $b) {
			$b->setId(7);
			return $b;
		});

		$mk = fn (array $o = []) => array_merge([
			'patternKey' => 'netflix|16', 'description' => 'NETFLIX 12345',
			'suggestedName' => 'Netflix', 'amount' => 15.99, 'frequency' => 'monthly',
			'dueDay' => 16, 'categoryId' => null, 'accountId' => null,
			'occurrences' => 4, 'confidence' => 0.83, 'autoDetectPattern' => 'NETFLIX',
			'lastSeen' => '2026-06-01',
		], $o);

		$detected = [
			$mk(),
			$mk(['frequency' => 'weekly', 'dueDay' => 3]),
			$mk(['frequency' => 'yearly']),
			$mk(['amount' => '15.99']),           // numeric string must not TypeError
			$mk(['dueDay' => null]),
			$mk(['categoryId' => 5, 'accountId' => 9]),
		];

		$created = $this->service->createFromDetected('user1', $detected);

		$this->assertCount(6, $created);
		foreach ($created as $bill) {
			$this->assertInstanceOf(Bill::class, $bill);
			$this->assertSame('NETFLIX', $bill->getAutoDetectPattern());
		}
	}

	public function testCreateFromDetectedTransfer(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-01');
		$this->mapper->method('insert')->willReturnCallback(function (Bill $b) {
			$b->setId(8);
			return $b;
		});

		$created = $this->service->createFromDetected('user1', [[
			'suggestedName' => 'Savings transfer', 'amount' => 200.0, 'frequency' => 'monthly',
			'dueDay' => 1, 'isTransfer' => true, 'destinationAccountId' => 3, 'accountId' => 1,
		]]);

		$this->assertCount(1, $created);
		$this->assertTrue($created[0]->getIsTransfer());
		$this->assertSame(3, $created[0]->getDestinationAccountId());
		$this->assertNull($created[0]->getCategoryId());
	}

	public function testCreateFromDetectedKeepsTheDetectedSchedule(): void {
		// A weekly bill counts from the payment the detector last saw, and a
		// quarterly one keeps its months: both were dropped, so the weekly
		// one fell on the wrong weekday and the quarterly one in Jan/Apr/Jul/Oct
		$this->mapper->method('insert')->willReturnArgument(0);

		[$weekly, $quarterly] = $this->service->createFromDetected('user1', [
			['suggestedName' => 'Gym', 'amount' => 9.99, 'frequency' => 'weekly', 'dueDay' => 5, 'dueMonth' => null, 'startDate' => '2026-09-25'],
			['suggestedName' => 'Water', 'amount' => 120.0, 'frequency' => 'quarterly', 'dueDay' => 10, 'dueMonth' => 9, 'startDate' => null],
		]);

		$this->assertSame('2026-09-25', $weekly->getStartDate());
		$this->assertSame(5, $weekly->getDueDay());
		// On the Friday fortnight of the payment it last saw...
		$this->assertSame('Fri', (new \DateTime($weekly->getNextDueDate()))->format('D'));
		$this->assertSame(0, (new \DateTime('2026-09-25'))->diff(new \DateTime($weekly->getNextDueDate()))->days % 7);
		// ...and in the quarter's own months
		$this->assertSame(9, $quarterly->getDueMonth());
		$this->assertNull($quarterly->getStartDate());
		$this->assertContains((int)substr($quarterly->getNextDueDate(), 5, 2), [3, 6, 9, 12]);
	}

	public function testCreateFromDetectedSkipsABlankSuggestedName(): void {
		// `suggestedName ?? description` kept '' and created a nameless bill
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-01');
		$this->mapper->method('insert')->willReturnArgument(0);

		$created = $this->service->createFromDetected('user1', [
			['suggestedName' => '', 'description' => 'DIRECT DEBIT', 'amount' => 20.0, 'frequency' => 'monthly'],
			['name' => 'Phone', 'suggestedName' => 'Ee', 'description' => 'EE LTD', 'amount' => 30.0, 'frequency' => 'monthly'],
		]);

		$this->assertSame('DIRECT DEBIT', $created[0]->getName());
		$this->assertSame('Phone', $created[1]->getName());
	}

	public function testCreateFromDetectedRefusesAnItemWithNoName(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->createFromDetected('user1', [
			['suggestedName' => ' ', 'description' => '', 'amount' => 20.0, 'frequency' => 'monthly'],
		]);
	}

	// ── markPaid ────────────────────────────────────────────────────

	public function testMarkPaidAdvancesNextDueDate(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-06-15']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->frequencyCalculator->expects($this->once())
			->method('calculateNextDueDate')
			->with('monthly', 15, null, '2099-06-15', null)
			->willReturn('2099-07-15');

		$this->transactionService->method('createFromBill'); // allow call

		$result = $this->service->markPaid(1, 'user1');
		$bill = $result['bill'];

		$this->assertSame(date('Y-m-d'), $bill->getLastPaidDate());
		$this->assertSame('2099-07-15', $bill->getNextDueDate());
		$this->assertTrue($bill->getIsActive());
		$this->assertArrayHasKey('previousState', $result);
		$this->assertArrayHasKey('createdTransactionIds', $result);
	}

	// ── pre-created next transaction opt-out (#311) ─────────────────

	public function testMarkPaidCreatesNextPlaceholderByDefault(): void {
		// Legacy rows have no flag (null) — treated as opted in
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		// Once for the payment leg, once for the next occurrence
		$this->transactionService->expects($this->exactly(2))->method('createFromBill');

		$this->service->markPaid(1, 'user1');
	}

	public function testMarkPaidSkipsNextPlaceholderWhenOptedOut(): void {
		$bill = $this->makeBill(['createTransaction' => false]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		// Only the payment leg is recorded — no placeholder for the next occurrence
		$this->transactionService->expects($this->once())
			->method('createFromBill')
			->with('user1', $bill, $this->anything(), 'cleared');

		$result = $this->service->markPaid(1, 'user1');

		// Schedule still advances as normal
		$this->assertSame('2099-07-15', $result['bill']->getNextDueDate());
	}

	public function testSkipPaymentSkipsPlaceholderWhenOptedOut(): void {
		$bill = $this->makeBill(['createTransaction' => false]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$this->transactionService->expects($this->once())->method('deleteScheduledBillTransactions')->with(1);
		$this->transactionService->expects($this->never())->method('createFromBill');

		$result = $this->service->skipPayment(1, 'user1');

		$this->assertSame('2099-07-15', $result['bill']->getNextDueDate());
	}

	// Undoing a skip put a placeholder back for a bill that had opted out of
	// them, so a transfer with pre-created transactions off gained a pair of
	// scheduled legs the moment its skip was undone (#396).
	public function testUndoSkipSkipsPlaceholderWhenOptedOut(): void {
		$bill = $this->makeBill(['createTransaction' => false]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->transactionService->expects($this->once())->method('deleteScheduledBillTransactions')->with(1);
		$this->transactionService->expects($this->never())->method('createFromBill');

		$bill = $this->service->undoSkip(1, 'user1', '2099-06-15');

		$this->assertSame('2099-06-15', $bill->getNextDueDate());
	}

	// ── recording the payment vs. the ledger's placeholders (#376) ──

	// "Don't create any transaction (just mark as paid)" left the placeholder
	// for the occurrence just paid sitting in the ledger, still scheduled, so
	// the bill went on looking upcoming. skipPayment() has always cleared it.
	public function testMarkPaidWithoutRecordingClearsThePaidOccurrencesPlaceholder(): void {
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$this->transactionService->expects($this->once())
			->method('deleteScheduledBillTransactions')
			->with(1);

		$result = $this->service->markPaid(1, 'user1', null, false);

		$this->assertSame('2099-07-15', $result['bill']->getNextDueDate());
		$this->assertFalse($result['paymentTransactionRecorded']);
	}

	// The bill's own "create future transaction" setting decides whether the
	// next occurrence is pre-created - not whether the user happened to record
	// this payment. Both other paths that advance a bill already read it that
	// way (skipPayment, update).
	public function testMarkPaidWithoutRecordingStillCreatesTheNextPlaceholder(): void {
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		// Only the next occurrence - no payment leg, that is what was declined
		$this->transactionService->expects($this->once())
			->method('createFromBill')
			->with('user1', $bill, null);

		$this->service->markPaid(1, 'user1', null, false);
	}

	// Linking an existing transaction also arrives with recordPayment false,
	// and used to leave the bill with no upcoming row at all.
	public function testMarkPaidLinkingAnExistingTransactionCreatesTheNextPlaceholder(): void {
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$this->linkable($this->makeImportedTx(['id' => 42]));
		$this->transactionService->expects($this->once())
			->method('createFromBill')
			->with('user1', $bill, null);

		$result = $this->service->markPaid(1, 'user1', null, false, 42);

		$this->assertTrue($result['linkedExistingTransaction']);
	}

	// The opt-out still wins over all of it.
	public function testMarkPaidWithoutRecordingHonoursThePlaceholderOptOut(): void {
		$bill = $this->makeBill(['createTransaction' => false]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$this->transactionService->expects($this->never())->method('createFromBill');

		$this->service->markPaid(1, 'user1', null, false);
	}

	/** The mapper keeps what updateFields() writes, as the real one does */
	private function persistUpdates(Bill $bill): void {
		$this->mapper->method('updateFields')->willReturnCallback(function (int $id, string $user, array $fields) use ($bill) {
			foreach ($fields as $column => $value) {
				$bill->{'set' . str_replace('_', '', ucwords($column, '_'))}($value);
			}
		});
	}

	public function testUpdateTogglingOffRemovesPlaceholders(): void {
		$bill = $this->makeBill(); // no flag = enabled
		$this->mapper->method('find')->willReturn($bill);
		$this->persistUpdates($bill);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-06-15');

		$this->transactionService->expects($this->once())->method('deleteScheduledBillTransactions')->with(1);
		$this->transactionService->expects($this->never())->method('createFromBill');

		$this->service->update(1, 'user1', ['createTransaction' => false]);
	}

	public function testUpdateTogglingOnCreatesPlaceholder(): void {
		$bill = $this->makeBill(['createTransaction' => false]);
		$this->mapper->method('find')->willReturn($bill);
		$this->persistUpdates($bill);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-06-15');

		$this->transactionService->expects($this->once())->method('createFromBill');

		$this->service->update(1, 'user1', ['createTransaction' => true]);
	}

	public function testUpdateTogglingOnWithAScheduleChangeCreatesPlaceholder(): void {
		// The recalculation copied the updates onto the in-memory bill before
		// the toggle compared old against new, so turning pre-booking on in
		// the same save as a due-day change read "already on" and created
		// nothing (#584).
		$bill = $this->makeBill(['createTransaction' => false]);
		$this->mapper->method('find')->willReturn($bill);
		$this->persistUpdates($bill);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-06-20');

		$this->transactionService->expects($this->once())->method('createFromBill');

		$this->service->update(1, 'user1', ['dueDay' => 20, 'createTransaction' => true]);
	}

	public function testUpdateSwitchingToStatementWithAScheduleChangeRefreshesPlaceholder(): void {
		// Same in-memory mutation: the switch to a dynamic amount compared the
		// bill's amount type after the recalculation had already overwritten
		// it, so the old placeholder kept the old fixed amount (#584).
		$bill = $this->makeBill(['isTransfer' => true, 'destinationAccountId' => 7]);
		$this->mapper->method('find')->willReturn($bill);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-06-20');
		$card = new Account();
		$card->setType('credit_card');
		$this->accountMapper->method('findById')->willReturn($card);
		$this->transactionService->method('getStatementAmountForAccount')->willReturn(120.0);

		$this->transactionService->expects($this->once())->method('deleteScheduledBillTransactions')->with(1);
		$this->transactionService->expects($this->once())->method('createFromBill');

		$this->service->update(1, 'user1', ['dueDay' => 20, 'amountType' => 'statement']);
	}

	/**
	 * @dataProvider paidAheadPeriods
	 */
	public function testUpdateKeepsADueDateAdvancedByAnEarlyPayment(int $periodsAhead): void {
		// A monthly bill paid before its due date advances past the occurrence
		// it paid; an unrelated edit must leave that date alone (#584).
		$real = new FrequencyCalculator();
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn (...$args) => $real->calculateNextDueDate(...$args));

		$due = (new \DateTime('+5 days'))->format('Y-m-d');
		$dueDay = (int)(new \DateTime($due))->format('j');
		$nextDue = $due;
		for ($i = 0; $i < $periodsAhead; $i++) {
			$nextDue = $real->calculateNextDueDate('monthly', $dueDay, null, $nextDue, null, true);
		}
		$bill = $this->makeBill([
			'dueDay' => $dueDay,
			'nextDueDate' => $nextDue,
			'lastPaidDate' => date('Y-m-d'),
		]);
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['name' => 'Renamed', 'amount' => 20.0]);

		$this->assertNotNull($captured);
		$this->assertArrayNotHasKey('next_due_date', $captured, 'an edit after an early payment must not reset next due');
	}

	public static function paidAheadPeriods(): array {
		return ['one period ahead' => [1], 'two periods ahead' => [2]];
	}

	public function testUpdateKeepsAPaidAheadDueDateOnTheFirstOfTheMonth(): void {
		// The paid-ahead check asked the calculator from the day before the
		// stored date, which for a bill due on the 1st is the previous month:
		// monthly snapped to that month's 1st, so the date read as stale and
		// every edit reset it. The data-provider test above only hit this when
		// today + 5 days fell on a 1st.
		$real = new FrequencyCalculator();
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn (...$args) => $real->calculateNextDueDate(...$args));

		$bill = $this->makeBill([
			'dueDay' => 1,
			'nextDueDate' => (new \DateTime('first day of +2 months'))->format('Y-m-d'),
			'lastPaidDate' => date('Y-m-d'),
		]);
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['name' => 'Renamed']);

		$this->assertNotNull($captured);
		$this->assertArrayNotHasKey('next_due_date', $captured);
	}

	// ── biweekly anchoring to startDate (#364) ──────────────────────

	public function testCreateBiweeklyAnchorsToStartDate(): void {
		// Back the mock with the real calculator so the anchor maths runs.
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn (...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));
		$this->mapper->method('insert')->willReturnCallback(fn (Bill $b) => $b);

		// Anchor 21 days ago: occurrences at -21, -7 and +7 days. Without the
		// anchor, creation week would win and next due would land at +14.
		$anchor = (new \DateTime('-21 days'))->format('Y-m-d');
		$dueDay = (int)(new \DateTime())->format('N'); // same weekday as anchor

		$bill = $this->service->create('user1', 'Pay', 100.0, 'biweekly', $dueDay, startDate: $anchor);

		$this->assertSame((new \DateTime('+7 days'))->format('Y-m-d'), $bill->getNextDueDate());
	}

	public function testUpdateUnrelatedFieldKeepsBiweeklyParityWithStartDate(): void {
		// Live bug: the update consistency check recomputed "from today", so
		// editing ANY field of a biweekly bill in the off-parity week silently
		// flipped its parity. With a startDate anchor the stored due date is
		// consistent and must be left alone.
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn (...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));

		$anchor = (new \DateTime('-21 days'))->format('Y-m-d');
		$bill = $this->makeBill([
			'frequency' => 'biweekly',
			'dueDay' => (int)(new \DateTime())->format('N'),
			'nextDueDate' => (new \DateTime('+7 days'))->format('Y-m-d'),
		]);
		$bill->setStartDate($anchor);
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->expects($this->once())->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['name' => 'Renamed']);

		$this->assertNotNull($captured);
		$this->assertArrayNotHasKey('next_due_date', $captured, 'an unrelated edit must not move an anchored biweekly bill');
	}

	public function testUpdateScheduleChangeRecomputesFromStartDateAnchor(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn (...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));

		// Weekly bill due today, anchored 21 days back; switching it to
		// biweekly must land on the anchor's fortnight (+7 days), not on
		// today's week parity (+14 days).
		$anchor = (new \DateTime('-21 days'))->format('Y-m-d');
		$bill = $this->makeBill([
			'frequency' => 'weekly',
			'dueDay' => (int)(new \DateTime())->format('N'),
			'nextDueDate' => (new \DateTime())->format('Y-m-d'),
		]);
		$bill->setStartDate($anchor);
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['frequency' => 'biweekly']);

		$this->assertSame((new \DateTime('+7 days'))->format('Y-m-d'), $captured['next_due_date'] ?? null);
	}

	public function testUpdateSettingStartDateReanchorsNextDue(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn (...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));

		// Un-anchored biweekly bill stuck on creation-week parity (+14 days);
		// giving it a startDate must snap next due onto the anchor's
		// fortnight (+7 days).
		$anchor = (new \DateTime('-21 days'))->format('Y-m-d');
		$bill = $this->makeBill([
			'frequency' => 'biweekly',
			'dueDay' => (int)(new \DateTime())->format('N'),
			'nextDueDate' => (new \DateTime('+14 days'))->format('Y-m-d'),
		]);
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['startDate' => $anchor]);

		$this->assertSame((new \DateTime('+7 days'))->format('Y-m-d'), $captured['next_due_date'] ?? null);
	}

	public function testCreatePersistsPreBookOptOut(): void {
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-01');
		$this->mapper->method('insert')->willReturnCallback(fn (Bill $b) => $b);

		$bill = $this->service->create('user1', 'Netflix', 15.99, 'monthly', 1, createTransaction: false);

		$this->assertFalse($bill->getCreateTransaction());
	}

	public function testMarkPaidUsesProvidedDate(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-06-15']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1', '2099-06-10');

		$this->assertSame('2099-06-10', $result['bill']->getLastPaidDate());
	}

	public function testMarkPaidOneTimeDeactivates(): void {
		$bill = $this->makeBill(['frequency' => 'one-time', 'nextDueDate' => '2099-06-15']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['bill']->getIsActive());
		$this->assertNull($result['bill']->getNextDueDate());
	}

	/**
	 * Paying a one-time bill cleared its next due date, and a bill created
	 * before the date field existed had nothing else to show - so a paid
	 * invoice read "No due date" in the list and opened with an empty Due
	 * Date (#333). The date it was due is kept as its start date.
	 */
	public function testMarkPaidOneTimeKeepsItsDueDateAsTheStartDate(): void {
		$bill = $this->makeBill(['frequency' => 'one-time', 'dueDay' => 31, 'dueMonth' => 8, 'nextDueDate' => '2026-08-31']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->markPaid(1, 'user1', '2026-08-30');

		$this->assertNull($result['bill']->getNextDueDate());
		$this->assertSame('2026-08-31', $result['bill']->getStartDate());
	}

	public function testMarkPaidOneTimeLeavesAnExistingDateAlone(): void {
		$bill = $this->makeBill(['frequency' => 'one-time', 'startDate' => '2026-08-31', 'nextDueDate' => '2026-08-31']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->markPaid(1, 'user1', '2026-09-05');

		$this->assertSame('2026-08-31', $result['bill']->getStartDate());
	}

	public function testMarkPaidDecrementsRemainingPayments(): void {
		$bill = $this->makeBill(['remainingPayments' => 3]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertSame(2, $result['bill']->getRemainingPayments());
		$this->assertTrue($result['bill']->getIsActive());
	}

	public function testMarkPaidLastPaymentDeactivates(): void {
		$bill = $this->makeBill(['remainingPayments' => 1]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertSame(0, $result['bill']->getRemainingPayments());
		$this->assertFalse($result['bill']->getIsActive());
		$this->assertNull($result['bill']->getNextDueDate());
	}

	public function testMarkPaidDeactivatesWhenPastEndDate(): void {
		$bill = $this->makeBill(['endDate' => '2099-06-30']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		// Next due date would be after end date
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['bill']->getIsActive());
		$this->assertNull($result['bill']->getNextDueDate());
	}

	public function testMarkPaidResetsAutoPayFailed(): void {
		$bill = $this->makeBill(['autoPayFailed' => true]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['bill']->getAutoPayFailed());
	}

	public function testMarkPaidCreatesTransactionForOneTimeBill(): void {
		$bill = $this->makeBill(['frequency' => 'one-time']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		// One-time bills create a cleared transaction for the current payment before deactivating
		$this->transactionService->expects($this->once())->method('createFromBill');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['bill']->getIsActive());
	}

	public function testMarkPaidReportsPaymentTransactionRecorded(): void {
		$bill = $this->makeBill(['frequency' => 'one-time']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->markPaid(1, 'user1');

		$this->assertTrue($result['paymentTransactionRecorded']);
	}

	public function testMarkPaidReportsNoTransactionWhenBillHasNoAccount(): void {
		// The #89/#274 silent leak: a bill without an account is marked paid
		// but no money movement is recorded — the result must say so, loudly.
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('user1');
		$bill->setName('Mortgage');
		$bill->setAmount(2912.0);
		$bill->setFrequency('monthly');
		$bill->setIsActive(true);
		$bill->setNextDueDate('2099-06-28');
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-28');

		$this->transactionService->expects($this->never())->method('createFromBill');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['paymentTransactionRecorded']);
		$this->assertNotNull($result['bill']->getLastPaidDate());
	}

	public function testMarkPaidReportsNoTransactionWhenCreationFails(): void {
		$bill = $this->makeBill(['frequency' => 'one-time']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->transactionService->method('createFromBill')
			->willThrowException(new \Exception('account gone'));

		$result = $this->service->markPaid(1, 'user1');

		$this->assertFalse($result['paymentTransactionRecorded']);
	}

	// ── processAutoPay ──────────────────────────────────────────────

	public function testProcessAutoPaySuccess(): void {
		// Due, as the job only asks for bills that are
		$bill = $this->makeBill(['autoPayEnabled' => true, 'accountId' => 1, 'nextDueDate' => '2026-06-15']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertTrue($result['success']);
		$this->assertStringContainsString('successfully', $result['message']);
	}

	public function testProcessAutoPayNotEnabled(): void {
		$bill = $this->makeBill(['autoPayEnabled' => false]);
		$this->mapper->method('find')->willReturn($bill);

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('not enabled', $result['message']);
	}

	public function testProcessAutoPayNoAccount(): void {
		// Build a bill where accountId is truly null (not set at all)
		$bill = new Bill();
		$bill->setId(1);
		$bill->setUserId('user1');
		$bill->setName('Test');
		$bill->setAmount(10.0);
		$bill->setFrequency('monthly');
		$bill->setAutoPayEnabled(true);
		$bill->setAutoPayFailed(false);
		// Do NOT call setAccountId → remains null
		$bill->setIsActive(true);

		$this->mapper->method('find')->willReturn($bill);

		$result = $this->service->processAutoPay(1, 'user1');

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('no account', $result['message']);
	}

	// ── matchTransactionToBill ──────────────────────────────────────

	public function testMatchTransactionToBillExactMatch(): void {
		$bill = $this->makeBill(['autoDetectPattern' => 'NETFLIX', 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		$result = $this->service->matchTransactionToBill('user1', 'NETFLIX.COM Subscription', 15.99);
		$this->assertNotNull($result);
		$this->assertSame('Netflix', $result->getName());
	}

	public function testMatchTransactionToBillWithinTolerance(): void {
		$bill = $this->makeBill(['autoDetectPattern' => 'NETFLIX', 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		// Within 10% tolerance
		$result = $this->service->matchTransactionToBill('user1', 'NETFLIX Payment', 16.50);
		$this->assertNotNull($result);
	}

	public function testMatchTransactionToBillOutsideTolerance(): void {
		$bill = $this->makeBill(['autoDetectPattern' => 'NETFLIX', 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		// Way outside 10% tolerance
		$result = $this->service->matchTransactionToBill('user1', 'NETFLIX Premium', 25.00);
		$this->assertNull($result);
	}

	public function testMatchTransactionToBillNoPatternMatch(): void {
		$bill = $this->makeBill(['autoDetectPattern' => 'NETFLIX', 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		$result = $this->service->matchTransactionToBill('user1', 'SPOTIFY Premium', 9.99);
		$this->assertNull($result);
	}

	public function testMatchTransactionToBillCaseInsensitive(): void {
		$bill = $this->makeBill(['autoDetectPattern' => 'netflix', 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		$result = $this->service->matchTransactionToBill('user1', 'NETFLIX Subscription', 15.99);
		$this->assertNotNull($result);
	}

	public function testMatchTransactionToBillSkipsEmptyPattern(): void {
		$bill = $this->makeBill(['autoDetectPattern' => null, 'amount' => 15.99]);
		$this->mapper->method('findActive')->willReturn([$bill]);

		$result = $this->service->matchTransactionToBill('user1', 'Something', 15.99);
		$this->assertNull($result);
	}

	// ── findUpcoming ────────────────────────────────────────────────

	public function testFindUpcomingDeduplicates(): void {
		$bill1 = $this->makeBill(['id' => 1, 'nextDueDate' => '2024-01-10']);
		$bill2 = $this->makeBill(['id' => 2, 'nextDueDate' => '2024-01-20']);

		// bill1 appears in both overdue and upcoming
		$this->mapper->method('findOverdue')->willReturn([$bill1]);
		$this->mapper->method('findDueInRange')->willReturn([$bill1, $bill2]);

		$result = $this->service->findUpcoming('user1');

		$this->assertCount(2, $result);
	}

	public function testFindUpcomingSortsByDueDate(): void {
		$billLater = $this->makeBill(['id' => 1, 'nextDueDate' => '2099-06-20']);
		$billEarlier = $this->makeBill(['id' => 2, 'nextDueDate' => '2099-06-05']);

		$this->mapper->method('findOverdue')->willReturn([]);
		$this->mapper->method('findDueInRange')->willReturn([$billLater, $billEarlier]);

		$result = $this->service->findUpcoming('user1');

		$this->assertSame(2, $result[0]->getId());
		$this->assertSame(1, $result[1]->getId());
	}

	public function testFindUpcomingSortsByDueDateAscending(): void {
		$billLate = $this->makeBill(['id' => 1, 'nextDueDate' => '2099-12-01']);
		$billEarly = $this->makeBill(['id' => 2, 'nextDueDate' => '2099-01-01']);

		$this->mapper->method('findOverdue')->willReturn([]);
		$this->mapper->method('findDueInRange')->willReturn([$billLate, $billEarly]);

		$result = $this->service->findUpcoming('user1');

		$this->assertCount(2, $result);
		// Earlier due date first
		$this->assertSame('2099-01-01', $result[0]->getNextDueDate());
		$this->assertSame('2099-12-01', $result[1]->getNextDueDate());
	}

	// ── detectRecurringBills ────────────────────────────────────────

	public function testDetectRecurringBillsDelegatesToDetector(): void {
		$expected = [['description' => 'Netflix', 'amount' => 15.99]];
		$this->recurringDetector->expects($this->once())
			->method('detectRecurringBills')
			->with('user1', 6)
			->willReturn($expected);

		$result = $this->service->detectRecurringBills('user1', 6);
		$this->assertSame($expected, $result);
	}

	public function testDetectRecurringBillsAsksForTransfersWhenFindingTransfers(): void {
		$this->recurringDetector->expects($this->once())
			->method('detectRecurringBills')
			->with('user1', 6, true)
			->willReturn([]);

		$this->service->detectRecurringBills('user1', 6, true);
	}

	// ===== Auto-match bills from imported transactions (#274) =====

	/**
	 * Rows a payment may link: findTransaction() finds them, and linking
	 * hands the row back with the bill's id, as the real service does. The
	 * ones a bill booked are found by findBookedBillRows() as its query does.
	 */
	private function linkable(\OCA\Budget\Db\Transaction ...$rows): void {
		$byId = [];
		foreach ($rows as $row) {
			$byId[$row->getId()] = $row;
		}
		$this->transactionService->method('findTransaction')->willReturnCallback(fn (int $id) => $byId[$id] ?? null);
		$this->transactionService->method('findBookedBillRows')->willReturnCallback(
			fn (int $billId, string $type, string $notesPrefix, string $from, string $to) => array_values(array_filter(
				$byId,
				fn ($row) => $row->getBillId() === $billId && $row->getType() === $type
					&& str_starts_with((string)$row->getNotes(), $notesPrefix) && ($row->getImportId() ?? '') === ''
					&& ($row->getStatus() ?? 'cleared') !== 'scheduled'
					&& $row->getDate() >= $from && $row->getDate() <= $to
			))
		);
		$this->transactionService->method('findTransferArrivals')->willReturnCallback(
			fn (int $accountId, float $amount, string $from, string $to, float $margin) => array_values(array_filter(
				$byId,
				fn ($row) => $row->getAccountId() === $accountId && $row->getType() === 'credit'
					&& $row->getBillId() === null && $row->getLinkedTransactionId() === null
					&& ($row->getStatus() ?? 'cleared') !== 'scheduled'
					&& $row->getDate() >= $from && $row->getDate() <= $to
					&& abs((float)$row->getAmount() - $amount) <= $margin
			))
		);
		$this->transactionService->method('linkBillAsAccountOwner')->willReturnCallback(function (int $id, Bill $bill) use ($byId) {
			$byId[$id]->setBillId($bill->getId());
			return $byId[$id];
		});
	}

	private function makeImportedTx(array $overrides = []): \OCA\Budget\Db\Transaction {
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId($overrides['id'] ?? 500);
		$tx->setAccountId($overrides['accountId'] ?? 1);
		$tx->setDate($overrides['date'] ?? '2026-06-14');
		$tx->setDescription($overrides['description'] ?? 'NETFLIX PAYMENT 12345');
		$tx->setVendor($overrides['vendor'] ?? null);
		$tx->setAmount($overrides['amount'] ?? 15.99);
		$tx->setType($overrides['type'] ?? 'debit');
		$tx->setStatus($overrides['status'] ?? 'cleared');
		return $tx;
	}

	private function setupAutoMatchBill(array $overrides = []): Bill {
		$bill = $this->makeBill(array_merge([
			'autoDetectPattern' => 'NETFLIX',
			'nextDueDate' => '2026-06-15',
			'accountId' => 1,
		], $overrides));
		$this->mapper->method('findActive')->willReturn([$bill]);
		// markPaid loads + saves the SAME instance, so the advanced state
		// flows back into the matcher's reload naturally
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-07-15');
		return $bill;
	}

	public function testAutoMatchMarksBillPaidAndLinksTransaction(): void {
		$bill = $this->setupAutoMatchBill();
		$tx = $this->makeImportedTx();

		// The existing transaction gets LINKED — no new money movement
		$this->linkable($tx);
		// Nothing is booked for this payment; only the next occurrence pre-books
		$this->transactionService->expects($this->once())->method('createFromBill')->with('user1', $bill, null);

		$marked = $this->service->autoMatchPaidFromImport('user1', [$tx]);

		$this->assertSame(1, $marked);
		$this->assertSame(1, $tx->getBillId());
		$this->assertSame('2026-06-14', $bill->getLastPaidDate());
		$this->assertSame('2026-07-15', $bill->getNextDueDate());
	}

	public function testAnImportReplacesThePaymentMarkPaidAlreadyBooked(): void {
		// Marked paid on the due date, then the statement came in: the bank
		// row was added beside the payment and the balance dropped twice
		$bill = $this->setupAutoMatchBill(['nextDueDate' => '2026-07-15', 'lastPaidDate' => '2026-06-15']);
		$bill->setPaidUndoState(json_encode([
			'previousState' => ['nextDueDate' => '2026-06-15'],
			'createdTransactionIds' => [600],
			'scheduledTransactionIds' => [601],
			'linkedTransactionId' => null,
			'paidDate' => '2026-06-15',
		]));
		$booked = $this->makeImportedTx(['id' => 600, 'date' => '2026-06-15', 'description' => '']);
		$booked->setNotes('Auto-generated from bill: Netflix');
		$booked->setBillId(1);
		$imported = $this->makeImportedTx(['id' => 500, 'date' => '2026-06-16']);
		$imported->setImportId('bank-1');
		$this->linkable($booked, $imported);
		// What the user added to the booked payment goes to the bank row
		$this->transactionService->expects($this->once())->method('replaceBookedRow')
			->with($booked, $imported, 'Auto-generated from bill: Netflix', true);

		$this->assertSame(1, $this->service->autoMatchPaidFromImport('user1', [$imported]));

		$this->assertSame(1, $imported->getBillId());
		$this->assertSame('2026-07-15', $bill->getNextDueDate(), 'Not paid a second time');
		$snapshot = json_decode($bill->getPaidUndoState(), true);
		$this->assertSame(500, $snapshot['linkedTransactionId']);
		$this->assertSame([], $snapshot['createdTransactionIds']);
	}

	public function testANewestFirstStatementStillReplacesTheOlderBookedPayment(): void {
		// June was marked paid by hand. The statement lists July first, which
		// paid the next occurrence and moved the bill past June, so June's
		// bank row was left beside the booked payment.
		$bill = $this->setupAutoMatchBill(['nextDueDate' => '2026-07-15', 'lastPaidDate' => '2026-06-15']);
		$bill->setPaidUndoState(json_encode([
			'previousState' => ['nextDueDate' => '2026-06-15'], 'createdTransactionIds' => [600],
			'linkedTransactionId' => null, 'paidDate' => '2026-06-15',
		]));
		$booked = $this->makeImportedTx(['id' => 600, 'date' => '2026-06-15', 'description' => '']);
		$booked->setNotes('Auto-generated from bill: Netflix');
		$booked->setBillId(1);
		$june = $this->makeImportedTx(['id' => 501, 'date' => '2026-06-15']);
		$july = $this->makeImportedTx(['id' => 502, 'date' => '2026-07-14']);
		$this->linkable($booked, $june, $july);
		$this->transactionService->expects($this->once())->method('replaceBookedRow')->with($booked, $june);

		$this->assertSame(2, $this->service->autoMatchPaidFromImport('user1', [$july, $june]));

		$this->assertSame(1, $june->getBillId());
		$this->assertSame(1, $july->getBillId(), 'July pays the next occurrence');
	}

	public function testAnEarlierBookedPaymentIsReplacedToo(): void {
		// Auto-pay booked May and June; only the last payment, the one the
		// snapshot names, could take the bank's row in its place
		$bill = $this->setupAutoMatchBill(['nextDueDate' => '2026-07-15', 'lastPaidDate' => '2026-06-15']);
		$snapshot = json_encode(['previousState' => ['nextDueDate' => '2026-06-15'], 'createdTransactionIds' => [601], 'linkedTransactionId' => null, 'paidDate' => '2026-06-15']);
		$bill->setPaidUndoState($snapshot);
		$may = $this->makeImportedTx(['id' => 600, 'date' => '2026-05-15', 'description' => '']);
		$may->setNotes('Auto-generated from bill: Netflix');
		$may->setBillId(1);
		$bankMay = $this->makeImportedTx(['id' => 500, 'date' => '2026-05-16']);
		$this->linkable($may, $bankMay);
		$this->transactionService->expects($this->once())->method('replaceBookedRow')->with($may, $bankMay);

		$this->assertSame(1, $this->service->autoMatchPaidFromImport('user1', [$bankMay]));

		$this->assertSame($snapshot, $bill->getPaidUndoState(), 'June\'s payment can still be undone');
		$this->assertSame('2026-07-15', $bill->getNextDueDate());
	}

	public function testAnImportLeavesAReconciledPaymentAlone(): void {
		$bill = $this->setupAutoMatchBill(['nextDueDate' => '2026-07-15', 'lastPaidDate' => '2026-06-15']);
		$bill->setPaidUndoState(json_encode(['previousState' => [], 'createdTransactionIds' => [600], 'paidDate' => '2026-06-15']));
		$booked = $this->makeImportedTx(['id' => 600, 'date' => '2026-06-15']);
		$booked->setNotes('Auto-generated from bill: Netflix');
		$booked->setBillId(1);
		$booked->setReconciled(true);
		$imported = $this->makeImportedTx(['id' => 500, 'date' => '2026-06-16']);
		$this->linkable($booked, $imported);
		$this->transactionService->expects($this->never())->method('replaceBookedRow');
		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');

		$this->service->autoMatchPaidFromImport('user1', [$imported]);
	}

	public function testAnImportReplacesATransferPaymentMarkedLateRatherThanPayingTheNextOne(): void {
		// The 1 October occurrence marked paid on the 20th moved the transfer
		// to 1 November; the bank's withdrawal of the 20th fell inside
		// November's window, so November was paid too and the money moved twice
		$bill = $this->setupAutoMatchBill([
			'isTransfer' => true, 'destinationAccountId' => 2, 'autoDetectPattern' => null,
			'nextDueDate' => '2026-11-01', 'lastPaidDate' => '2026-10-20',
		]);
		$bill->setTransferDescriptionPattern('NETFLIX');
		$bill->setPaidUndoState(json_encode([
			'previousState' => ['nextDueDate' => '2026-10-01'],
			'createdTransactionIds' => [600, 601],
			'scheduledTransactionIds' => [602, 603],
			'linkedTransactionId' => null,
			'paidDate' => '2026-10-20',
		]));
		[$booked, $deposit] = $this->bookedTransferPair('2026-10-20');
		$imported = $this->makeImportedTx(['id' => 500, 'date' => '2026-10-20']);
		$imported->setImportId('bank-1');
		$this->linkable($booked, $deposit, $imported);
		// The booked deposit isn't the bank row's other side: it goes, and
		// the bank row gets its own arrival
		$this->transactionService->expects($this->once())->method('replaceBookedRow')
			->with($booked, $imported, 'Auto-generated transfer: Netflix', false);
		$this->transactionService->expects($this->once())->method('completeTransferPayment')
			->with($imported, $this->isInstanceOf(Bill::class))->willReturn(905);
		$this->transactionService->expects($this->once())->method('deleteAsAccountOwner')->with(601, false, 1)->willReturn(true);

		$this->assertSame(1, $this->service->autoMatchPaidFromImport('user1', [$imported]));

		$this->assertSame('2026-11-01', $bill->getNextDueDate(), 'Not paid a second time');
		$this->assertSame('2026-10-20', $bill->getLastPaidDate());
		$snapshot = json_decode($bill->getPaidUndoState(), true);
		$this->assertSame(500, $snapshot['linkedTransactionId']);
		$this->assertSame([905], $snapshot['createdTransactionIds']);
	}

	/**
	 * A transfer's withdrawal (600) and deposit (601) as Mark Paid books
	 * them on $date
	 *
	 * @return \OCA\Budget\Db\Transaction[]
	 */
	private function bookedTransferPair(string $date): array {
		$booked = $this->makeImportedTx(['id' => 600, 'date' => $date, 'description' => '']);
		$booked->setNotes('Auto-generated transfer: Netflix');
		$booked->setBillId(1);
		$booked->setLinkedTransactionId(601);
		$deposit = $this->makeImportedTx(['id' => 601, 'accountId' => 2, 'type' => 'credit', 'date' => $date, 'description' => '']);
		$deposit->setNotes('Auto-generated transfer: Netflix');
		$deposit->setBillId(1);
		$deposit->setLinkedTransactionId(600);
		return [$booked, $deposit];
	}

	public function testAReconciledDepositStaysAndIsPairedWithTheBanksWithdrawal(): void {
		// The savings account was reconciled with the booked deposit in it;
		// the swap deleted it and booked another
		$bill = $this->setupAutoMatchBill([
			'isTransfer' => true, 'destinationAccountId' => 2, 'autoDetectPattern' => null,
			'nextDueDate' => '2026-11-01', 'lastPaidDate' => '2026-10-20',
		]);
		$bill->setTransferDescriptionPattern('NETFLIX');
		$bill->setPaidUndoState(json_encode(['previousState' => [], 'createdTransactionIds' => [600, 601], 'linkedTransactionId' => null, 'paidDate' => '2026-10-20']));
		[$booked, $deposit] = $this->bookedTransferPair('2026-10-20');
		$deposit->setReconciled(true);
		$imported = $this->makeImportedTx(['id' => 500, 'date' => '2026-10-20']);
		$imported->setImportId('bank-1');
		$this->linkable($booked, $deposit, $imported);
		$this->transactionService->expects($this->once())->method('replaceBookedRow')
			->with($booked, $imported, 'Auto-generated transfer: Netflix', true);
		$this->transactionService->expects($this->never())->method('transferArrivalAmount');
		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');

		$this->assertSame(1, $this->service->autoMatchPaidFromImport('user1', [$imported]));

		$this->assertSame('2026-11-01', $bill->getNextDueDate());
	}

	public function testTheBanksCopyOfAReconciledPaymentPaysNothingElse(): void {
		// Marked paid late (20 October for 1 October) and reconciled: the
		// bank's row of it fell in November's window and paid November too
		$bill = $this->setupAutoMatchBill(['nextDueDate' => '2026-11-01', 'lastPaidDate' => '2026-10-20']);
		$bill->setPaidUndoState(json_encode(['previousState' => [], 'createdTransactionIds' => [600], 'linkedTransactionId' => null, 'paidDate' => '2026-10-20']));
		$booked = $this->makeImportedTx(['id' => 600, 'date' => '2026-10-20', 'description' => '']);
		$booked->setNotes('Auto-generated from bill: Netflix');
		$booked->setBillId(1);
		$booked->setReconciled(true);
		$imported = $this->makeImportedTx(['id' => 500, 'date' => '2026-10-20']);
		$this->linkable($booked, $imported);
		$this->transactionService->expects($this->never())->method('replaceBookedRow');
		$this->transactionService->expects($this->never())->method('linkBillAsAccountOwner');

		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$imported]));

		$this->assertSame('2026-11-01', $bill->getNextDueDate());
		$this->assertNull($imported->getBillId());
	}

	/**
	 * The savings statement came in first; Mark Paid then booked the
	 * transfer's deposit beside the bank's own credit of it
	 */
	public function testMarkPaidLetsTheDestinationsCreditAlreadyThereTakeTheDepositsPlace(): void {
		$bill = $this->setupAutoMatchBill([
			'isTransfer' => true, 'destinationAccountId' => 2, 'autoDetectPattern' => null,
			'nextDueDate' => '2026-06-15', 'createTransaction' => false,
		]);
		[$withdrawal, $deposit] = $this->bookedTransferPair('2026-06-15');
		$credit = $this->makeImportedTx(['id' => 500, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-14', 'description' => 'FROM CHECKING']);
		$credit->setImportId('bank-2');
		$this->linkable($withdrawal, $deposit, $credit);
		$this->transactionService->method('clearScheduledBillTransaction')->willReturn(null);
		$this->transactionService->method('createFromBill')->willReturn($withdrawal);
		$this->transactionService->method('findTransferArrivals')->willReturn([$credit]);
		$this->transactionService->expects($this->once())->method('replaceBookedRow')
			->with($deposit, $credit, 'Auto-generated transfer: Netflix');

		$result = $this->service->markPaid(1, 'user1', '2026-06-15', true);

		$this->assertTrue($result['paymentTransactionRecorded']);
		$snapshot = json_decode($bill->getPaidUndoState(), true);
		$this->assertSame([600], $snapshot['createdTransactionIds'], 'Mark Unpaid must not delete the bank\'s credit');
	}

	public function testMarkPaidLeavesACreditTheAppBookedForIncomeAlone(): void {
		$this->setupAutoMatchBill([
			'isTransfer' => true, 'destinationAccountId' => 2, 'autoDetectPattern' => null,
			'nextDueDate' => '2026-06-15', 'createTransaction' => false,
		]);
		[$withdrawal, $deposit] = $this->bookedTransferPair('2026-06-15');
		$wages = $this->makeImportedTx(['id' => 500, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-14', 'description' => '']);
		$wages->setNotes('Auto-generated from income: Wages');
		$this->linkable($withdrawal, $deposit, $wages);
		$this->transactionService->method('clearScheduledBillTransaction')->willReturn(null);
		$this->transactionService->method('createFromBill')->willReturn($withdrawal);
		$this->transactionService->expects($this->never())->method('replaceBookedRow');

		$this->service->markPaid(1, 'user1', '2026-06-15', true);
	}

	/**
	 * The withdrawal linked as the payment (an import, the Mark Paid dialog,
	 * auto-pay): the destination's credit already in, five days later, was
	 * left beside the deposit booked for it, as only three days were looked at
	 */
	public function testLinkingTheWithdrawalLetsTheDestinationsCreditAlreadyThereBeItsArrival(): void {
		$bill = $this->setupAutoMatchBill([
			'isTransfer' => true, 'destinationAccountId' => 2, 'autoDetectPattern' => null,
			'nextDueDate' => '2026-06-15', 'createTransaction' => false,
		]);
		$bankWithdrawal = $this->makeImportedTx(['id' => 700, 'date' => '2026-06-15', 'description' => 'SAVINGS TFR']);
		$bankWithdrawal->setImportId('bank-1');
		$deposit = $this->makeImportedTx(['id' => 601, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-15', 'description' => '']);
		$deposit->setNotes('Auto-generated transfer: Netflix');
		$deposit->setBillId(1);
		$deposit->setLinkedTransactionId(700);
		$credit = $this->makeImportedTx(['id' => 500, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-20', 'description' => 'FROM CHECKING']);
		$credit->setImportId('bank-2');
		$this->linkable($bankWithdrawal, $deposit, $credit);
		$this->transactionService->method('completeTransferPayment')->willReturn(601);
		$this->transactionService->expects($this->once())->method('replaceBookedRow')
			->with($deposit, $credit, 'Auto-generated transfer: Netflix');

		$this->service->markPaid(1, 'user1', null, false, 700);

		$snapshot = json_decode($bill->getPaidUndoState(), true);
		$this->assertSame([], $snapshot['createdTransactionIds'], 'Mark Unpaid must not delete the bank\'s credit');
		$this->assertSame(1, $credit->getBillId());
	}

	/**
	 * The salary came in two days after a 2,000 top-up was marked paid and
	 * was taken as the top-up's arrival: the income stayed expected and
	 * auto-create booked the salary a second time
	 */
	public function testARecurringIncomesCreditIsNeverATransfersArrival(): void {
		$salary = $this->makeImportedTx(['id' => 510, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-17', 'description' => 'ACME PAYROLL']);
		$topUp = $this->makeImportedTx(['id' => 511, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-19', 'description' => 'FROM SAVINGS']);
		[, $deposit] = $this->transferWithBookedDeposit([], [$salary, $topUp]);
		$income = new \OCA\Budget\Db\RecurringIncome();
		$income->setAccountId(2);
		$income->setAutoDetectPattern('ACME');
		$this->incomeMapper->method('findActiveByAccount')->with(2)->willReturn([$income]);
		$this->transactionService->expects($this->once())->method('replaceBookedRow')->with($deposit, $topUp);

		$this->service->autoMatchPaidFromImport('user1', [$topUp, $salary]);

		$this->assertNull($salary->getBillId(), 'Left for the income');
		$this->assertSame(1, $topUp->getBillId());
	}

	public function testTheCreditNamingTheTransferIsItsArrival(): void {
		// A joint account: the partner's own 15.99 came in the same day
		$partners = $this->makeImportedTx(['id' => 510, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-15', 'description' => 'BOB SMITH SHARE']);
		$ours = $this->makeImportedTx(['id' => 511, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-17', 'description' => 'A SMITH SAVINGS TFR']);
		[, $deposit] = $this->transferWithBookedDeposit([], [$partners, $ours]);
		$this->transactionService->expects($this->once())->method('replaceBookedRow')->with($deposit, $ours);

		$this->service->autoMatchPaidFromImport('user1', [$ours, $partners]);
	}

	public function testTheCreditNearestTheDepositIsItsArrivalNotTheOldest(): void {
		$earlier = $this->makeImportedTx(['id' => 510, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-04', 'description' => 'CREDIT']);
		$nearer = $this->makeImportedTx(['id' => 511, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-16', 'description' => 'CREDIT']);
		[, $deposit] = $this->transferWithBookedDeposit([], [$earlier, $nearer]);
		$this->transactionService->expects($this->once())->method('replaceBookedRow')->with($deposit, $nearer);

		$this->service->autoMatchPaidFromImport('user1', [$nearer, $earlier]);
	}

	public function testWithinOneCurrencyTheArrivalIsTheExactAmount(): void {
		[, , $credit] = $this->transferWithBookedDeposit();
		$credit->setAmount(16.50);
		$this->transactionService->expects($this->never())->method('replaceBookedRow');

		$this->service->autoMatchPaidFromImport('user1', [$credit]);
	}

	public function testBetweenCurrenciesTheArrivalMayBeATenthOff(): void {
		// The bank converts at its own rate: 117.40 for the app's 117.65
		[, $deposit, $credit] = $this->transferWithBookedDeposit();
		$credit->setAmount(15.20);
		$this->transactionService->method('transferBetweenCurrencies')->willReturn(true);
		$this->transactionService->expects($this->once())->method('replaceBookedRow')->with($deposit, $credit);

		$this->service->autoMatchPaidFromImport('user1', [$credit]);
	}

	public function testWhatWasAddedToABookedDepositGoesToTheArrivalTheBankRowGets(): void {
		$bill = $this->setupAutoMatchBill([
			'isTransfer' => true, 'destinationAccountId' => 2, 'autoDetectPattern' => null,
			'nextDueDate' => '2026-11-01', 'lastPaidDate' => '2026-10-20',
		]);
		$bill->setTransferDescriptionPattern('NETFLIX');
		$bill->setPaidUndoState(json_encode(['previousState' => [], 'createdTransactionIds' => [600, 601], 'linkedTransactionId' => null, 'paidDate' => '2026-10-20']));
		[$booked, $deposit] = $this->bookedTransferPair('2026-10-20');
		$imported = $this->makeImportedTx(['id' => 500, 'date' => '2026-10-20']);
		$imported->setImportId('bank-1');
		$arrival = $this->makeImportedTx(['id' => 905, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-10-20']);
		$this->linkable($booked, $deposit, $imported, $arrival);
		$this->transactionService->method('completeTransferPayment')->willReturnCallback(function () use ($imported) {
			$imported->setLinkedTransactionId(905);
			return 905;
		});
		$replaced = [];
		$this->transactionService->method('replaceBookedRow')->willReturnCallback(function ($from, $to) use (&$replaced) {
			$replaced[] = [$from->getId(), $to->getId()];
		});
		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');

		$this->assertSame(1, $this->service->autoMatchPaidFromImport('user1', [$imported]));

		$this->assertSame([[600, 500], [601, 905]], $replaced);
	}

	public function testAnImportedWithdrawalPaysARecurringTransfer(): void {
		// The transfer form's description pattern was never read and transfers
		// were left out of matching, so the statement's rows stayed apart and
		// Mark Paid then moved the money twice
		$bill = $this->setupAutoMatchBill(['isTransfer' => true, 'destinationAccountId' => 2, 'autoDetectPattern' => null]);
		$bill->setTransferDescriptionPattern('NETFLIX');
		$tx = $this->makeImportedTx();
		$this->linkable($tx);
		$this->transactionService->expects($this->once())->method('completeTransferPayment')
			->with($tx, $this->isInstanceOf(Bill::class))->willReturn(901);

		$this->assertSame(1, $this->service->autoMatchPaidFromImport('user1', [$tx]));

		$snapshot = json_decode($bill->getPaidUndoState(), true);
		$this->assertSame([901], $snapshot['createdTransactionIds'], 'A deposit booked here goes on revert');
	}

	public function testAWithdrawalIsNotLinkedToATransferWhoseArrivalCannotBePriced(): void {
		// No rate between the two currencies: the withdrawal was linked and
		// the pre-booked rows deleted before booking the arrival failed,
		// leaving the bill half paid. It is priced first now.
		$bill = $this->setupAutoMatchBill(['isTransfer' => true, 'destinationAccountId' => 2, 'autoDetectPattern' => null]);
		$bill->setTransferDescriptionPattern('NETFLIX');
		$tx = $this->makeImportedTx();
		$this->transactionService->method('findTransaction')->willReturn($tx);
		$this->transactionService->method('transferArrivalAmount')->willThrowException(new \Exception('No exchange rate between GBP and CHF'));
		$this->transactionService->expects($this->never())->method('linkBillAsAccountOwner');
		$this->transactionService->expects($this->never())->method('deleteScheduledBillTransactions');
		$this->transactionService->expects($this->never())->method('completeTransferPayment');

		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$tx]));
		$this->assertSame('2026-06-15', $bill->getNextDueDate());
		$this->assertNull($bill->getPaidUndoState());
	}

	public function testTheWithdrawalsArrivalIsPricedBeforeItIsLinked(): void {
		$bill = $this->setupAutoMatchBill(['isTransfer' => true, 'destinationAccountId' => 2, 'autoDetectPattern' => null]);
		$bill->setTransferDescriptionPattern('NETFLIX');
		$tx = $this->makeImportedTx();
		$this->linkable($tx);
		$this->transactionService->method('transferArrivalAmount')->willReturn(117.65);
		$this->transactionService->expects($this->once())->method('completeTransferPayment')
			->with($tx, $this->isInstanceOf(Bill::class), 117.65)->willReturn(901);

		$this->assertSame(1, $this->service->autoMatchPaidFromImport('user1', [$tx]));
	}

	/**
	 * A transfer into account 2 paid on 15 June, its deposit (601) booked
	 * by the app, and the destination's own credit of it just imported.
	 *
	 * @return array{0: Bill, 1: \OCA\Budget\Db\Transaction, 2: \OCA\Budget\Db\Transaction}
	 */
	private function transferWithBookedDeposit(array $deposit = [], ?array $credits = null): array {
		$bill = $this->setupAutoMatchBill([
			'isTransfer' => true, 'destinationAccountId' => 2, 'autoDetectPattern' => null,
			'nextDueDate' => '2026-07-15', 'lastPaidDate' => '2026-06-15',
		]);
		$bill->setTransferDescriptionPattern('SAVINGS TFR');
		$bill->setPaidUndoState(json_encode([
			'previousState' => ['nextDueDate' => '2026-06-15'],
			'createdTransactionIds' => [600, 601],
			'scheduledTransactionIds' => [602, 603],
			'linkedTransactionId' => null,
			'paidDate' => '2026-06-15',
		]));
		$booked = $this->makeImportedTx(array_merge(['id' => 601, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-15', 'description' => ''], $deposit));
		$booked->setNotes('Auto-generated transfer: Netflix');
		$booked->setBillId(1);
		$booked->setLinkedTransactionId(600);
		$credit = $this->makeImportedTx(['id' => 500, 'accountId' => 2, 'type' => 'credit', 'date' => '2026-06-16', 'description' => 'FROM CHECKING']);
		$credit->setImportId('bank-2');
		// $credits: the destination's own rows a test puts there instead
		$this->linkable($booked, ...($credits ?? [$credit]));
		return [$bill, $booked, $credit];
	}

	public function testTheDestinationsCreditTakesThePlaceOfTheDepositBookedForIt(): void {
		// Paying the transfer booked its arrival, and the destination's own
		// statement then brought the same money in a second time
		[$bill, $deposit, $credit] = $this->transferWithBookedDeposit();
		$this->transactionService->expects($this->once())->method('replaceBookedRow')->with($deposit, $credit);

		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$credit]), 'No bill was marked paid');

		$this->assertSame(1, $credit->getBillId());
		$this->assertSame('2026-07-15', $bill->getNextDueDate());
		$snapshot = json_decode($bill->getPaidUndoState(), true);
		$this->assertSame([600], $snapshot['createdTransactionIds'], 'Mark Unpaid must not delete the bank\'s credit');
	}

	public function testAReconciledDepositKeepsItsPlace(): void {
		[, $deposit, $credit] = $this->transferWithBookedDeposit();
		$deposit->setReconciled(true);
		$this->transactionService->expects($this->never())->method('replaceBookedRow');

		$this->service->autoMatchPaidFromImport('user1', [$credit]);

		$this->assertNull($credit->getBillId());
	}

	public function testACreditFarFromTheDepositsAmountIsNotItsArrival(): void {
		[, , $credit] = $this->transferWithBookedDeposit(['amount' => 30.0]);
		$this->transactionService->expects($this->never())->method('replaceBookedRow');

		$this->service->autoMatchPaidFromImport('user1', [$credit]);
	}

	public function testACreditAlreadyPairedIsLeftAlone(): void {
		// The import's own transfer matching, or a withdrawal earlier in the
		// same batch, took it as its arrival
		[, , $credit] = $this->transferWithBookedDeposit();
		$credit->setLinkedTransactionId(77);
		$this->transactionService->expects($this->never())->method('replaceBookedRow');

		$this->service->autoMatchPaidFromImport('user1', [$credit]);
	}

	public function testABankSyncHoldWaitsUntilItPosts(): void {
		[, , $credit] = $this->transferWithBookedDeposit();
		$credit->setStatus('pending');
		$this->transactionService->expects($this->never())->method('replaceBookedRow');

		$this->service->autoMatchPaidFromImport('user1', [$credit]);
	}

	public function testAutoMatchMatchesPatternInVendor(): void {
		$this->setupAutoMatchBill();
		$tx = $this->makeImportedTx(['description' => 'Card payment 9912', 'vendor' => 'Netflix Inc']);
		$this->linkable($tx);

		$this->assertSame(1, $this->service->autoMatchPaidFromImport('user1', [$tx]));
	}

	public function testAutoMatchSkipsTransactionOutsideDueWindow(): void {
		$this->setupAutoMatchBill();
		// 45 days before the due date — a historical re-import, not this period
		$tx = $this->makeImportedTx(['date' => '2026-05-01']);

		$this->transactionService->expects($this->never())->method('update');
		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$tx]));
	}

	public function testAutoMatchSkipsWrongAccount(): void {
		$this->setupAutoMatchBill(['accountId' => 1]);
		$tx = $this->makeImportedTx(['accountId' => 2]);

		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$tx]));
	}

	public function testAutoMatchSkipsAmountOutsideTolerance(): void {
		$this->setupAutoMatchBill(['amount' => 15.99]);
		$tx = $this->makeImportedTx(['amount' => 30.00]);

		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$tx]));
	}

	public function testAutoMatchSkipsCreditsAndScheduled(): void {
		$this->setupAutoMatchBill();

		$credit = $this->makeImportedTx(['type' => 'credit']);
		$scheduled = $this->makeImportedTx(['status' => 'scheduled']);

		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$credit, $scheduled]));
	}

	public function testAutoMatchNeverDoubleAdvancesInOneBatch(): void {
		$this->setupAutoMatchBill();
		// Two same-period payments (e.g. duplicate rows in a statement):
		// the first advances the due date to 2026-07-15, putting the second
		// outside the new window
		$tx1 = $this->makeImportedTx(['id' => 500, 'date' => '2026-06-14']);
		$tx2 = $this->makeImportedTx(['id' => 501, 'date' => '2026-06-16']);
		$this->linkable($tx1, $tx2);

		$this->assertSame(1, $this->service->autoMatchPaidFromImport('user1', [$tx1, $tx2]));
		$this->assertNull($tx2->getBillId());
	}

	public function testAutoMatchIgnoresTransferAndPatternlessBills(): void {
		$transfer = $this->makeBill(['id' => 1, 'isTransfer' => true, 'autoDetectPattern' => 'NETFLIX', 'nextDueDate' => '2026-06-15']);
		$patternless = $this->makeBill(['id' => 2, 'autoDetectPattern' => null, 'nextDueDate' => '2026-06-15']);
		$this->mapper->method('findActive')->willReturn([$transfer, $patternless]);

		$this->assertSame(0, $this->service->autoMatchPaidFromImport('user1', [$this->makeImportedTx()]));
	}

	// ── unrecorded payments (#274) ──────────────────────────────────

	public function testFindUnrecordedPaymentsFlagsPaidBillWithoutTransaction(): void {
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$bill = $this->makeBill(['id' => 7, 'name' => 'Hypothek', 'amount' => 2912.00, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('findAll')->willReturn([$bill]);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([]);

		$result = $this->service->findUnrecordedPayments('user1');

		$this->assertCount(1, $result);
		$this->assertSame(7, $result[0]['billId']);
		$this->assertSame('Hypothek', $result[0]['name']);
		$this->assertSame($paidDate, $result[0]['lastPaidDate']);
	}

	public function testFindUnrecordedPaymentsTellsATransfersDestination(): void {
		// The card offers Record transaction only when the user can write
		// both accounts a transfer posts into
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$transfer = $this->makeBill(['id' => 7, 'isTransfer' => true, 'accountId' => 1, 'destinationAccountId' => 4, 'lastPaidDate' => $paidDate]);
		$bill = $this->makeBill(['id' => 8, 'accountId' => 1, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('findAll')->willReturn([$transfer, $bill]);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([]);

		$result = $this->service->findUnrecordedPayments('user1');
		usort($result, static fn ($a, $b) => $a['billId'] <=> $b['billId']);

		$this->assertSame([true, 4], [$result[0]['isTransfer'], $result[0]['destinationAccountId']]);
		$this->assertSame([false, null], [$result[1]['isTransfer'], $result[1]['destinationAccountId']]);
	}

	public function testFindUnrecordedPaymentsIgnoresRecordedPayment(): void {
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('findAll')->willReturn([$bill]);

		// Linked payment dated 3 days off the paid date still counts
		$tx = $this->makeImportedTx(['date' => date('Y-m-d', strtotime('-13 days'))]);
		$tx->setBillId(7);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([$tx]);

		$this->assertSame([], $this->service->findUnrecordedPayments('user1'));
	}

	public function testFindUnrecordedPaymentsIgnoresOldAndNeverPaidBills(): void {
		$old = $this->makeBill(['id' => 1, 'lastPaidDate' => date('Y-m-d', strtotime('-90 days'))]);
		$never = $this->makeBill(['id' => 2, 'lastPaidDate' => null]);
		$this->mapper->method('findAll')->willReturn([$old, $never]);
		$this->transactionService->expects($this->never())->method('findRecordedBillTransactions');

		$this->assertSame([], $this->service->findUnrecordedPayments('user1'));
	}

	public function testRecordMissedPaymentCreatesClearedTransactionOnPaidDate(): void {
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([]);

		$created = $this->makeImportedTx(['id' => 900, 'date' => $paidDate]);
		$this->transactionService->expects($this->once())
			->method('createFromBill')
			->with('user1', $bill, $paidDate, 'cleared')
			->willReturn($created);

		$result = $this->service->recordMissedPayment(7, 'user1');

		$this->assertSame(900, $result['transaction']->getId());
	}

	public function testRecordMissedPaymentRefusesWhenAlreadyRecorded(): void {
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('find')->willReturn($bill);

		$tx = $this->makeImportedTx(['date' => $paidDate]);
		$tx->setBillId(7);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([$tx]);
		$this->transactionService->expects($this->never())->method('createFromBill');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->recordMissedPayment(7, 'user1');
	}

	/**
	 * What markPaid() leaves on the bill for the payment made on $paidDate:
	 * the rows it recorded or linked.
	 */
	private function paymentSnapshot(string $paidDate, array $createdIds = [], ?int $linkedId = null): string {
		return json_encode([
			'previousState' => ['lastPaidDate' => null, 'nextDueDate' => $paidDate],
			'createdTransactionIds' => $createdIds,
			'scheduledTransactionIds' => [],
			'linkedTransactionId' => $linkedId,
			'hadScheduledTransaction' => false,
			'paidDate' => $paidDate,
		]);
	}

	private function billRow(int $id, int $billId, string $date): \OCA\Budget\Db\Transaction {
		$tx = $this->makeImportedTx(['id' => $id, 'date' => $date]);
		$tx->setBillId($billId);
		return $tx;
	}

	public function testAPaymentMovedToItsBankDateStillCountsAsRecorded(): void {
		// Marked paid today, then the row corrected to the day the bank took
		// it, three weeks back
		$paidDate = date('Y-m-d', strtotime('-1 day'));
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$bill->setPaidUndoState($this->paymentSnapshot($paidDate, [900]));
		$this->mapper->method('findAll')->willReturn([$bill]);
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->method('findRecordedBillTransactions')
			->willReturn([$this->billRow(900, 7, date('Y-m-d', strtotime('-22 days')))]);

		$this->assertSame([], $this->service->findUnrecordedPayments('user1'));

		// ...and Record transaction won't book it a second time
		$this->transactionService->expects($this->never())->method('createFromBill');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->recordMissedPayment(7, 'user1');
	}

	public function testALinkedBankRowFromWeeksBeforeTheClickCountsAsRecorded(): void {
		// An overdue bill marked paid by linking the bank's row from its due
		// date, 20 days before the click
		$paidDate = date('Y-m-d');
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$bill->setPaidUndoState($this->paymentSnapshot($paidDate, [], 901));
		$this->mapper->method('findAll')->willReturn([$bill]);
		$this->transactionService->method('findRecordedBillTransactions')
			->willReturn([$this->billRow(901, 7, date('Y-m-d', strtotime('-20 days')))]);

		$this->assertSame([], $this->service->findUnrecordedPayments('user1'));
	}

	public function testAWeeklyPaymentWithoutATransactionIsFlaggedThoughLastWeeksHasOne(): void {
		$paidDate = date('Y-m-d', strtotime('-2 days'));
		$bill = $this->makeBill(['id' => 7, 'frequency' => 'weekly', 'lastPaidDate' => $paidDate]);
		$bill->setPaidUndoState($this->paymentSnapshot($paidDate, []));
		$this->mapper->method('findAll')->willReturn([$bill]);
		// Last week's payment, recorded with a transaction
		$this->transactionService->method('findRecordedBillTransactions')
			->willReturn([$this->billRow(800, 7, date('Y-m-d', strtotime('-9 days')))]);

		$result = $this->service->findUnrecordedPayments('user1');

		$this->assertCount(1, $result);
		$this->assertSame(7, $result[0]['billId']);
	}

	public function testOverdueOccurrencesCaughtUpOnOneDayAreEachChecked(): void {
		// Two paid today: the first with a transaction, the second without.
		// The second payment's snapshot remembers the first was paid today.
		$paidDate = date('Y-m-d');
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$snapshot = json_decode($this->paymentSnapshot($paidDate, []), true);
		$snapshot['previousState']['lastPaidDate'] = $paidDate;
		$bill->setPaidUndoState(json_encode($snapshot));
		$this->mapper->method('findAll')->willReturn([$bill]);
		$this->transactionService->method('findRecordedBillTransactions')
			->willReturn([$this->billRow(800, 7, $paidDate)]);

		$this->assertCount(1, $this->service->findUnrecordedPayments('user1'));
	}

	public function testARowRecordedBy254ButNotNamedInTheSnapshotCounts(): void {
		// 2.54.0's Record transaction booked the row on the paid date and
		// never added it to the snapshot: the card listed the payment again
		// and Record booked it a second time
		$paidDate = date('Y-m-d', strtotime('-3 days'));
		$bill = $this->makeBill(['id' => 7, 'frequency' => 'weekly', 'lastPaidDate' => $paidDate]);
		$snapshot = json_decode($this->paymentSnapshot($paidDate, []), true);
		$snapshot['previousState']['lastPaidDate'] = date('Y-m-d', strtotime('-10 days'));
		$bill->setPaidUndoState(json_encode($snapshot));
		$this->mapper->method('findAll')->willReturn([$bill]);
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->method('findRecordedBillTransactions')
			->willReturn([$this->billRow(903, 7, $paidDate)]);

		$this->assertSame([], $this->service->findUnrecordedPayments('user1'));

		$this->transactionService->expects($this->never())->method('createFromBill');
		$this->expectException(\InvalidArgumentException::class);
		$this->service->recordMissedPayment(7, 'user1');
	}

	public function testWithoutASnapshotAWeeklyBillsPreviousPaymentDoesNotCount(): void {
		// Payments from before bills kept a record of their rows: matched by
		// date, within half the bill's interval
		$paidDate = date('Y-m-d', strtotime('-2 days'));
		$weekly = $this->makeBill(['id' => 7, 'frequency' => 'weekly', 'lastPaidDate' => $paidDate]);
		$biweekly = $this->makeBill(['id' => 8, 'frequency' => 'biweekly', 'lastPaidDate' => $paidDate]);
		$this->mapper->method('findAll')->willReturn([$weekly, $biweekly]);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([
			$this->billRow(800, 7, date('Y-m-d', strtotime('-9 days'))),
			$this->billRow(801, 8, date('Y-m-d', strtotime('-16 days'))),
		]);

		$this->assertEqualsCanonicalizing([7, 8], array_column($this->service->findUnrecordedPayments('user1'), 'billId'));
	}

	public function testRecordingAMissedPaymentAddsItToThePaymentsSnapshot(): void {
		// So the card sees it from then on, and Mark Unpaid takes it back
		// along with the payment
		$paidDate = date('Y-m-d', strtotime('-3 days'));
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$bill->setPaidUndoState($this->paymentSnapshot($paidDate, []));
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([]);
		$this->transactionService->method('createFromBill')
			->willReturn($this->makeImportedTx(['id' => 902, 'date' => $paidDate]));

		$saved = null;
		$this->mapper->expects($this->once())->method('update')
			->willReturnCallback(function (Bill $b) use (&$saved) {
				$saved = json_decode($b->getPaidUndoState(), true);
				return $b;
			});

		$this->service->recordMissedPayment(7, 'user1');

		$this->assertSame([902], $saved['createdTransactionIds']);
		$this->assertSame($paidDate, $saved['paidDate']);
	}

	public function testRecordMissedPaymentRefusesWithoutAccount(): void {
		// makeBill's `?? 1` default swallows a null override, so unset explicitly
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => date('Y-m-d')]);
		$bill->setAccountId(null);
		$this->mapper->method('find')->willReturn($bill);

		$this->expectException(\InvalidArgumentException::class);
		$this->service->recordMissedPayment(7, 'user1');
	}

	// ── dismissing an unrecorded payment (#394) ─────────────────────

	public function testFindUnrecordedPaymentsSkipsADismissedPayment(): void {
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('findAll')->willReturn([$bill]);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([]);
		$this->dismissedMapper->method('findHashes')
			->with('user1', 'unrecorded')
			->willReturn([sha1("7:{$paidDate}")]);

		$this->assertSame([], $this->service->findUnrecordedPayments('user1'));
	}

	/** A dismissal covers one payment: the next one without a transaction is flagged again. */
	public function testFindUnrecordedPaymentsFlagsTheNextPaymentAfterADismissal(): void {
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$earlier = date('Y-m-d', strtotime('-40 days'));
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('findAll')->willReturn([$bill]);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([]);
		$this->dismissedMapper->method('findHashes')->willReturn([sha1("7:{$earlier}")]);

		$result = $this->service->findUnrecordedPayments('user1');

		$this->assertCount(1, $result);
		$this->assertSame(7, $result[0]['billId']);
	}

	public function testFindUnrecordedPaymentsSaysWhetherThePaymentCanBeReverted(): void {
		$paidDate = date('Y-m-d', strtotime('-10 days'));
		$revertible = $this->makeBill(['id' => 7, 'lastPaidDate' => $paidDate]);
		$revertible->setPaidUndoState(json_encode(['previousState' => ['lastPaidDate' => null]]));
		$plain = $this->makeBill(['id' => 8, 'lastPaidDate' => $paidDate]);
		$this->mapper->method('findAll')->willReturn([$revertible, $plain]);
		$this->transactionService->method('findRecordedBillTransactions')->willReturn([]);

		$byId = array_column($this->service->findUnrecordedPayments('user1'), null, 'billId');

		$this->assertTrue($byId[7]['canMarkUnpaid']);
		$this->assertFalse($byId[8]['canMarkUnpaid']);
	}

	public function testDismissUnrecordedPaymentRemembersTheBillAndItsPaidDate(): void {
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => '2026-07-25']);
		$this->mapper->method('find')->willReturn($bill);
		$this->dismissedMapper->expects($this->once())
			->method('dismiss')
			->with('user1', 'unrecorded', sha1('7:2026-07-25'), '7:2026-07-25');

		$this->service->dismissUnrecordedPayment(7, 'user1');
	}

	public function testDismissUnrecordedPaymentRefusesABillNeverMarkedPaid(): void {
		$bill = $this->makeBill(['id' => 7, 'lastPaidDate' => null]);
		$this->mapper->method('find')->willReturn($bill);
		$this->dismissedMapper->expects($this->never())->method('dismiss');

		$this->expectException(\InvalidArgumentException::class);
		$this->service->dismissUnrecordedPayment(7, 'user1');
	}

	// ── amountType (#347) ───────────────────────────────────────────

	public function testBillSerializesAmountTypeWithFixedDefault(): void {
		$bill = $this->makeBill();
		$this->assertSame('fixed', $bill->jsonSerialize()['amountType']);

		$bill->setAmountType('statement');
		$this->assertSame('statement', $bill->jsonSerialize()['amountType']);
	}

	private function makeCardAccount(int $id = 20, string $type = 'credit_card'): \OCA\Budget\Db\Account {
		$account = new \OCA\Budget\Db\Account();
		$account->setId($id);
		$account->setUserId('user1');
		$account->setName('Visa');
		$account->setType($type);
		$account->setCurrency('GBP');
		return $account;
	}

	public function testCreateStatementBillRequiresTransferWithDestination(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('requires a transfer with a destination account');

		$this->service->create(
			userId: 'user1', name: 'Visa payment', amount: 0.0,
			amountType: 'statement'
		);
	}

	public function testCreateStatementBillRejectsNonCardDestination(): void {
		$this->accountMapper->method('findById')->willReturn($this->makeCardAccount(20, 'checking'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('only available for transfers to a credit card');

		$this->service->create(
			userId: 'user1', name: 'Visa payment', amount: 0.0,
			accountId: 1, isTransfer: true, destinationAccountId: 20,
			amountType: 'statement'
		);
	}

	public function testCreateStatementBillResolvesInitialEstimate(): void {
		$this->accountMapper->method('findById')->willReturn($this->makeCardAccount());
		$this->transactionService->method('getStatementAmountForAccount')
			->with(20, $this->anything())
			->willReturn(440.0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-09-15');
		$this->mapper->method('insert')->willReturnCallback(fn (Bill $b) => $b);

		$bill = $this->service->create(
			userId: 'user1', name: 'Visa payment', amount: 0.0, frequency: 'monthly',
			dueDay: 15, accountId: 1, isTransfer: true, destinationAccountId: 20,
			amountType: 'statement'
		);

		$this->assertSame('statement', $bill->getAmountType());
		$this->assertEqualsWithDelta(440.0, $bill->getAmount(), 0.001);
	}

	public function testMarkPaidStatementBillResolvesAmountAtDueDate(): void {
		$bill = $this->makeBill([
			'isTransfer' => true, 'destinationAccountId' => 20,
			'amount' => 300.0, 'nextDueDate' => '2026-08-15',
		]);
		$bill->setAmountType('statement');
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-09-15');
		$this->transactionService->expects($this->once())
			->method('getStatementAmountForAccount')
			->with(20, '2026-08-15')
			->willReturn(440.0);
		$this->transactionService->expects($this->once())
			->method('clearScheduledBillTransaction')
			->with('user1', 1, $this->anything(), 440.0)
			->willReturn(null);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(55);
		$this->transactionService->method('createFromBill')->willReturn($tx);

		$result = $this->service->markPaid(1, 'user1');

		$this->assertEqualsWithDelta(440.0, $result['bill']->getAmount(), 0.001);
		$this->assertEqualsWithDelta(300.0, $result['previousState']['amount'], 0.001);
		$this->assertEqualsWithDelta(440.0, $result['statementAmount'], 0.001);
	}

	public function testMarkPaidFixedBillNeverResolvesStatementAmount(): void {
		$bill = $this->makeBill(['nextDueDate' => '2026-08-15']);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-09-15');
		$this->transactionService->expects($this->never())->method('getStatementAmountForAccount');
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(55);
		$this->transactionService->method('createFromBill')->willReturn($tx);

		$result = $this->service->markPaid(1, 'user1');

		$this->assertEqualsWithDelta(15.99, $result['bill']->getAmount(), 0.001);
		$this->assertNull($result['statementAmount']);
	}

	public function testMarkPaidCurrentBalanceResolvesAtPaidDate(): void {
		// current_balance pays everything owed at payment time, so the
		// boundary is the paid date, not the due date
		$bill = $this->makeBill([
			'isTransfer' => true, 'destinationAccountId' => 20,
			'amount' => 300.0, 'nextDueDate' => '2026-08-15',
		]);
		$bill->setAmountType('current_balance');
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-09-15');
		$this->transactionService->expects($this->once())
			->method('getStatementAmountForAccount')
			->with(20, '2026-08-19')
			->willReturn(475.0);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(55);
		$this->transactionService->method('createFromBill')->willReturn($tx);

		$result = $this->service->markPaid(1, 'user1', '2026-08-19');

		$this->assertEqualsWithDelta(475.0, $result['bill']->getAmount(), 0.001);
		$this->assertEqualsWithDelta(475.0, $result['statementAmount'], 0.001);
	}

	public function testMarkPaidMinimumPaymentUsesCardMinimum(): void {
		$card = $this->makeCardAccount();
		$card->setMinimumPayment(50.0);
		$this->accountMapper->method('findById')->willReturn($card);
		$bill = $this->makeBill([
			'isTransfer' => true, 'destinationAccountId' => 20,
			'amount' => 300.0, 'nextDueDate' => '2026-08-15',
		]);
		$bill->setAmountType('minimum_payment');
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-09-15');
		$this->transactionService->method('getStatementAmountForAccount')
			->with(20, '2026-08-19')
			->willReturn(475.0);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(55);
		$this->transactionService->method('createFromBill')->willReturn($tx);

		$result = $this->service->markPaid(1, 'user1', '2026-08-19');

		$this->assertEqualsWithDelta(50.0, $result['bill']->getAmount(), 0.001);
	}

	public function testMarkPaidMinimumPaymentNeverExceedsOwed(): void {
		// Owing less than the minimum: pay what is owed, like real cards
		$card = $this->makeCardAccount();
		$card->setMinimumPayment(50.0);
		$this->accountMapper->method('findById')->willReturn($card);
		$bill = $this->makeBill([
			'isTransfer' => true, 'destinationAccountId' => 20,
			'amount' => 300.0, 'nextDueDate' => '2026-08-15',
		]);
		$bill->setAmountType('minimum_payment');
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-09-15');
		$this->transactionService->method('getStatementAmountForAccount')->willReturn(30.0);
		$tx = new \OCA\Budget\Db\Transaction();
		$tx->setId(55);
		$this->transactionService->method('createFromBill')->willReturn($tx);

		$result = $this->service->markPaid(1, 'user1', '2026-08-19');

		$this->assertEqualsWithDelta(30.0, $result['bill']->getAmount(), 0.001);
	}

	/**
	 * "Minimum payment" to a card with no minimum stored resolved to
	 * min(0, owed) = 0.00: a 0.00 pair booked every month, the transfer
	 * shown paid, and the card never paid down (#399 review, F60).
	 */
	public function testCreateMinimumPaymentBillNeedsTheCardsMinimum(): void {
		$this->accountMapper->method('findById')->willReturn($this->makeCardAccount());
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('minimum payment');

		$this->service->create(
			userId: 'user1', name: 'Visa payment', amount: 0.0, frequency: 'monthly',
			dueDay: 15, accountId: 1, isTransfer: true, destinationAccountId: 20,
			amountType: 'minimum_payment'
		);
	}

	public function testPayingAMinimumPaymentBillRefusesOnceTheCardsMinimumIsCleared(): void {
		$this->accountMapper->method('findById')->willReturn($this->makeCardAccount());
		$bill = $this->makeBill([
			'isTransfer' => true, 'destinationAccountId' => 20,
			'amount' => 50.0, 'nextDueDate' => '2026-08-15',
		]);
		$bill->setAmountType('minimum_payment');
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->method('getStatementAmountForAccount')->willReturn(500.0);
		$this->transactionService->expects($this->never())->method('createFromBill');
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('minimum payment');

		$this->service->markPaid(1, 'user1', '2026-08-19');
	}

	public function testCreateCurrentBalanceBillRequiresCardDestination(): void {
		$this->accountMapper->method('findById')->willReturn($this->makeCardAccount(20, 'savings'));

		$this->expectException(\InvalidArgumentException::class);

		$this->service->create(
			userId: 'user1', name: 'Visa payment', amount: 0.0,
			accountId: 1, isTransfer: true, destinationAccountId: 20,
			amountType: 'current_balance'
		);
	}

	public function testUndoPaidRestoresPreviousAmount(): void {
		$bill = $this->makeBill(['amount' => 440.0]);
		$bill->setAmountType('statement');
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$restored = $this->service->undoPaid(1, 'user1', [
			'lastPaidDate' => null, 'nextDueDate' => '2026-08-15',
			'isActive' => true, 'amount' => 300.0,
		], []);

		$this->assertEqualsWithDelta(300.0, $restored->getAmount(), 0.001);
	}

	// ── durable mark-as-unpaid (#365) ───────────────────────────────

	private function makePaidUndoSnapshot(array $overrides = []): string {
		return json_encode(array_merge([
			'previousState' => [
				'lastPaidDate' => null,
				'nextDueDate' => '2099-06-15',
				'remainingPayments' => null,
				'isActive' => true,
				'autoPayFailed' => false,
				'amount' => 15.99,
			],
			'createdTransactionIds' => [55, 56],
			'hadScheduledTransaction' => false,
			'paidDate' => '2099-06-15',
		], $overrides));
	}

	public function testMarkPaidPersistsUndoSnapshot(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-06-15', 'remainingPayments' => 3]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$payment = new \OCA\Budget\Db\Transaction();
		$payment->setId(55);
		$placeholder = new \OCA\Budget\Db\Transaction();
		$placeholder->setId(56);
		$this->transactionService->method('createFromBill')
			->willReturnOnConsecutiveCalls($payment, $placeholder);

		$result = $this->service->markPaid(1, 'user1');

		$raw = $result['bill']->getPaidUndoState();
		$this->assertNotNull($raw, 'markPaid must persist the undo snapshot on the bill');
		$decoded = json_decode($raw, true);
		$this->assertSame('2099-06-15', $decoded['previousState']['nextDueDate']);
		$this->assertSame(3, $decoded['previousState']['remainingPayments']);
		// The payment leg and the next-occurrence placeholder are tracked
		// separately: the placeholder may materialise into a real ledger row
		// before the snapshot is used, and must then survive the revert.
		$this->assertSame([55], $decoded['createdTransactionIds']);
		$this->assertSame([56], $decoded['scheduledTransactionIds']);
		$this->assertNull($decoded['linkedTransactionId']);
		$this->assertFalse($decoded['hadScheduledTransaction']);
		$this->assertSame(date('Y-m-d'), $decoded['paidDate']);
	}

	public function testMarkUnpaidRestoresRecurringBill(): void {
		$bill = $this->makeBill([
			'nextDueDate' => '2099-07-15', 'remainingPayments' => 2,
			'lastPaidDate' => '2099-06-15',
		]);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot([
			'previousState' => [
				'lastPaidDate' => null, 'nextDueDate' => '2099-06-15',
				'remainingPayments' => 3, 'isActive' => true,
				'autoPayFailed' => false, 'amount' => 15.99,
			],
		]));
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$deleted = [];
		$this->transactionService->method('deleteAsAccountOwner')
			->willReturnCallback(function (int $id) use (&$deleted) {
				$deleted[] = $id;
				return true;
			});

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertSame('2099-06-15', $restored->getNextDueDate());
		$this->assertSame(3, $restored->getRemainingPayments());
		$this->assertNull($restored->getLastPaidDate());
		$this->assertSame([55, 56], $deleted);
		$this->assertNull($restored->getPaidUndoState(), 'snapshot must be cleared after use');
	}

	public function testMarkUnpaidReactivatesOneTimeBill(): void {
		$bill = $this->makeBill([
			'frequency' => 'one-time', 'isActive' => false,
			'lastPaidDate' => '2099-06-15',
		]);
		$bill->setNextDueDate(null);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertTrue($restored->getIsActive(), 'one-time bill must be reactivated');
		$this->assertSame('2099-06-15', $restored->getNextDueDate());
		$this->assertNull($restored->getLastPaidDate());
		$this->assertNull($restored->getPaidUndoState());
	}

	public function testMarkUnpaidRestoresStatementAmountFromSnapshot(): void {
		// Statement amounts resolve from the card ledger at payment time and
		// cannot be re-derived later (#347) — only the snapshot can restore it.
		$bill = $this->makeBill([
			'isTransfer' => true, 'destinationAccountId' => 20, 'amount' => 440.0,
		]);
		$bill->setAmountType('statement');
		$bill->setPaidUndoState($this->makePaidUndoSnapshot([
			'previousState' => [
				'lastPaidDate' => null, 'nextDueDate' => '2099-06-15',
				'remainingPayments' => null, 'isActive' => true,
				'autoPayFailed' => false, 'amount' => 300.0,
			],
		]));
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertEqualsWithDelta(300.0, $restored->getAmount(), 0.001);
	}

	public function testMarkUnpaidToleratesAlreadyDeletedTransactions(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-07-15', 'lastPaidDate' => '2099-06-15']);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->transactionService->method('deleteAsAccountOwner')
			->willThrowException(new \Exception('already gone'));

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertSame('2099-06-15', $restored->getNextDueDate());
		$this->assertNull($restored->getPaidUndoState());
	}

	public function testMarkUnpaidRecreatesScheduledPlaceholderFromSnapshot(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-07-15', 'lastPaidDate' => '2099-06-15']);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot(['hadScheduledTransaction' => true]));
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->transactionService->expects($this->once())->method('createFromBill');

		$this->service->markUnpaid(1, 'user1');
	}

	/**
	 * A payment reconciled against a bank statement was deleted by Mark
	 * Unpaid with no warning, and the account stopped matching the statement.
	 */
	public function testMarkUnpaidAsksBeforeDeletingAReconciledPayment(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-07-15', 'lastPaidDate' => '2099-06-15']);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->method('countReconciledBillRows')->with([55, 56], 1)->willReturn(1);
		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');
		$this->mapper->expects($this->never())->method('update');

		$this->expectException(\OCA\Budget\Exception\ReconciledPaymentException::class);

		$this->service->markUnpaid(1, 'user1');
	}

	public function testMarkUnpaidGoesAheadOnceTheUserConfirms(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-07-15', 'lastPaidDate' => '2099-06-15']);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->transactionService->method('countReconciledBillRows')->willReturn(1);
		$this->transactionService->expects($this->atLeastOnce())->method('deleteAsAccountOwner');

		$restored = $this->service->markUnpaid(1, 'user1', true);

		$this->assertSame('2099-06-15', $restored->getNextDueDate());
	}

	/**
	 * A deleted bill's payments kept its id, so a bill set up again in its
	 * place never offered them in Mark Paid, and the payment was recorded a
	 * second time.
	 */
	public function testDeletingABillLetsGoOfItsRecordedPayments(): void {
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->expects($this->once())->method('deleteScheduledBillTransactions')->with(1);
		$this->transactionService->expects($this->once())->method('detachBillPayments')->with(1);
		$this->mapper->expects($this->once())->method('delete')->with($bill);

		$this->service->delete(1, 'user1');
	}

	public function testMarkUnpaidWithoutSnapshotThrows(): void {
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('This bill has no recorded payment to undo');

		$this->service->markUnpaid(1, 'user1');
	}

	public function testMarkUnpaidWithCorruptSnapshotThrows(): void {
		$bill = $this->makeBill();
		$bill->setPaidUndoState('not json');
		$this->mapper->method('find')->willReturn($bill);

		$this->expectException(\InvalidArgumentException::class);

		$this->service->markUnpaid(1, 'user1');
	}

	public function testUndoPaidClearsPersistedSnapshot(): void {
		// The toast path and the durable path share the revert — either one
		// consumes the stored snapshot.
		$bill = $this->makeBill();
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$restored = $this->service->undoPaid(1, 'user1', [
			'lastPaidDate' => null, 'nextDueDate' => '2099-06-15', 'isActive' => true,
		], []);

		$this->assertNull($restored->getPaidUndoState());
	}

	public function testBillSerializesCanMarkUnpaidHintNotRawSnapshot(): void {
		$bill = $this->makeBill();
		$this->assertFalse($bill->jsonSerialize()['canMarkUnpaid']);

		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$json = $bill->jsonSerialize();
		$this->assertTrue($json['canMarkUnpaid']);
		$this->assertArrayNotHasKey('paidUndoState', $json, 'internal blob must not reach the frontend');
	}

	// ── mark-unpaid review round (#363, #364, #365) ─────────────────

	public function testUndoPaidDeletesTransactionsUnderTheAccountOwner(): void {
		// markPaid created the payment rows under the ACCOUNT owner (#334) —
		// for a bill on a shared account that is not the acting user, so the
		// revert must route every deletion through the owner-resolving path.
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->transactionService->expects($this->never())->method('delete');
		$deleted = [];
		$this->transactionService->method('deleteAsAccountOwner')
			->willReturnCallback(function (int $id, bool $onlyIfScheduled = false) use (&$deleted) {
				$deleted[] = $id;
				return true;
			});

		$this->service->undoPaid(1, 'user1', [
			'lastPaidDate' => null, 'nextDueDate' => '2099-06-15', 'isActive' => true,
		], [55, 56]);

		$this->assertSame([55, 56], $deleted);
	}

	public function testUpdateEditOnThePaidDayKeepsTheAdvancedDueDate(): void {
		// A biweekly bill anchored on today's grid was paid TODAY: next due
		// advanced to D+14. The consistency check used to recompute "on or
		// after today", which is today itself — snapping the bill back onto
		// the just-paid occurrence, which auto-pay then paid again.
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn (...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));

		$today = new \DateTime();
		$bill = $this->makeBill([
			'frequency' => 'biweekly',
			'dueDay' => (int)$today->format('N'),
			'nextDueDate' => (clone $today)->modify('+14 days')->format('Y-m-d'),
			'lastPaidDate' => $today->format('Y-m-d'),
		]);
		$bill->setStartDate((clone $today)->modify('-28 days')->format('Y-m-d'));
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['name' => 'Renamed']);

		$this->assertNotNull($captured);
		$this->assertArrayNotHasKey('next_due_date', $captured, 'an edit on the paid day must not snap next due back to the paid occurrence');
	}

	public function testUpdateKeepsAnOverdueDueDate(): void {
		// Overdue anchored bill: stored due 3 days ago, previous occurrence
		// paid one period earlier. The overdue date is real, unpaid state —
		// an unrelated edit must not silently snap it into the future.
		$this->frequencyCalculator->method('calculateNextDueDate')
			->willReturnCallback(fn (...$args) => (new FrequencyCalculator())->calculateNextDueDate(...$args));

		$today = new \DateTime();
		$overdue = (clone $today)->modify('-3 days')->format('Y-m-d');
		$bill = $this->makeBill([
			'frequency' => 'biweekly',
			'dueDay' => (int)(new \DateTime($overdue))->format('N'),
			'nextDueDate' => $overdue,
			'lastPaidDate' => (clone $today)->modify('-17 days')->format('Y-m-d'),
		]);
		$bill->setStartDate((clone $today)->modify('-31 days')->format('Y-m-d'));
		$this->mapper->method('find')->willReturn($bill);

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['name' => 'Renamed']);

		$this->assertNotNull($captured);
		$this->assertArrayNotHasKey('next_due_date', $captured, 'an unrelated edit must not un-overdue the bill');
	}

	public function testUpdateMaterialEditSpendsTheUndoSnapshot(): void {
		// The snapshot would restore the OLD amount/schedule over a deliberate
		// edit — a material change spends it.
		$bill = $this->makeBill();
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-06-15');

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		$this->service->update(1, 'user1', ['amount' => 20.0]);

		$this->assertNotNull($captured);
		$this->assertArrayHasKey('paid_undo_state', $captured);
		$this->assertNull($captured['paid_undo_state']);
	}

	public function testUpdateRenameKeepsTheUndoSnapshot(): void {
		$bill = $this->makeBill();
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-06-15');

		$captured = null;
		$this->mapper->method('updateFields')
			->willReturnCallback(function ($id, $userId, $updates) use (&$captured) {
				$captured = $updates;
			});

		// A rename plus an unchanged material value: neither spends the snapshot
		$this->service->update(1, 'user1', ['name' => 'Renamed', 'amount' => 15.99]);

		$this->assertNotNull($captured);
		$this->assertArrayNotHasKey('paid_undo_state', $captured);
	}

	public function testSkipPaymentSpendsTheUndoSnapshot(): void {
		// Skip moves next_due_date; a snapshot restored afterwards would bring
		// back a pre-skip date.
		$bill = $this->makeBill();
		$bill->setPaidUndoState($this->makePaidUndoSnapshot());
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->skipPayment(1, 'user1');

		$this->assertNull($result['bill']->getPaidUndoState());
	}

	public function testMarkUnpaidDeletesThePlaceholderOnlyWhileStillScheduled(): void {
		// The snapshot's placeholder may have materialised into a real ledger
		// row since the payment — the revert asks for the scheduled-only
		// delete and completes either way.
		$bill = $this->makeBill(['nextDueDate' => '2099-07-15', 'lastPaidDate' => '2099-06-15']);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot([
			'createdTransactionIds' => [55],
			'scheduledTransactionIds' => [56],
		]));
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$calls = [];
		$this->transactionService->method('deleteAsAccountOwner')
			->willReturnCallback(function (int $id, bool $onlyIfScheduled = false) use (&$calls) {
				$calls[] = [$id, $onlyIfScheduled];
				// The placeholder became 'cleared' — left alone
				return !$onlyIfScheduled;
			});

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertSame([[55, false], [56, true]], $calls);
		$this->assertSame('2099-06-15', $restored->getNextDueDate(), 'the revert still completes');
		$this->assertNull($restored->getPaidUndoState());
	}

	public function testMarkPaidWithLinkedTransactionSnapshotsTheLink(): void {
		// Link-existing / import-match payments create nothing — the snapshot
		// records the LINKED id so the revert can unlink it (never delete it).
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$this->linkable($this->makeImportedTx(['id' => 77]));

		$result = $this->service->markPaid(1, 'user1', null, false, 77);

		$decoded = json_decode($result['bill']->getPaidUndoState(), true);
		$this->assertSame(77, $decoded['linkedTransactionId']);
		$this->assertSame([], $decoded['createdTransactionIds']);
	}

	public function testMarkUnpaidUnlinksALinkedTransactionInsteadOfDeletingIt(): void {
		$bill = $this->makeBill(['nextDueDate' => '2099-07-15', 'lastPaidDate' => '2099-06-15']);
		$bill->setPaidUndoState($this->makePaidUndoSnapshot([
			'createdTransactionIds' => [],
			'linkedTransactionId' => 77,
		]));
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);

		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');
		$this->transactionService->expects($this->once())
			->method('unlinkBillAsAccountOwner')
			->with(77);

		$restored = $this->service->markUnpaid(1, 'user1');

		$this->assertSame('2099-06-15', $restored->getNextDueDate());
	}

	// ── a bank-sync hold that paid the bill is cancelled ────────────

	private function billPaidByHold(int $holdId, array $snapshotOverrides = [], array $billOverrides = []): Bill {
		$bill = $this->makeBill(array_merge([
			'userId' => 'owner1', 'nextDueDate' => '2099-07-15', 'lastPaidDate' => '2099-06-12',
		], $billOverrides));
		$bill->setPaidUndoState($this->makePaidUndoSnapshot(array_merge([
			'createdTransactionIds' => [],
			'scheduledTransactionIds' => [3390],
			'linkedTransactionId' => $holdId,
		], $snapshotOverrides)));
		$this->mapper->method('findByIds')->with([1])->willReturn([$bill]);
		$this->mapper->method('find')->willReturn($bill);
		$this->mapper->method('update')->willReturnArgument(0);
		return $bill;
	}

	public function testACancelledHoldUndoesTheBillPaymentItMade(): void {
		// The hold paid the bill (auto-match or the Mark Paid dialog), then
		// the bank dropped it. Deleting it left the bill paid and moved on,
		// with the unrecorded-payments card offering to record a payment the
		// bank never took.
		$bill = $this->billPaidByHold(3387);

		$this->transactionService->expects($this->once())
			->method('unlinkBillAsAccountOwner')
			->with(3387);

		$this->assertTrue($this->service->revertCancelledPayment(1, 3387));
		$this->assertSame('2099-06-15', $bill->getNextDueDate());
		$this->assertNull($bill->getLastPaidDate());
		$this->assertNull($bill->getPaidUndoState());
	}

	public function testACancelledHoldIsRevertedAsTheBillsOwner(): void {
		$this->billPaidByHold(3387);
		$this->mapper->expects($this->atLeastOnce())->method('find')->with(1, 'owner1');

		$this->service->revertCancelledPayment(1, 3387);
	}

	public function testACancelledHoldBringsBackThePreBookedRowItsLinkRemoved(): void {
		// Linking the hold removed the pre-booked row for the occurrence it
		// paid without recording that, so the revert alone left the restored
		// occurrence with no row at all.
		$bill = $this->billPaidByHold(3387);

		$this->transactionService->expects($this->once())
			->method('createFromBill')
			->with('owner1', $bill, null);

		$this->service->revertCancelledPayment(1, 3387);
	}

	public function testACancelledHoldBooksNoRowForABillThatDoesNotPreBook(): void {
		$this->billPaidByHold(3387, [], ['createTransaction' => false]);

		$this->transactionService->expects($this->never())->method('createFromBill');

		$this->service->revertCancelledPayment(1, 3387);
	}

	public function testACancelledHoldLeavesABillPaidSinceByAnotherPayment(): void {
		// The snapshot only covers the latest payment: a hold behind it can't
		// be undone without also undoing the later one.
		$this->billPaidByHold(88);

		$this->mapper->expects($this->never())->method('update');
		$this->transactionService->expects($this->never())->method('unlinkBillAsAccountOwner');

		$this->assertFalse($this->service->revertCancelledPayment(1, 3387));
	}

	public function testMarkUnpaidWithNonArrayTransactionIdsThrows(): void {
		// A corrupt blob must fail like a missing snapshot, not TypeError past
		// the controller's catches.
		$bill = $this->makeBill();
		$bill->setPaidUndoState(json_encode([
			'previousState' => ['nextDueDate' => '2099-06-15', 'isActive' => true],
			'createdTransactionIds' => 'bogus',
		]));
		$this->mapper->method('find')->willReturn($bill);
		$this->transactionService->expects($this->never())->method('deleteAsAccountOwner');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('This bill has no recorded payment to undo');

		$this->service->markUnpaid(1, 'user1');
	}

	public function testMarkPaidStillSucceedsWhenSnapshotPersistFails(): void {
		// The payment is committed by the first mapper update; a DB error on
		// the snapshot write may only cost the durable undo, never the payment.
		$bill = $this->makeBill();
		$this->mapper->method('find')->willReturn($bill);
		$calls = 0;
		$this->mapper->method('update')->willReturnCallback(function (Bill $b) use (&$calls) {
			if (++$calls === 2) {
				throw new \Exception('server has gone away');
			}
			return $b;
		});
		$this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2099-07-15');

		$result = $this->service->markPaid(1, 'user1');

		$this->assertSame('2099-07-15', $result['bill']->getNextDueDate());
		$this->assertSame(date('Y-m-d'), $result['bill']->getLastPaidDate());
		$this->assertNull($result['bill']->getPaidUndoState(), 'a snapshot that failed to persist must not be advertised');
	}
}
