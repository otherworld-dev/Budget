<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\BudgetCarryoverService;
use OCA\Budget\Service\Forecast\ForecastProjector;
use OCA\Budget\Service\Forecast\PatternAnalyzer;
use OCA\Budget\Service\Forecast\ScenarioBuilder;
use OCA\Budget\Service\Forecast\TrendCalculator;
use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\UserClock;
use PHPUnit\Framework\TestCase;

/**
 * The live forecast places what is scheduled in the month it falls in:
 * bills and recurring income on their dates, and rows dated after today.
 * The rest of the history is averaged without them, so nothing counts
 * twice. Spread as monthly floors, a one-off bill for a car in December, a
 * yearly bonus in May and a new subscription made no difference at all.
 *
 * Today is 30 October 2026, in calendar months unless a test says
 * otherwise: the rest of October comes first, then the months after it.
 */
class ForecastScheduledTest extends TestCase {
	/** @var Bill[] */
	private array $bills = [];
	/** @var RecurringIncome[] */
	private array $incomes = [];
	/** @var Transaction[] */
	private array $history = [];
	/** @var Transaction[] */
	private array $future = [];
	/** @var array<string, string[]> bill name => the dates its schedule falls on */
	private array $billDates = [];

	private function forecast(int $months = 3, int $monthStartDay = 1): array {
		$account = new Account();
		$account->setId(1);
		$account->setName('Current');
		$account->setBalance(10000.0);
		$account->setCurrency('EUR');
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([$account]);

		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('getNetChangeAfterDateForAccounts')->willReturn([]);
		$transactions->method('findAllByUserAndDateRange')
			->willReturnCallback(fn ($u, $from) => $from > '2026-10-30' ? $this->future : $this->history);

		$billMapper = $this->createMock(BillMapper::class);
		$billMapper->method('findActive')->willReturn($this->bills);
		$billService = $this->createMock(BillService::class);
		$billService->method('occurrencesBetween')->willReturnCallback(
			fn (Bill $bill, string $from, string $to) => array_values(array_filter(
				$this->billDates[$bill->getName()] ?? [],
				static fn (string $d) => $d >= $from && $d <= $to
			))
		);
		$incomeMapper = $this->createMock(RecurringIncomeMapper::class);
		$incomeMapper->method('findActive')->willReturn($this->incomes);

		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn('2026-10-30');
		$carryover = $this->createMock(BudgetCarryoverService::class);
		$carryover->method('reportMonthStartDay')->willReturn($monthStartDay);

		$service = new ForecastService(
			$accounts,
			$transactions,
			new PatternAnalyzer(new TrendCalculator(), $this->createMock(CategoryMapper::class), $this->createMock(TransactionSplitMapper::class)),
			new TrendCalculator(),
			$this->createMock(ScenarioBuilder::class),
			$this->createMock(ForecastProjector::class),
			null,
			$clock,
			null,
			$incomeMapper,
			$billMapper,
			$carryover,
			$billService,
			new FrequencyCalculator(),
		);
		return $service->getLiveForecast('alice', $months);
	}

	private function bill(string $name, float $amount, string $frequency, array $dates, ?string $nextDue = null): void {
		$bill = new Bill();
		$bill->setId(count($this->bills) + 1);
		$bill->setName($name);
		$bill->setAmount($amount);
		$bill->setFrequency($frequency);
		$bill->setAccountId(1);
		$bill->setIsTransfer(false);
		$bill->setExcludedFromForecast(false);
		$bill->setNextDueDate($nextDue ?? $dates[0]);
		$this->bills[] = $bill;
		$this->billDates[$name] = $dates;
	}

	private function income(float $amount, string $frequency, int $day, ?int $month = null, ?string $pattern = null): void {
		$income = new RecurringIncome();
		$income->setId(count($this->incomes) + 1);
		$income->setName('Income ' . (count($this->incomes) + 1));
		$income->setAmount($amount);
		$income->setFrequency($frequency);
		$income->setExpectedDay($day);
		$income->setExpectedMonth($month);
		$income->setAccountId(1);
		$income->setAutoDetectPattern($pattern);
		$income->setExcludedFromForecast(false);
		$this->incomes[] = $income;
	}

	private function row(string $date, float $amount, string $type, ?int $billId = null, string $description = ''): Transaction {
		$t = new Transaction();
		$t->setId(count($this->history) + count($this->future) + 1);
		$t->setAccountId(1);
		$t->setDate($date);
		$t->setAmount($amount);
		$t->setType($type);
		$t->setBillId($billId);
		$t->setDescription($description);
		$t->setExcludedFromForecast(false);
		return $t;
	}

	/** Three months of history: salary, a bill-paid rent and other spending each month. */
	private function steadyHistory(): void {
		foreach (['2026-07', '2026-08', '2026-09'] as $month) {
			$this->history[] = $this->row("{$month}-26", 3000.0, 'credit', null, 'SALARY ACME');
			$this->history[] = $this->row("{$month}-01", 900.0, 'debit', 1, 'Rent');
			$this->history[] = $this->row("{$month}-10", 1000.0, 'debit');
		}
	}

	public function testAOneOffBillLandsInItsMonth(): void {
		$this->bill('Car', 8500.0, 'one-time', ['2026-12-15']);

		$months = $this->forecast(3)['monthlyProjections'];

		$this->assertSame([0.0, 0.0, 8500.0, 0.0], array_column($months, 'scheduledExpenses'));
		$this->assertSame(1500.0, $months[2]['balance']);
	}

