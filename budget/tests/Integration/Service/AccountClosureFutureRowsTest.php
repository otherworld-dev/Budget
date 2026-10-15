<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Service\AccountClosureService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * An account can't be closed while a transaction is booked in it for a
 * later date. The guard skipped every scheduled row, meant for a bill's
 * pre-booked payment (which the bill check covers), so a purchase entered
 * for next week, which is stored as scheduled, let a zero-balance account
 * close, and the closed account went to -40 when it cleared.
 */
class AccountClosureFutureRowsTest extends IntegrationTestCase {
	private Account $account;

	protected function setUp(): void {
		parent::setUp();
		$this->account = $this->makeAccount(['name' => 'To close']);
	}

	public function testAScheduledPurchaseBookedAheadStopsTheClosure(): void {
		$this->makeTransaction($this->account->getId(), [
			'amount' => '40.00', 'status' => 'scheduled', 'date' => date('Y-m-d', strtotime('+20 days')),
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('dated after today');
		$this->service(AccountClosureService::class)->assertClosable($this->account);
	}

	public function testABillsPrebookedPaymentIsLeftToTheBillCheck(): void {
		// A bill no longer active (999xxx exists nowhere), so only the
		// placeholder itself could stand in the way
		$this->makeTransaction($this->account->getId(), [
			'amount' => '40.00', 'status' => 'scheduled', 'bill_id' => 999417,
			'date' => date('Y-m-d', strtotime('+20 days')),
		]);

		$this->service(AccountClosureService::class)->assertClosable($this->account);
		$this->addToAssertionCount(1);
	}
}
