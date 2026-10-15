<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\BudgetCarryoverService;
use OCA\Budget\Service\BudgetPeriod;
use OCA\Budget\Service\BudgetStatusService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\RecurringBudgetService;
use OCA\Budget\Service\SharedBudgetService;
use PHPUnit\Framework\TestCase;

/**
 * The figures must equal the web Budget page's for the same user and month
 * (#767). Each expectation is the page's own arithmetic, done by hand.
 */
class BudgetStatusServiceTest extends TestCase {
	private array $tree = [];
	private array $sharedCategories = [];
	private array $budgets = [];
	private array $sharedBudgets = [];
	private array $recurring = [];
	/** @var array<string, array> "start|end|type" => getAllCategorySpending rows */
	private array $spending = [];
	private int $startDay = 1;
	private BudgetStatusService $service;

	protected function setUp(): void {
		$categories = $this->createMock(CategoryService::class);
		$categories->method('getCategoryTree')->willReturnCallback(fn () => $this->tree);
		$categories->method('resolveEffectiveBudgets')->willReturnCallback(fn () => $this->budgets);
		$categories->method('getAllCategorySpending')->willReturnCallback(
			fn (string $u, string $start, string $end, ?array $ids, string $type) => $this->spending["$start|$end|$type"] ?? []
		);
		$shared = $this->createMock(SharedBudgetService::class);
		$shared->method('effectiveBudgets')->willReturnCallback(fn () => $this->sharedBudgets);
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getSharedCategories')->willReturnCallback(fn () => $this->sharedCategories);
		$shares->method('getVisibleAccountIds')->willReturn([1, 2]);
		$carryover = $this->createMock(BudgetCarryoverService::class);
		$carryover->method('currentBudgetMonth')->willReturn('2026-09');
		$carryover->method('budgetStartDay')->willReturnCallback(fn () => $this->startDay);
		$carryover->method('budgetMonthRange')->willReturnCallback(fn (string $u, string $m) => BudgetPeriod::range($m, $this->startDay));
		$recurring = $this->createMock(RecurringBudgetService::class);
		$recurring->method('getMonthlyBudgetsByCategory')->willReturnCallback(fn () => $this->recurring);
		$recurring->method('convertMonthlyToPeriod')->willReturnCallback(fn (float $m, string $p) => match ($p) {
			'weekly' => $m * 12 / 52, 'quarterly' => $m * 3, 'yearly' => $m * 12, default => $m,
		});

		$this->service = new BudgetStatusService($categories, $shared, $shares, $carryover, $recurring);
	}

	private static function cat(int $id, string $name, array $extra = []): array {
		return $extra + [
			'id' => $id, 'name' => $name, 'type' => 'expense', 'parentId' => null,
			'budgetAmount' => null, 'budgetPeriod' => 'monthly',
			'excludedFromReports' => false, 'excludedFromBudget' => false, 'children' => [],
		];
	}

	private static function budget(float $available, string $period = 'monthly', float $carried = 0.0, bool $rollover = false): array {
		return ['amount' => $available - $carried, 'period' => $period, 'rollover' => $rollover, 'carried' => $carried, 'available' => $available];
	}

	private static function spent(int $id, float $amount): array {
		return ['categoryId' => $id, 'spent' => $amount, 'name' => '', 'color' => null, 'count' => 1];
	}

	private static function money(string $amount): string {
		return number_format((float)$amount, 2, '.', '');
	}

	private function line(array $status, int $id): array {
		foreach ($status['categories'] as $line) {
			if ($line['categoryId'] === $id) {
				return $line;
			}
		}
		$this->fail("No line for category $id");
	}

	private function assertFigures(array $expected, array $line): void {
		foreach ($expected as $key => $value) {
			$this->assertSame($value, self::money($line[$key]), $key . ' of ' . (isset($line['categoryId']) ? "category {$line['categoryId']}" : 'the period so far'));
		}
	}

	public function testAMonthlyCategoryMatchesItsPageRow(): void {
		$this->tree = [self::cat(12, 'Groceries')];
		$this->budgets = [12 => self::budget(400)];
		$this->spending['2026-09-01|2026-09-30|debit'] = [self::spent(12, 431.2)];

		$status = $this->service->forMonth('user1', '2026-09');

		$this->assertSame(['2026-09', '2026-09-01', '2026-09-30'], [$status['month'], $status['startDate'], $status['endDate']]);
		$line = $this->line($status, 12);
		$this->assertFigures(['budgeted' => '400.00', 'carried' => '0.00', 'spent' => '431.20', 'remaining' => '-31.20'], $line);
		$this->assertSame(['Groceries', null, 'expense', 'monthly', false], [$line['name'], $line['parentId'], $line['type'], $line['period'], $line['shared']]);
		$this->assertSame(['400.00', '431.20', '-31.20'], array_map(self::money(...), array_values($status['totals'])));
	}

