<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\ForecastService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Projected spending never trends below what is already known: the bills
 * the user pays, and the least spent in any complete month of the history.
 * A falling line through three uneven months (1,060, 11 and 131) projected
 * no spending at all, so a reliable-looking forecast showed six months of
 * income saved whole.
 */
class ForecastSpendingFloorTest extends IntegrationTestCase {
	private int $account;

	protected function setUp(): void {
		parent::setUp();
		$this->account = $this->makeAccount(['name' => 'Current', 'balance' => 2000.0])->getId();
		$thisMonth = new \DateTimeImmutable(date('Y-m-01'));
		foreach ([3 => '1060.00', 2 => '11.00', 1 => '131.00'] as $back => $spent) {
			$month = $thisMonth->modify("-{$back} months");
			$this->makeTransaction($this->account, ['amount' => '2500.00', 'type' => 'credit', 'date' => $month->format('Y-m-01')]);
			$this->makeTransaction($this->account, ['amount' => $spent, 'date' => $month->format('Y-m-10')]);
			// A second row keeps the history over the ten transactions the
			// forecast calls reliable
			$this->makeTransaction($this->account, ['amount' => '0.01', 'type' => 'credit', 'date' => $month->format('Y-m-11')]);
			$this->makeTransaction($this->account, ['amount' => '0.01', 'type' => 'credit', 'date' => $month->format('Y-m-12')]);
		}
	}

	public function testSpendingIsNotProjectedBelowTheLeastMonthSpent(): void {
		$forecast = $this->service(ForecastService::class)->getLiveForecast($this->userId, 6);

		$this->assertTrue($forecast['dataQuality']['isReliable']);
		$this->assertSame(array_fill(0, 6, 11.0), array_column($forecast['monthlyProjections'], 'expenses'));
	}

	public function testSpendingIsNotProjectedBelowTheBills(): void {
		$this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Phone', 'amount' => '35.00', 'frequency' => 'monthly',
			'account_id' => $this->account, 'is_active' => true, 'created_at' => $this->now(), 'due_day' => 5,
		]);
		$this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Insurance', 'amount' => '180.00', 'frequency' => 'yearly',
			'account_id' => $this->account, 'is_active' => true, 'created_at' => $this->now(), 'due_day' => 5, 'due_month' => 3,
		]);

		$forecast = $this->service(ForecastService::class)->getLiveForecast($this->userId, 6);

		// 35 a month and 180 a year
		$this->assertSame(array_fill(0, 6, 50.0), array_column($forecast['monthlyProjections'], 'expenses'));
	}
}
