<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The account page's This Month Income and Expenses tiles counted
 * pre-booked bills and transfers that hadn't happened: an unpaid bill due
 * later in the month was already spent, and a pending transfer already
 * income in the account it was going to. They count what happened, as the
 * dashboard and reports do (ReportScope::excludeScheduledFuture).
 */
class AccountMetricsScopeTest extends IntegrationTestCase {
	public function testThisMonthsTilesCountOnlyWhatHappened(): void {
		$accountId = $this->makeAccount()->getId();
		$today = date('Y-m-d');
		$later = date('Y-m-d', strtotime('+5 days'));

		$this->makeTransaction($accountId, ['date' => $today, 'amount' => '20.00', 'type' => 'debit']);
		$this->makeTransaction($accountId, ['date' => $today, 'amount' => '300.00', 'type' => 'credit']);
		// A bill's pre-booked row and a pending incoming transfer, both later this period
		$this->makeTransaction($accountId, ['date' => $later, 'amount' => '100.00', 'type' => 'debit', 'status' => 'scheduled', 'bill_id' => 999101]);
		$this->makeTransaction($accountId, ['date' => $later, 'amount' => '250.00', 'type' => 'credit', 'status' => 'scheduled', 'bill_id' => 999102]);

		$metrics = $this->service(TransactionMapper::class)->getAccountMetrics(
			$accountId,
			date('Y-m-d', strtotime('-10 days')),
			date('Y-m-d', strtotime('+10 days'))
		);

		$this->assertEqualsWithDelta(300.0, $metrics['monthIncome'], 0.001);
		$this->assertEqualsWithDelta(20.0, $metrics['monthExpenses'], 0.001);
	}
}