	/**
	 * A weekly 100 and a yearly 27.50 are 435.625 a month. Each turned
	 * monthly and cut at six places they summed to 435.624999, so the total
	 * came out a penny below the page's (4031.12 against 4031.13).
	 */
	public function testMixedPeriodTotalsAreExactNotAPennyShort(): void {
		$this->tree = [self::cat(1, 'Rent'), self::cat(2, 'Groceries'), self::cat(3, 'Insurance')];
		$this->budgets = [1 => self::budget(3595.5), 2 => self::budget(100, 'weekly'), 3 => self::budget(27.5, 'yearly')];
		$this->spending['2026-10-01|2026-10-31|debit'] = [self::spent(1, 1450)];

		$status = $this->service->forMonth('user1', '2026-10');

		$this->assertSame(['4031.13', '1450.00', '2581.13'], array_map(self::money(...), array_values($status['totals'])));
	}

	public function testNoMonthMeansTheBudgetMonthRunningToday(): void {
		$this->assertSame('2026-09', $this->service->forMonth('user1')['month']);
	}

	public function testACustomStartDayMovesTheMonthsDates(): void {
		$this->startDay = 25;
		$this->tree = [self::cat(12, 'Groceries')];
		$this->budgets = [12 => self::budget(400)];
		$this->spending['2026-08-25|2026-09-24|debit'] = [self::spent(12, 100)];

		$status = $this->service->forMonth('user1', '2026-09');

		$this->assertSame(['2026-08-25', '2026-09-24'], [$status['startDate'], $status['endDate']]);
		$this->assertFigures(['spent' => '100.00', 'remaining' => '300.00'], $this->line($status, 12));
	}

	/**
	 * Every row counts the month's spending against its budget's share of
	 * the month, whatever the period: a weekly budget was measured over the
	 * week holding the 15th and a yearly one over the whole year, so the
	 * Spent card added a year's spending to one month's budgets.
	 */
	public function testEveryPeriodCountsTheMonthsSpendingAgainstItsShareOfTheMonth(): void {
		$this->tree = [
			self::cat(20, 'Lunch', ['budgetPeriod' => 'weekly']),
			self::cat(21, 'Car', ['budgetPeriod' => 'quarterly']),
			self::cat(22, 'Holidays', ['budgetPeriod' => 'yearly']),
			self::cat(23, 'Fuel'),
		];
		$this->budgets = [
			20 => self::budget(52, 'weekly'),
			21 => self::budget(300, 'quarterly'),
			22 => self::budget(1200, 'yearly'),
			23 => self::budget(100),
		];
		$this->spending['2026-09-01|2026-09-30|debit'] = [self::spent(20, 45), self::spent(21, 20), self::spent(23, 40)];
		// The week holding the 15th, the quarter and the year count no more
		$this->spending['2026-09-14|2026-09-20|debit'] = [self::spent(20, 30)];
		$this->spending['2026-07-01|2026-09-30|debit'] = [self::spent(21, 250)];
		$this->spending['2026-01-01|2026-12-31|debit'] = [self::spent(22, 900)];

		$status = $this->service->forMonth('user1', '2026-09');

		// 52 a week is 52 * 52 / 12 = 225.33 a month
		$this->assertFigures(['budgeted' => '225.33', 'spent' => '45.00', 'remaining' => '180.33'], $this->line($status, 20));
		$this->assertSame('weekly', $this->line($status, 20)['period']);
		$this->assertFigures(['budgeted' => '100.00', 'spent' => '20.00', 'remaining' => '80.00'], $this->line($status, 21));
		$this->assertFigures(['budgeted' => '100.00', 'spent' => '0.00', 'remaining' => '100.00'], $this->line($status, 22));
		$this->assertFigures(['budgeted' => '100.00', 'spent' => '40.00'], $this->line($status, 23));
		// The cards add the rows up: 225.33 + 100 + 100 + 100 and 45 + 20 + 40
		$this->assertSame(['525.33', '105.00', '420.33'], array_map(self::money(...), array_values($status['totals'])));
	}

