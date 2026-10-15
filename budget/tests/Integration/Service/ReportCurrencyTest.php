<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\ReportService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The Spending, Income and Income & Expenses reports convert accounts in
 * other currencies to the base currency, as Cash Flow does, so their net
 * matches Cash Flow's for the same period. They summed amounts as stored.
 */
class ReportCurrencyTest extends IntegrationTestCase {
	private const START = '2025-06-01';
	private const END = '2025-06-30';

	protected function setUp(): void {
		parent::setUp();
		$this->service(SettingService::class)->set($this->userId, 'default_currency', 'GBP');
		// 1 EUR = 0.85 GBP
		$this->insertRow('budget_manual_rates', [
			'user_id' => $this->userId, 'currency' => 'GBP', 'rate_per_eur' => '0.8500000000', 'updated_at' => $this->now(),
		]);
		$pounds = $this->makeAccount(['name' => 'Current'])->getId();
		$euros = $this->makeAccount(['name' => 'Euro card', 'currency' => 'EUR'])->getId();
		$food = $this->makeCategory(['name' => 'Food']);
		$salary = $this->makeCategory(['name' => 'Salary', 'type' => 'income']);

		$this->makeTransaction($pounds, ['category_id' => $food, 'amount' => '100.00', 'vendor' => 'Tesco', 'date' => '2025-06-02']);
		$this->makeTransaction($pounds, ['category_id' => $food, 'amount' => '10.00', 'vendor' => 'Amazon', 'date' => '2025-06-03']);
		$this->makeTransaction($euros, ['category_id' => $food, 'amount' => '100.00', 'vendor' => 'Lidl', 'date' => '2025-06-04']);
		$this->makeTransaction($euros, ['category_id' => $food, 'amount' => '10.00', 'vendor' => 'Amazon', 'date' => '2025-06-05']);
		$this->makeTransaction($euros, ['category_id' => $salary, 'amount' => '1000.00', 'type' => 'credit', 'vendor' => 'Employer', 'date' => '2025-06-01']);
	}

	public function testIncomeAndExpensesNetMatchesCashFlow(): void {
		$reports = $this->service(ReportService::class);

		$incomeExpense = $reports->getIncomeExpenseReport($this->userId, self::START, self::END);
		$cashFlow = $reports->getCashFlowReport($this->userId, self::START, self::END);

		// 1000 euros is 850 pounds; 110 pounds and 110 euros (93.50) spent
		$this->assertEqualsWithDelta(850.0, $incomeExpense['totals']['income'], 0.001);
		$this->assertEqualsWithDelta(203.5, $incomeExpense['totals']['expenses'], 0.001);
		$this->assertEqualsWithDelta($cashFlow['totals']['income'], $incomeExpense['totals']['income'], 0.001);
		$this->assertEqualsWithDelta($cashFlow['totals']['expenses'], $incomeExpense['totals']['expenses'], 0.001);
		$this->assertEqualsWithDelta($cashFlow['totals']['net'], $incomeExpense['totals']['net'], 0.001);
	}

	public function testEveryGroupingOfSpendingIsInPounds(): void {
		$reports = $this->service(ReportService::class);
		$total = fn (string $groupBy): array => array_column(
			$reports->getSpendingReport($this->userId, self::START, self::END, null, $groupBy)['data'], 'total', 'name'
		);

		$this->assertEqualsWithDelta(203.5, $reports->getSpendingReport($this->userId, self::START, self::END)['totals']['amount'], 0.001);
		$this->assertEqualsWithDelta(203.5, $total('month')['Jun 2025'], 0.001);
		// A vendor paid in both currencies is one row
		$vendors = $total('vendor');
		$this->assertSame(['Tesco', 'Lidl', 'Amazon'], array_keys($vendors));
		$this->assertEqualsWithDelta(18.5, $vendors['Amazon'], 0.001);
		$this->assertEqualsWithDelta(85.0, $vendors['Lidl'], 0.001);
		$accounts = $total('account');
		$this->assertEqualsWithDelta(110.0, $accounts['Current'], 0.001);
		$this->assertEqualsWithDelta(93.5, $accounts['Euro card'], 0.001);

		$income = $reports->getIncomeReport($this->userId, self::START, self::END, null, 'source');
		$this->assertEqualsWithDelta(850.0, $income['data'][0]['total'], 0.001);
	}
}
