<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\NetWorthService;
use OCA\Budget\Service\Report\ReportAggregator;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A shared account's balance as of today is the same for the person it is
 * shared with as for its owner, on every surface that sums balances.
 *
 * The stored balance counts a future-dated transaction that isn't
 * scheduled (bank sync books rows ahead as cleared, and moving a row's
 * date forward keeps it cleared), so each surface takes the rows after
 * today back off. The dashboard summary, net worth and the forecast looked
 * those rows up for the viewer's OWN accounts only, so for the recipient a
 * shared account kept them: 700 on the dashboard where the Accounts page
 * and the owner both showed 900.
 */
class SharedAccountTodayBalanceTest extends IntegrationTestCase {
	private int $joint;

	protected function setUp(): void {
		parent::setUp();
		$owner = $this->newUserId();
		// Opening 1000, 100 spent last week, 200 booked for later this month
		$this->joint = $this->makeAccount(['name' => 'Joint', 'openingBalance' => 1000.0, 'balance' => 700.0], $owner)->getId();
		$this->makeTransaction($this->joint, ['amount' => '100.00', 'date' => date('Y-m-d', strtotime('-7 days'))]);
		$this->makeTransaction($this->joint, ['amount' => '200.00', 'date' => date('Y-m-d', strtotime('+16 days'))]);
	}

	public function testTheDashboardSummaryLeavesOutTheSharedAccountsFutureRows(): void {
		$summary = $this->service(ReportAggregator::class)->generateSummary(
			$this->userId, null, date('Y-m-01'), date('Y-m-t'), [], true, [$this->joint]
		);

		$this->assertEqualsWithDelta(900.0, $summary['accounts'][0]['balance'], 0.001);
		$this->assertEqualsWithDelta(900.0, $summary['totals']['currentBalance'], 0.001);
	}

	public function testNetWorthLeavesOutTheSharedAccountsFutureRows(): void {
		$netWorth = $this->service(NetWorthService::class)->calculateNetWorth($this->userId, [$this->joint]);

		$this->assertEqualsWithDelta(900.0, $netWorth['netWorth'], 0.001);
	}

	public function testTheForecastStartsFromTheSharedAccountsBalanceToday(): void {
		$forecast = $this->service(ForecastService::class)->getLiveForecast($this->userId, 3, [$this->joint]);

		$this->assertEqualsWithDelta(900.0, $forecast['currentBalance'], 0.001);
	}
}