	/**
	 * R7: Gym, weekly 20, with 10 spent on 2 October. Measured over the week
	 * of the 15th it showed nothing spent while the alerts showed the 10.
	 */
	public function testAWeeklyBudgetIsTheMonthsSpendingAgainstFiftyTwoWeeksOverTwelve(): void {
		$this->tree = [self::cat(20, 'Gym', ['budgetPeriod' => 'weekly'])];
		$this->budgets = [20 => self::budget(20, 'weekly')];
		$this->spending['2026-10-01|2026-10-31|debit'] = [self::spent(20, 10)];

		$status = $this->service->forMonth('user1', '2026-10');

		$line = $this->line($status, 20);
		$this->assertFigures(['budgeted' => '86.67', 'spent' => '10.00', 'remaining' => '76.67'], $line);
		$this->assertNull($line['periodToDate']);
		$this->assertSame(['86.67', '10.00', '76.67'], array_map(self::money(...), array_values($status['totals'])));
	}

	/**
	 * R7: Car, yearly 1200, with 400 spent in June. October's Spent card
	 * counted June's 400 against October's 100; now the month counts only
	 * its own spending, and the row says how much of the year has gone.
	 */
	public function testAYearlyBudgetCountsThisMonthAndShowsTheYearSoFar(): void {
		$this->tree = [self::cat(22, 'Car', ['budgetPeriod' => 'yearly']), self::cat(23, 'Groceries')];
		$this->budgets = [22 => self::budget(1200, 'yearly'), 23 => self::budget(400)];
		$this->spending['2026-10-01|2026-10-31|debit'] = [self::spent(23, 50)];
		$this->spending['2026-01-01|2026-10-31|debit'] = [self::spent(22, 400), self::spent(23, 450)];

		$status = $this->service->forMonth('user1', '2026-10');

		$car = $this->line($status, 22);
		$this->assertFigures(['budgeted' => '100.00', 'spent' => '0.00', 'remaining' => '100.00'], $car);
		$this->assertSame(['2026-01-01', '2026-10-31'], [$car['periodToDate']['startDate'], $car['periodToDate']['endDate']]);
		$this->assertFigures(['budgeted' => '1200.00', 'spent' => '400.00'], $car['periodToDate']);
		$this->assertNull($this->line($status, 23)['periodToDate']);
		$this->assertSame(['500.00', '50.00', '450.00'], array_map(self::money(...), array_values($status['totals'])));
	}

	public function testAQuarterlyBudgetShowsTheQuarterSoFar(): void {
		$this->tree = [self::cat(21, 'Holiday', ['budgetPeriod' => 'quarterly'])];
		$this->budgets = [21 => self::budget(900, 'quarterly')];
		$this->spending['2026-11-01|2026-11-30|debit'] = [self::spent(21, 50)];
		// October and November so far of the October to December quarter
		$this->spending['2026-10-01|2026-11-30|debit'] = [self::spent(21, 350)];

		$line = $this->line($this->service->forMonth('user1', '2026-11'), 21);

		$this->assertFigures(['budgeted' => '300.00', 'spent' => '50.00', 'remaining' => '250.00'], $line);
		$this->assertSame(['2026-10-01', '2026-11-30'], [$line['periodToDate']['startDate'], $line['periodToDate']['endDate']]);
		$this->assertFigures(['budgeted' => '900.00', 'spent' => '350.00'], $line['periodToDate']);
	}

	public function testTheYearSoFarIsTheYearsBudgetMonthsWithAStartDay(): void {
		// With the 25th, October is 25 September to 24 October and the
		// year's first budget month, January, began on 25 December
		$this->startDay = 25;
		$this->tree = [self::cat(22, 'Car', ['budgetPeriod' => 'yearly'])];
		$this->budgets = [22 => self::budget(1200, 'yearly')];
		$this->spending['2026-09-25|2026-10-24|debit'] = [self::spent(22, 30)];
		$this->spending['2025-12-25|2026-10-24|debit'] = [self::spent(22, 430)];

		$line = $this->line($this->service->forMonth('user1', '2026-10'), 22);

		$this->assertFigures(['spent' => '30.00', 'remaining' => '70.00'], $line);
		$this->assertSame(['2025-12-25', '2026-10-24'], [$line['periodToDate']['startDate'], $line['periodToDate']['endDate']]);
		$this->assertFigures(['spent' => '430.00'], $line['periodToDate']);
	}

