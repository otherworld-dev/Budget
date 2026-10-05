<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\BudgetStatusService;
use OCA\Budget\Service\Report\ReportAggregator;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The budget report (the dashboard's Budget Progress and Budget Breakdown
 * tiles, Budget remaining, and the budget export) through the real queries.
 *
 * It compared a weekly, quarterly or yearly budget's whole amount with the
 * spending over the range asked for, so on the current month a yearly Car
 * of 1,200 read 1,200 budgeted against one month's spending where the
 * Budget page's row reads 100. Each budget now counts its share of the
 * range, and for one month the report agrees with the page row for row.
 */
class BudgetReportPeriodsTest extends IntegrationTestCase {
	/** @var array<string, int> name => category id */
	private array $ids = [];

	protected function setUp(): void {
		parent::setUp();
		$this->service(SettingService::class)->set($this->userId, 'default_currency', 'GBP');
		$account = $this->makeAccount()->getId();

		foreach ([
			['Gym', '20', 'weekly'],
			['Car', '1200', 'yearly'],
			['Holiday', '900', 'quarterly'],
			['Groceries', '400', 'monthly'],
		] as [$name, $amount, $period]) {
			$this->ids[$name] = $this->makeCategory(['name' => $name, 'budget_amount' => $amount, 'budget_period' => $period]);
		}

		foreach ([
			['Car', '400.00', '2025-06-01'],
			['Groceries', '380.00', '2025-08-10'],
			['Groceries', '350.00', '2025-09-10'],
			['Holiday', '200.00', '2025-09-10'],
			['Gym', '10.00', '2025-10-02'],
			['Groceries', '170.00', '2025-10-03'],
			['Holiday', '300.00', '2025-10-01'],
		] as [$name, $amount, $date]) {
			$this->makeTransaction($account, ['category_id' => $this->ids[$name], 'amount' => $amount, 'date' => $date]);
		}
	}

	public function testOneMonthMatchesTheBudgetPageRowForRow(): void {
		$report = $this->rows($this->service(ReportAggregator::class)->getBudgetReport($this->userId, '2025-10-01', '2025-10-31'));
		$page = [];
		foreach ($this->service(BudgetStatusService::class)->forMonth($this->userId, '2025-10')['categories'] as $line) {
			$page[$line['categoryId']] = $line;
		}

		foreach ($this->ids as $name => $id) {
			foreach (['budgeted', 'spent', 'remaining'] as $figure) {
				$this->assertEqualsWithDelta(round((float)$page[$id][$figure], 2), $report[$id][$figure], 0.001, "$name $figure");
			}
		}
		$this->assertEqualsWithDelta(86.67, $report[$this->ids['Gym']]['budgeted'], 0.001);
		$this->assertEqualsWithDelta(100.0, $report[$this->ids['Car']]['budgeted'], 0.001);
		$this->assertEqualsWithDelta(300.0, $report[$this->ids['Holiday']]['budgeted'], 0.001);
		$this->assertEqualsWithDelta(400.0, $report[$this->ids['Groceries']]['budgeted'], 0.001);
	}

	public function testThreeMonthsCountThreeMonthsOfEveryBudget(): void {
		$result = $this->service(ReportAggregator::class)->getBudgetReport($this->userId, '2025-08-01', '2025-10-31');
		$report = $this->rows($result);

		// Thirteen weeks of 20, a quarter of 1,200, one quarter, three months of 400
		$this->assertEqualsWithDelta(260.0, $report[$this->ids['Gym']]['budgeted'], 0.001);
		$this->assertEqualsWithDelta(300.0, $report[$this->ids['Car']]['budgeted'], 0.001);
		$this->assertEqualsWithDelta(900.0, $report[$this->ids['Holiday']]['budgeted'], 0.001);
		$this->assertEqualsWithDelta(1200.0, $report[$this->ids['Groceries']]['budgeted'], 0.001);
		$this->assertEqualsWithDelta(300.0, $report[$this->ids['Groceries']]['remaining'], 0.001);
		$this->assertEqualsWithDelta(2660.0, $result['totals']['budgeted'], 0.001);
		$this->assertEqualsWithDelta(1410.0, $result['totals']['spent'], 0.001);
	}

	public function testABudgetPeriodWithAStartDayIsOneMonth(): void {
		$this->service(SettingService::class)->set($this->userId, 'budget_start_day', '25');

		$report = $this->rows($this->service(ReportAggregator::class)
			->getBudgetReport($this->userId, '2025-09-25', '2025-10-24', null, null, '2025-10'));

		$this->assertEqualsWithDelta(86.67, $report[$this->ids['Gym']]['budgeted'], 0.001);
		$this->assertEqualsWithDelta(100.0, $report[$this->ids['Car']]['budgeted'], 0.001);
	}

	/**
	 * @return array<int, array<string, mixed>> by category id
	 */
	private function rows(array $report): array {
		return array_column($report['categories'], null, 'categoryId');
	}
}