	public function testAYearlyBonusLandsInMayNotATwelfthEveryMonth(): void {
		$this->income(7000.0, 'yearly', 25, 5);

		$months = $this->forecast(8)['monthlyProjections'];

		$byMonth = array_combine(array_column($months, 'yearMonth'), array_column($months, 'scheduledIncome'));
		$this->assertSame(7000.0, $byMonth['2027-05']);
		$this->assertSame(7000.0, array_sum($byMonth));
	}

	public function testScheduledItemsAreNotCountedAgainInTheAverage(): void {
		$this->steadyHistory();
		$this->bill('Rent', 900.0, 'monthly', ['2026-11-01', '2026-12-01', '2027-01-01']);
		$this->income(3000.0, 'monthly', 26, null, 'SALARY');

		$months = $this->forecast(3)['monthlyProjections'];

		// Salary and rent on their dates, the other 1,000 a month averaged
		// (October's last day: 1,000 / 31)
		$this->assertSame([0.0, 3000.0, 3000.0, 3000.0], array_column($months, 'income'));
		$this->assertSame([32.26, 1900.0, 1900.0, 1900.0], array_column($months, 'expenses'));
	}

	public function testAPatternMatchesItsWordsInAnyOrder(): void {
		// The bank writes "SALARY ACME", the pattern says "acme  salary":
		// taken as one phrase it never matched, so the salary counted twice,
		// once in the average and once on its date
		$this->steadyHistory();
		$this->income(3000.0, 'monthly', 26, null, 'acme  salary');

		$months = $this->forecast(3)['monthlyProjections'];

		$this->assertSame([0.0, 3000.0, 3000.0, 3000.0], array_column($months, 'income'));
	}

	public function testAPatternWithAWordTheRowLacksDoesNotMatch(): void {
		$this->steadyHistory();
		$this->income(3000.0, 'monthly', 26, null, 'ACME BONUS');

		$months = $this->forecast(3)['monthlyProjections'];

		// Not this income's: the salary stays in the average as well
		$this->assertSame([6000.0, 6000.0, 6000.0], array_column(array_slice($months, 1), 'income'));
	}

	public function testANewBillCountsFromItsFirstDate(): void {
		$this->steadyHistory();
		$this->bill('Rent', 900.0, 'monthly', ['2026-11-01', '2026-12-01', '2027-01-01']);
		$this->bill('Streaming', 15.0, 'monthly', ['2026-12-05', '2027-01-05']);

		$months = $this->forecast(3)['monthlyProjections'];

		$this->assertSame([0.0, 900.0, 915.0, 915.0], array_column($months, 'scheduledExpenses'));
	}

	public function testRowsDatedAfterTodayLandInTheirMonth(): void {
		$this->future[] = $this->row('2026-12-03', 8500.0, 'debit');
		$this->future[] = $this->row('2027-01-20', 400.0, 'credit');

		$months = $this->forecast(3)['monthlyProjections'];

		$this->assertSame([0.0, 0.0, 8500.0, 0.0], array_column($months, 'scheduledExpenses'));
		$this->assertSame([0.0, 0.0, 0.0, 400.0], array_column($months, 'scheduledIncome'));
	}

	public function testWhatIsStillDueThisMonthCountsInWhatIsLeftOfIt(): void {
		// Due tomorrow, 31 October: not in today's balance yet
		$this->bill('Insurance', 120.0, 'monthly', ['2026-10-31', '2026-11-30', '2026-12-31']);

		$months = $this->forecast(2)['monthlyProjections'];

		$this->assertSame(['2026-10', '2026-11', '2026-12'], array_column($months, 'yearMonth'));
		$this->assertSame([true, false, false], array_column($months, 'partial'));
		$this->assertSame([120.0, 120.0, 120.0], array_column($months, 'scheduledExpenses'));
	}

	public function testTheRestOfTheRunningMonthIsItsOwnMonth(): void {
		$this->steadyHistory();
		$this->bill('Rent', 900.0, 'monthly', ['2026-11-01', '2026-12-01']);
		$this->income(3000.0, 'monthly', 26, null, 'SALARY');

		$months = $this->forecast(2)['monthlyProjections'];

		// 30 October: 1 of October's 31 days to come, so a 31st of the
		// usual 1,000 spending, and November no more than a month
		$this->assertEqualsWithDelta(1000.0 / 31, $months[0]['expenses'], 0.01);
		$this->assertSame(1900.0, $months[1]['expenses']);
	}

	public function testWithABudgetStartDayTheRunningMonthKeepsWhatFallsInIt(): void {
		// Budget months from the 27th: "November" runs from 27 October to
		// 26 November, with 27 of its 31 days still to come. Its pay on 26
		// November and its bill belong to it, not to December: folded into
		// December, the rest of November made December look like two months.
		$this->steadyHistory();
		$this->bill('Car', 8500.0, 'one-time', ['2026-11-20']);
		$this->income(7000.0, 'yearly', 26, 11);
		$this->income(3000.0, 'monthly', 26, null, 'SALARY');

		$months = $this->forecast(2, 27)['monthlyProjections'];

		$this->assertSame(['2026-11', '2026-12', '2027-01'], array_column($months, 'yearMonth'));
		$this->assertSame([true, false, false], array_column($months, 'partial'));
		$this->assertSame([8500.0, 0.0, 0.0], array_column($months, 'scheduledExpenses'));
		$this->assertSame([10000.0, 3000.0, 3000.0], array_column($months, 'scheduledIncome'));
	}

	public function testAPaymentMadeAheadOfItsDateIsNotCountedAgain(): void {
		// November's payment is made: the next one due is December's
		$this->bill('Rent', 900.0, 'monthly', ['2026-11-01', '2026-12-01', '2027-01-01'], '2026-12-01');

		$months = $this->forecast(3)['monthlyProjections'];

		$this->assertSame([0.0, 0.0, 900.0, 900.0], array_column($months, 'scheduledExpenses'));
	}
}
