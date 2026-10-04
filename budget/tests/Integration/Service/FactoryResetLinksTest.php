<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Tests\Integration\FullDataset;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A factory reset or a deleted user, against the real database, for what
 * other users' data was left pointing at: rows under the user's deleted
 * shared category, bills auto-paying on a deleted shared account, transfer
 * legs paired with deleted rows, and contacts still linked to a deleted uid.
 * The decisions are unit tested in FactoryResetServiceTest and
 * CrossUserLinksTest; this proves the SQL behind them.
 */
class FactoryResetLinksTest extends IntegrationTestCase {
	use FullDataset;

	private FactoryResetService $reset;
	private string $bob;

	protected function setUp(): void {
		parent::setUp();
		$this->reset = $this->service(FactoryResetService::class);
		$this->bob = $this->newUserId();
	}

	/**
	 * Alice ($this->userId) shares her joint account (write) and her Food
	 * category with Bob, and Bob shares nothing back. Bob's Netflix bill
	 * auto-pays from the joint account under Food, his own shopping is filed
	 * under Food, he moved money from his account into the joint one, and
	 * his contact for Alice is linked to her uid.
	 *
	 * @return array<string, int>
	 */
	private function sharedWorld(): array {
		$now = $this->now();
		$ids = [];
		$ids['joint'] = $this->makeAccount(['name' => 'Joint'])->getId();
		$ids['bobAccount'] = $this->makeAccount(['name' => 'Bob current'], $this->bob)->getId();
		$ids['food'] = $this->makeCategory(['name' => 'Food']);
		$ids['share'] = $this->insertRow('budget_shares', [
			'owner_user_id' => $this->userId, 'shared_with_user_id' => $this->bob, 'status' => 'accepted',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_share_items', [
			'share_id' => $ids['share'], 'entity_type' => 'account', 'entity_id' => $ids['joint'], 'permission' => 'write',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_share_items', [
			'share_id' => $ids['share'], 'entity_type' => 'category', 'entity_id' => $ids['food'], 'permission' => 'read',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$ids['netflix'] = $this->insertRow('budget_bills', [
			'user_id' => $this->bob, 'name' => 'Netflix', 'amount' => '9.99', 'frequency' => 'monthly', 'due_day' => 15,
			'account_id' => $ids['joint'], 'category_id' => $ids['food'], 'is_active' => true, 'auto_pay_enabled' => true,
			'next_due_date' => '2026-10-15', 'created_at' => $now,
		]);
		$ids['shopping'] = $this->makeTransaction($ids['bobAccount'], ['category_id' => $ids['food']]);
		$ids['out'] = $this->makeTransaction($ids['bobAccount'], ['amount' => '100.00']);
		$ids['in'] = $this->makeTransaction($ids['joint'], ['amount' => '100.00', 'type' => 'credit', 'linked_transaction_id' => $ids['out']]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET linked_transaction_id = ? WHERE id = ?', [$ids['in'], $ids['out']]);
		$ids['contact'] = $this->makeContact($this->bob);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_contacts SET nextcloud_user_id = ? WHERE id = ?', [$this->userId, $ids['contact']]);
		return $ids;
	}

	public function testAResetCutsEverythingOfBobsThatUsedAlicesData(): void {
		$w = $this->sharedWorld();

		$this->reset->executeFactoryReset($this->userId);

		$netflix = $this->fetchRow('budget_bills', $w['netflix']);
		$this->assertNull($netflix['account_id']);
		$this->assertNull($netflix['category_id']);
		$this->assertFalse((bool)$netflix['auto_pay_enabled']);
		$this->assertNull($this->fetchRow('budget_transactions', $w['shopping'])['category_id']);
		$this->assertNull($this->fetchRow('budget_transactions', $w['out'])['linked_transaction_id']);
		$this->assertSame([], $this->danglingReferences());

		// Alice still exists: Bob's contact for her is still right
		$this->assertSame($this->userId, $this->fetchRow('budget_contacts', $w['contact'])['nextcloud_user_id']);
	}

	/**
	 * Bob, with write access, tagged a row in Alice's joint account with his
	 * own tag. His reset deleted the tag but cleared tag links only through
	 * his own transactions, so the link on Alice's row was left pointing at
	 * a tag that no longer existed (T4-11). Alice's own tag stays.
	 */
	public function testAResetRemovesTheUsersTagsFromOtherUsersRows(): void {
		$w = $this->sharedWorld();
		[$bobsLink, $alicesLink] = $this->tagAlicesRow($w);

		$this->reset->executeFactoryReset($this->bob);

		$this->assertNull($this->fetchRow('budget_transaction_tags', $bobsLink));
		$this->assertNotNull($this->fetchRow('budget_transaction_tags', $alicesLink));
		$this->assertSame([], $this->danglingReferences());
	}

	/**
	 * The same through a restore of Bob's own backup, which holds his tags
	 * but not Alice's rows.
	 */
	public function testARestoreRemovesTheUsersTagsFromOtherUsersRows(): void {
		$w = $this->sharedWorld();
		[$bobsLink, $alicesLink] = $this->tagAlicesRow($w);
		$migration = $this->service(\OCA\Budget\Service\MigrationService::class);

		$migration->importAll($this->bob, $migration->exportAll($this->bob)['content']);

		$this->assertNull($this->fetchRow('budget_transaction_tags', $bobsLink));
		$this->assertNotNull($this->fetchRow('budget_transaction_tags', $alicesLink));
		$this->assertSame([], $this->danglingReferences());
	}

	/**
	 * @return array{0: int, 1: int} Bob's and Alice's tag links on Alice's joint row
	 */
	private function tagAlicesRow(array $w): array {
		$bobsTag = $this->makeTag(null, $this->bob);
		$alicesTag = $this->makeTag($this->makeTagSet($w['food']));
		return [$this->tagTransaction($w['in'], $bobsTag), $this->tagTransaction($w['in'], $alicesTag)];
	}

	public function testADeletedUsersUidLeavesNoAccessBehind(): void {
		$w = $this->sharedWorld();
		$bobsShare = $this->insertRow('budget_shares', [
			'owner_user_id' => $this->bob, 'shared_with_user_id' => $this->userId, 'status' => 'accepted',
			'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);

		$this->reset->purgeDeletedUser($this->userId);

		$this->assertNull($this->fetchRow('budget_shares', $bobsShare));
		$this->assertNull($this->fetchRow('budget_shares', $w['share']));
		$this->assertNull($this->fetchRow('budget_contacts', $w['contact'])['nextcloud_user_id']);
		$this->assertNull($this->fetchRow('budget_bills', $w['netflix'])['account_id']);
	}
}
