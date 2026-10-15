<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\ExpenseShareMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * What a user sees of the transactions other users split with them.
 *
 * Bob can split a transaction in an account Alice shares with him, and the
 * person he splits it with sees its description, date and amount. That has
 * to end when Bob can no longer see the transaction himself: the query read
 * Alice's row live, so after she stopped sharing the account, the person Bob
 * split it with went on seeing it, edits included.
 */
class ExpenseShareVisibilityTest extends IntegrationTestCase {
	private ExpenseShareMapper $mapper;
	private string $bob;
	private string $carol;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->service(ExpenseShareMapper::class);
		$this->bob = $this->newUserId();
		$this->carol = $this->newUserId();
	}

	/**
	 * Alice ($this->userId) shares her joint account with Bob read-only; Bob
	 * splits one of its transactions, and one of his own, with his contact
	 * for Carol.
	 *
	 * @return array<string, int>
	 */
	private function world(string $permission = 'read'): array {
		$now = $this->now();
		$ids = [];
		$ids['joint'] = $this->makeAccount(['name' => 'Joint'])->getId();
		$ids['private'] = $this->makeAccount(['name' => 'Private'])->getId();
		$ids['bobAccount'] = $this->makeAccount(['name' => 'Bob current'], $this->bob)->getId();
		$ids['share'] = $this->insertRow('budget_shares', [
			'owner_user_id' => $this->userId, 'shared_with_user_id' => $this->bob, 'status' => 'accepted',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$ids['item'] = $this->insertRow('budget_share_items', [
			'share_id' => $ids['share'], 'entity_type' => 'account', 'entity_id' => $ids['joint'], 'permission' => $permission,
			'created_at' => $now, 'updated_at' => $now,
		]);
		$ids['aliceTx'] = $this->makeTransaction($ids['joint'], ['description' => 'Alice groceries', 'amount' => '40.00']);
		$ids['bobTx'] = $this->makeTransaction($ids['bobAccount'], ['description' => 'Bob lunch', 'amount' => '12.00']);
		$ids['contact'] = $this->insertRow('budget_contacts', [
			'user_id' => $this->bob, 'name' => 'Carol', 'nextcloud_user_id' => $this->carol, 'created_at' => $now,
		]);
		$ids['onAlice'] = $this->makeExpenseShare($ids['aliceTx'], $ids['contact'], $this->bob);
		$ids['onBob'] = $this->makeExpenseShare($ids['bobTx'], $ids['contact'], $this->bob);
		return $ids;
	}

	/** @return array<int, array<string, mixed>> share id => row */
	private function sharedWithCarol(?string $owner = null): array {
		$rows = [];
		foreach ($this->mapper->findSharedWithNextcloudUser($this->carol, $owner) as $row) {
			$rows[(int)$row['id']] = $row;
		}
		return $rows;
	}

	public function testCarolSeesTheTransactionWhileBobCanSeeIt(): void {
		$w = $this->world();

		$rows = $this->sharedWithCarol();

		$this->assertSame('Alice groceries', $rows[$w['onAlice']]['transaction_description']);
		$this->assertSame('Bob lunch', $rows[$w['onBob']]['transaction_description']);
		$this->assertSame($this->bob, $rows[$w['onAlice']]['owner_user_id']);
	}

	public function testTheTransactionIsHiddenOnceTheAccountIsNoLongerSharedWithBob(): void {
		$w = $this->world();
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_share_items WHERE id = ?', [$w['item']]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET description = ? WHERE id = ?', ['Edited afterwards', $w['aliceTx']]);

		$rows = $this->sharedWithCarol();

		// Bob's own split stays, without Alice's transaction behind it
		$this->assertArrayHasKey($w['onAlice'], $rows);
		$this->assertNull($rows[$w['onAlice']]['transaction_description']);
		$this->assertNull($rows[$w['onAlice']]['transaction_date']);
		$this->assertNull($rows[$w['onAlice']]['transaction_amount']);
		$this->assertNull($rows[$w['onAlice']]['transaction_type']);
		$this->assertSame('Bob lunch', $rows[$w['onBob']]['transaction_description']);
		// Narrowed to Bob, the same
		$this->assertNull($this->sharedWithCarol($this->bob)[$w['onAlice']]['transaction_description']);
	}

	public function testTheTransactionIsHiddenOnceTheShareEnds(): void {
		$w = $this->world('write');
		$this->db()->executeStatement('UPDATE *PREFIX*budget_shares SET status = ? WHERE id = ?', ['declined', $w['share']]);

		$this->assertNull($this->sharedWithCarol()[$w['onAlice']]['transaction_description']);
	}

	public function testTheTransactionIsHiddenOnceAliceMovesItToAnAccountBobCannotSee(): void {
		$w = $this->world();
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET account_id = ? WHERE id = ?', [$w['private'], $w['aliceTx']]);

		$this->assertNull($this->sharedWithCarol()[$w['onAlice']]['transaction_description']);
	}
}
