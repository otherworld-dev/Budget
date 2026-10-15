<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\Forecast\ScenarioBuilder;
use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The forecast adds up accounts in more than one currency in the base
 * currency, as the dashboard summary does. It summed balances and history
 * as stored and labelled the sum with the largest account's currency, so
 * 100 euros owed counted as 100 pounds and half a bitcoin as 50p.
 */
class ForecastCurrencyTest extends IntegrationTestCase {
	private int $pounds;
	private int $euros;
	private int $food;

	protected function setUp(): void {
		parent::setUp();
		$this->service(SettingService::class)->set($this->userId, 'default_currency', 'GBP');
		// 1 EUR = 0.85 GBP, the user's own standing rate
		$this->insertRow('budget_manual_rates', [
			'user_id' => $this->userId, 'currency' => 'GBP', 'rate_per_eur' => '0.8500000000', 'updated_at' => $this->now(),
		]);

		$this->food = $this->makeCategory(['name' => 'Food']);
		$this->pounds = $this->makeAccount(['name' => 'Current', 'openingBalance' => 2850.0, 'balance' => 2800.0])->getId();
		$this->euros = $this->makeAccount(['name' => 'Euro card', 'currency' => 'EUR', 'balance' => -100.0])->getId();
	}

	/**
	 * 50 pounds and 100 euros spent on $date.
	 */
	private function spendOn(string $date): void {
		$this->makeTransaction($this->pounds, ['amount' => '50.00', 'date' => $date, 'category_id' => $this->food]);
		$this->makeTransaction($this->euros, ['amount' => '100.00', 'date' => $date, 'category_id' => $this->food]);
	}

	public function testTheLiveForecastIsInTheBaseCurrency(): void {
		// The live forecast learns from complete months
		$this->spendOn(date('Y-m-d', strtotime('first day of last month')));

		$forecast = $this->service(ForecastService::class)->getLiveForecast($this->userId, 3);

		// 2800 + (-100 x 0.85)
		$this->assertEqualsWithDelta(2715.0, $forecast['currentBalance'], 0.001);
		$this->assertSame('GBP', $forecast['currency']);
		// 50 + 100 x 0.85 spent last month
		$this->assertEqualsWithDelta(135.0, $forecast['trends']['avgMonthlyExpenses'], 0.001);
		$this->assertEqualsWithDelta(135.0, $forecast['categoryBreakdown'][0]['avgMonthly'], 0.001);
	}

	public function testScenariosAreInTheBaseCurrency(): void {
		$this->spendOn(date('Y-m-d'));
		$scenarios = $this->service(ScenarioBuilder::class);

		// 2715 now, less 135 a month for a year
		$this->assertEqualsWithDelta(1095.0, $scenarios->calculateScenarioBalance($this->userId, null, 0.0, 0.0), 0.001);
		$history = $scenarios->getHistoricalBalances($this->userId, null, 2);
		$this->assertEqualsWithDelta(2715.0, $history[0], 0.001);
		$this->assertEqualsWithDelta(2850.0, $history[1], 0.001);
	}
}