	/**
	 * R7: a yearly Car of 1200 over a monthly Fuel of 100 showed "Total
	 * 1,300", a year's budget plus a month's. The branch is now 200 a
	 * month, and its year so far 2,400 with the spending of both.
	 */
	public function testAYearlyParentAddsItsChildrenAsAShareOfTheMonth(): void {
		$this->tree = [self::cat(22, 'Car', ['budgetPeriod' => 'yearly', 'children' => [
			self::cat(24, 'Fuel', ['parentId' => 22]),
		]])];
		$this->budgets = [22 => self::budget(1200, 'yearly'), 24 => self::budget(100)];
		$this->spending['2026-10-01|2026-10-31|debit'] = [self::spent(24, 60)];
		$this->spending['2026-01-01|2026-10-31|debit'] = [self::spent(22, 400), self::spent(24, 100)];

		$status = $this->service->forMonth('user1', '2026-10');

		$car = $this->line($status, 22);
		$this->assertFigures(['budgeted' => '200.00', 'spent' => '60.00', 'remaining' => '140.00'], $car);
		$this->assertFigures(['budgeted' => '2400.00', 'spent' => '500.00'], $car['periodToDate']);
		$this->assertFigures(['budgeted' => '100.00', 'spent' => '60.00'], $this->line($status, 24));
		$this->assertSame(['200.00', '60.00', '140.00'], array_map(self::money(...), array_values($status['totals'])));
	}

	public function testAPastMonthsYearSoFarEndsWithThatMonth(): void {
		$this->tree = [self::cat(22, 'Car', ['budgetPeriod' => 'yearly'])];
		$this->budgets = [22 => self::budget(1200, 'yearly')];
		$this->spending['2026-06-01|2026-06-30|debit'] = [self::spent(22, 400)];
		$this->spending['2026-01-01|2026-06-30|debit'] = [self::spent(22, 400)];

		$line = $this->line($this->service->forMonth('user1', '2026-06'), 22);

		// June itself is over its share of the year; the year is not
		$this->assertFigures(['budgeted' => '100.00', 'spent' => '400.00', 'remaining' => '-300.00'], $line);
		$this->assertFigures(['budgeted' => '1200.00', 'spent' => '400.00'], $line['periodToDate']);
	}

	public function testAnEnvelopesCarryIsPartOfItsBudget(): void {
		$this->tree = [self::cat(30, 'Clothes'), self::cat(31, 'Gifts')];
		$this->budgets = [
			30 => self::budget(120, 'monthly', 20, true),
			// Overspent last month: nothing budgeted this month, still owes 15
			31 => ['amount' => 0, 'period' => 'monthly', 'rollover' => true, 'carried' => -15.0, 'available' => -15.0],
		];

		$status = $this->service->forMonth('user1', '2026-09');

		$this->assertFigures(['budgeted' => '120.00', 'carried' => '20.00'], $this->line($status, 30));
		$this->assertFigures(['budgeted' => '-15.00', 'carried' => '-15.00', 'remaining' => '-15.00'], $this->line($status, 31));
		// Only a positive budget counts towards the total
		$this->assertSame('120.00', self::money($status['totals']['budgeted']));
	}

	public function testAParentShowsItsBranchTheWayThePageAddsItUp(): void {
		$this->tree = [self::cat(40, 'Home', ['children' => [
			self::cat(41, 'Bills', ['parentId' => 40, 'children' => [
				self::cat(42, 'Water', ['parentId' => 41]),
			]]),
		]])];
		$this->budgets = [40 => self::budget(50), 41 => self::budget(100), 42 => self::budget(30)];
		$this->spending['2026-09-01|2026-09-30|debit'] = [self::spent(40, 5), self::spent(41, 10), self::spent(42, 20)];

		$status = $this->service->forMonth('user1', '2026-09');

		// Spent adds every level; the budget only adds direct children, so
		// Water's 30 stops at Bills and never reaches Home (page rule)
		$this->assertFigures(['budgeted' => '150.00', 'spent' => '35.00', 'remaining' => '115.00'], $this->line($status, 40));
		$this->assertFigures(['budgeted' => '130.00', 'spent' => '30.00'], $this->line($status, 41));
		$this->assertFigures(['budgeted' => '30.00', 'spent' => '20.00'], $this->line($status, 42));
		$this->assertSame([null, 40, 41], array_column($status['categories'], 'parentId'));
		// Totals use each row's own figures: 50+100+30 and 5+10+20
		$this->assertSame(['180.00', '35.00', '145.00'], array_map(self::money(...), array_values($status['totals'])));
	}

