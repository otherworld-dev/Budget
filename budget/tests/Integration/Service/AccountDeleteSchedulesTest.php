<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\BillMapper;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Deleting an account never looked at what used it, so bills and transfers
 * stayed active on a deleted id: a card-statement transfer failed on every
 * Mark Paid and auto-pay, and the deposit it had pre-booked on the card side
 * stayed behind. Someone the account was shared with could have set them up,
 * and only the owner's were ever looked at.
 */
class AccountDeleteSchedulesTest extends IntegrationTestCase {
	private int $doomed;
	private int $card;

	protected function setUp(): void {
		parent::setUp();
		$this->doomed = $this->makeAccount(['name' => 'Old current'])->getId();
		$this->card = $this->makeAccount(['name' => 'Card', 'type' => 'credit_card'])->getId();
	}

	private function insertBill(array $overrides): int {
		return $this->insertRow('budget_bills', $overrides + [
			'user_id' => $this->userId, 'amount' => '200.00', 'frequency' => 'monthly',
			'is_active' => true, 'created_at' => $this->now(), 'due_day' => 1,
		]);
	}

	public function testEveryonesActiveBillsOnTheAccountAreFound(): void {
		$own = $this->insertBill(['name' => 'Gym', 'account_id' => $this->doomed]);
		$theirs = $this->insertBill(['name' => 'Their gym', 'account_id' => $this->doomed, 'user_id' => $this->newUserId()]);
		$into = $this->insertBill(['name' => 'Top-up', 'account_id' => $this->card, 'destination_account_id' => $this->doomed, 'is_transfer' => true]);
		$this->insertBill(['name' => 'Ended', 'account_id' => $this->doomed, 'is_active' => false]);
		$this->insertBill(['name' => 'Elsewhere', 'account_id' => $this->card]);

		$ids = array_map(fn ($bill) => (int)$bill->getId(), $this->service(BillMapper::class)->findActiveByAccount($this->doomed));

		$this->assertEqualsCanonicalizing([$own, $theirs, $into], $ids);
	}

	public function testDeletingTheAccountStopsTheTransferAndItsLegOnTheOtherSide(): void {
		$transfer = $this->insertBill([
			'name' => 'Card payment', 'account_id' => $this->doomed, 'destination_account_id' => $this->card, 'is_transfer' => true,
		]);
		$future = date('Y-m-d', strtotime('+10 days'));
		$out = $this->makeTransaction($this->doomed, ['bill_id' => $transfer, 'status' => 'scheduled', 'date' => $future, 'amount' => '200.00']);
		$in = $this->makeTransaction($this->card, ['bill_id' => $transfer, 'status' => 'scheduled', 'date' => $future,
			'amount' => '200.00', 'type' => 'credit', 'linked_transaction_id' => $out]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET linked_transaction_id = ? WHERE id = ?', [$in, $out]);

		$this->service(AccountService::class)->deleteWithTransactions($this->doomed, $this->userId);

		$row = $this->fetchRow('budget_bills', $transfer);
		$this->assertFalse((bool)$row['is_active']);
		$this->assertNull($row['account_id']);
		$this->assertSame($this->card, (int)$row['destination_account_id']);
		$this->assertNull($this->fetchRow('budget_transactions', $in), 'the pre-booked deposit on the card goes too');
	}
}
