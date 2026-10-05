<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\BudgetStatusService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Weekly, quarterly and yearly budgets on the monthly Budget page, through
 * the real spending queries (R7's s6).
 *
 * The page measured a weekly budget over the week holding the month's 15th
 * and a yearly one over the whole year: a gym budget of 20 a week showed
 * nothing spent on 2 October although 10 went that week, and a car budget
 * of 1,200 put June's 400 in October's Spent card against October's 100.
 * Every row now counts the month against its share of the month, and a
 * quarterly or yearly row also has its period so far.
 */
class BudgetPeriodsMonthTest extends IntegrationTestCase {
	private int $gym;
	private int $car;
	private int $fuel;
	private int $groceries;
	private int $holiday;

	protected function setUp(): void {
		parent::setUp();
		$this->service(SettingService::class)->set($this->userId, 'default_currency', 'GBP');
		$account = $this->makeAccount()->getId();

		$this->gym = $this->makeCategory(['name' => 'Gym', 'budget_amount' => '20', 'budget_period' => 'weekly']);
		$this->car = $this->makeCategory(['name' => 'Car', 'budget_amount' => '1200', 'budget_period' => 'yearly']);
		$this->fuel = $this->makeCategory(['name' => 'Fuel', 'parent_id' => $this->car, 'budget_amount' => '100', 'budget_period' => 'monthly']);
		$this->groceries = $this->makeCategory(['name' => 'Groceries', 'budget_amount' => '400', 'budget_period' => 'monthly']);
		$this->holiday = $this->makeCategory(['name' => 'Holiday', 'budget_amount' => '900', 'budget_period' => 'quarterly']);

		foreach ([
			[$this->car, '400.00', '2025-06-01'],
			[$this->fuel, '40.00', '2025-09-10'],
			[$this->holiday, '200.00', '2025-09-10'],
			[$this->gym, '10.00', '2025-10-02'],
			[$this->fuel, '60.00', '2025-10-03'],
			[$this->groceries, '50.00', '2025-10-03'],
			[$this->holiday, '300.00', '2025-10-01'],
			// Next year's: no part of 2025's year so far
			[$this->car, '75.00', '2026-01-05'],
		] as [$category, $amount, $date]) {
			$this->makeTransaction($account, ['category_id' => $category, 'amount' => $amount, 'date' => $date]);
		}
	}

	public function testEveryRowCountsTheMonthAgainstItsShareOfTheMonth(): void {
		$status = $this->service(BudgetStatusService::class)->forMonth($this->userId, '2025-10');

		$this->assertFigures(['budgeted' => 86.67, 'spent' => 10.0, 'remaining' => 76.67], $this->line($status, $this->gym));
		$this->assertNull($this->line($status, $this->gym)['periodToDate']);
		// Car's 100 a month and Fuel's 100; October's spending is Fuel's 60
		$this->assertFigures(['budgeted' => 200.0, 'spent' => 60.0, 'remaining' => 140.0], $this->line($status, $this->car));
		$this->assertFigures(['budgeted' => 100.0, 'spent' => 60.0], $this->line($status, $this->fuel));
		$this->assertFigures(['budgeted' => 400.0, 'spent' => 50.0], $this->line($status, $this->groceries));
		$this->assertFigures(['budgeted' => 300.0, 'spent' => 300.0, 'remaining' => 0.0], $this->line($status, $this->holiday));

		// 86.67 + 100 + 100 + 400 + 300 budgeted, 10 + 60 + 50 + 300 spent:
		// June's 400 and September's 200 stay in their own months
		$this->assertFigures(['budgeted' => 986.67, 'spent' => 420.0, 'remaining' => 566.67], $status['totals']);
	}

	public function testAQuarterlyOrYearlyRowHasItsPeriodSoFar(): void {
		$status = $this->service(BudgetStatusService::class)->forMonth($this->userId, '2025-10');

		$car = $this->line($status, $this->car)['periodToDate'];
		$this->assertSame(['2025-01-01', '2025-10-31'], [$car['startDate'], $car['endDate']]);
		// 1,200 + 12 x Fuel's 100, against June's 400 and Fuel's 40 + 60
		$this->assertFigures(['budgeted' => 2400.0, 'spent' => 500.0], $car);

		$holiday = $this->line($status, $this->holiday)['periodToDate'];
		$this->assertSame(['2025-10-01', '2025-10-31'], [$holiday['startDate'], $holiday['endDate']]);
		$this->assertFigures(['budgeted' => 900.0, 'spent' => 300.0], $holiday);
	}

	public function testThePeriodSoFarFollowsTheBudgetStartDay(): void {
		// With the 25th, October 2025 is 25 September to 24 October and the
		// year began on 25 December 2024: Fuel's 40 on 10 September is in
		// September's budget month, and so in the year so far
		$this->service(SettingService::class)->set($this->userId, 'budget_start_day', '25');

		$status = $this->service(BudgetStatusService::class)->forMonth($this->userId, '2025-10');

		$car = $this->line($status, $this->car);
		$this->assertFigures(['spent' => 60.0], $car);
		$this->assertSame(['2024-12-25', '2025-10-24'], [$car['periodToDate']['startDate'], $car['periodToDate']['endDate']]);
		$this->assertFigures(['spent' => 500.0], $car['periodToDate']);
	}

	public function testJuneCarriesTheServiceAndTheYearSoFarEndsThere(): void {
		$status = $this->service(BudgetStatusService::class)->forMonth($this->userId, '2025-06');

		$car = $this->line($status, $this->car);
		$this->assertFigures(['budgeted' => 200.0, 'spent' => 400.0, 'remaining' => -200.0], $car);
		$this->assertSame('2025-06-30', $car['periodToDate']['endDate']);
		$this->assertFigures(['budgeted' => 2400.0, 'spent' => 400.0], $car['periodToDate']);
	}

	/**
	 * @param array<string, float> $expected
	 * @param array<string, mixed> $figures
	 */
	private function assertFigures(array $expected, array $figures): void {
		foreach ($expected as $key => $value) {
			$this->assertEqualsWithDelta($value, round((float)$figures[$key], 2), 0.001, $key);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function line(array $status, int $categoryId): array {
		foreach ($status['categories'] as $line) {
			if ($line['categoryId'] === $categoryId) {
				return $line;
			}
		}
		$this->fail('No budget line for category ' . $categoryId);
	}
}