	public function testTotalsCountUnbudgetedExpenseSpendingButNotIncome(): void {
		$this->tree = [self::cat(50, 'Food'), self::cat(51, 'Misc'), self::cat(60, 'Salary', ['type' => 'income'])];
		$this->budgets = [50 => self::budget(100), 51 => self::budget(0), 60 => self::budget(2000)];
		$this->spending['2026-09-01|2026-09-30|debit'] = [self::spent(50, 60), self::spent(51, 25)];
		$this->spending['2026-09-01|2026-09-30|credit'] = [self::spent(60, 1800)];

		$status = $this->service->forMonth('user1', '2026-09');

		// No budget, no line; income is listed but kept out of the totals
		$this->assertSame([50, 60], array_column($status['categories'], 'categoryId'));
		$this->assertFigures(['spent' => '1800.00', 'remaining' => '200.00'], $this->line($status, 60));
		$this->assertSame(['100.00', '85.00', '15.00'], array_map(self::money(...), array_values($status['totals'])));
	}

	public function testBranchesOutOfReportsOrBudgetingDropOut(): void {
		$this->tree = [
			self::cat(70, 'Transfers', ['excludedFromReports' => true, 'children' => [self::cat(71, 'Savings', ['parentId' => 70])]]),
			self::cat(72, 'Tax', ['excludedFromBudget' => true]),
			self::cat(73, 'Food'),
		];
		$this->budgets = [70 => self::budget(10), 71 => self::budget(10), 73 => self::budget(10)];
		$this->spending['2026-09-01|2026-09-30|debit'] = [self::spent(71, 500), self::spent(72, 300), self::spent(73, 5)];

		$status = $this->service->forMonth('user1', '2026-09');

		$this->assertSame([73], array_column($status['categories'], 'categoryId'));
		$this->assertSame('5.00', self::money($status['totals']['spent']));
	}

	public function testASharedCategoryTakesThePlaceOfAnOwnOneWithTheSameName(): void {
		$this->tree = [self::cat(80, 'Groceries', ['children' => [self::cat(81, 'Snacks', ['parentId' => 80])]])];
		$this->budgets = [80 => self::budget(400), 81 => self::budget(20)];
		$this->sharedCategories = [
			self::cat(90, 'Groceries', ['_shared' => true, 'userId' => 'owner1']),
			self::cat(91, 'Snacks', ['parentId' => 90, '_shared' => true, 'userId' => 'owner1']),
			self::cat(92, 'Treats', ['parentId' => 90, '_shared' => true, 'userId' => 'owner1']),
			// Its parent isn't shared, so it sits at the top of the tree
			self::cat(93, 'Pets', ['parentId' => 99, '_shared' => true, 'userId' => 'owner1']),
		];
		$this->sharedBudgets = [90 => self::budget(500), 91 => self::budget(30), 92 => self::budget(10), 93 => self::budget(25)];

		$status = $this->service->forMonth('user1', '2026-09');

		$this->assertSame([90, 91, 92, 93], array_column($status['categories'], 'categoryId'));
		$this->assertTrue($this->line($status, 90)['shared']);
		$this->assertFigures(['budgeted' => '540.00'], $this->line($status, 90));
		$this->assertSame(90, $this->line($status, 92)['parentId']);
		$this->assertNull($this->line($status, 93)['parentId']);
	}

	public function testACategoryWithNoEntryFallsBackToItsOwnAmount(): void {
		// A shared child whose owner keeps its parent out of budgeting has no entry
		$this->sharedCategories = [self::cat(95, 'Vet', ['_shared' => true, 'userId' => 'owner1', 'budgetAmount' => 25.0])];

		$this->assertFigures(['budgeted' => '25.00'], $this->line($this->service->forMonth('user1', '2026-09'), 95));
	}

	public function testAnEmptyBudgetIsAllZeros(): void {
		$status = $this->service->forMonth('user1', '2026-09');

		$this->assertSame([], $status['categories']);
		$this->assertSame(['0.00', '0.00', '0.00'], array_map(self::money(...), array_values($status['totals'])));
	}
}
