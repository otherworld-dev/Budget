<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\BudgetCarryoverService;
use OCA\Budget\Service\BudgetStatusService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\Report\ReportAggregator;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Every budget figure counts spending from accounts in other currencies in
 * the base currency, as Cash Flow and the dashboard summary count it. The
 * Budget page, the API's budget status, Ready to Assign, the alerts, the
 * dashboard's budget tiles and the envelope carry-over all summed amounts
 * as stored: 100 euros spent counted as 100 pounds against a budget in
 * pounds, and the API labelled the mixed sum GBP.
 */
class BudgetCurrencyTest extends IntegrationTestCase {
	private int $pounds;
	private int $euros;

	protected function setUp(): void {
		parent::setUp();
		$this->service(SettingService::class)->set($this->userId, 'default_currency', 'GBP');
		// 1 EUR = 0.85 GBP
		$this->insertRow('budget_manual_rates', [
			'user_id' => $this->userId, 'currency' => 'GBP', 'rate_per_eur' => '0.8500000000', 'updated_at' => $this->now(),
		]);
		$this->pounds = $this->makeAccount(['name' => 'Current'])->getId();
		$this->euros = $this->makeAccount(['name' => 'Euro card', 'currency' => 'EUR'])->getId();
	}

	public function testPastMonthsCountEurosInPounds(): void {
		$groceries = $this->makeCategory([
			'name' => 'Groceries', 'budget_amount' => '200', 'budget_period' => 'monthly',
			'budget_rollover' => true, 'rollover_start' => '2025-01',
		]);
		$salary = $this->makeCategory(['name' => 'Salary', 'type' => 'income']);
		// January: 100 pounds and 100 euros (85 pounds) of groceries
		$this->makeTransaction($this->pounds, ['category_id' => $groceries, 'amount' => '100.00', 'date' => '2025-01-10']);
		$this->makeTransaction($this->euros, ['category_id' => $groceries, 'amount' => '100.00', 'date' => '2025-01-11']);
		// February: paid 1000 euros (850 pounds)
		$this->makeTransaction($this->euros, ['category_id' => $salary, 'amount' => '1000.00', 'type' => 'credit', 'date' => '2025-02-01']);

		$categories = $this->service(CategoryService::class);
		$spent = array_column($categories->getAllCategorySpending($this->userId, '2025-01-01', '2025-01-31'), 'spent', 'categoryId');
		$this->assertEqualsWithDelta(185.0, $spent[$groceries], 0.001);

		$january = $this->line($this->service(BudgetStatusService::class)->forMonth($this->userId, '2025-01'), $groceries);
		$this->assertEqualsWithDelta(185.0, (float)$january['spent'], 0.001);
		$this->assertEqualsWithDelta(15.0, (float)$january['remaining'], 0.001);
		// The envelope carries what the page showed as left
		$this->assertEqualsWithDelta(15.0, $this->service(BudgetCarryoverService::class)->getCarryovers($this->userId, '2025-02')[$groceries] ?? null, 0.001);

		$this->assertEqualsWithDelta(850.0, $categories->getReadyToAssign($this->userId, '2025-02')['income'], 0.001);
	}

	public function testThisMonthsAlertsAndBudgetTileCountEurosInPounds(): void {
		$fuel = $this->makeCategory(['name' => 'Fuel', 'budget_amount' => '100', 'budget_period' => 'monthly']);
		$today = date('Y-m-d');
		$this->makeTransaction($this->pounds, ['category_id' => $fuel, 'amount' => '10.00', 'date' => $today]);
		$this->makeTransaction($this->euros, ['category_id' => $fuel, 'amount' => '10.00', 'date' => $today]);

		$status = array_column($this->service(BudgetAlertService::class)->getBudgetStatus($this->userId), 'spent', 'categoryId');
		$this->assertEqualsWithDelta(18.5, $status[$fuel], 0.001);

		$report = $this->service(ReportAggregator::class)->getBudgetReport($this->userId, date('Y-m-01'), date('Y-m-t'));
		$this->assertEqualsWithDelta(18.5, array_column($report['categories'], 'spent', 'categoryId')[$fuel], 0.001);
	}

	public function testAccountsInOneCurrencyAreSummedAsBefore(): void {
		$food = $this->makeCategory(['name' => 'Food']);
		$this->makeTransaction($this->pounds, ['category_id' => $food, 'amount' => '12.34', 'date' => '2025-03-03']);
		$this->makeTransaction($this->pounds, ['category_id' => $food, 'amount' => '1.00', 'type' => 'credit', 'date' => '2025-03-04']);

		$spent = $this->service(CategoryService::class)
			->getAllCategorySpending($this->userId, '2025-03-01', '2025-03-31', [$this->pounds]);

		$this->assertSame([[
			'categoryId' => $food, 'spent' => 11.34, 'name' => 'Food', 'color' => null, 'count' => 2,
		]], array_map(static fn (array $row) => ['categoryId' => $row['categoryId'], 'spent' => round($row['spent'], 2)] + $row, $spent));
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
