<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\ImportRuleService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A rule's Set Account against the real database: a row moves only within
 * its owner's own accounts, never into an account shared with them (whose
 * stored balance went stale, R2-2), and both ledgers of an own move are
 * recomputed.
 */
class RuleSetAccountTest extends IntegrationTestCase {
	private string $bob;

	protected function setUp(): void {
		parent::setUp();
		$this->bob = $this->newUserId();
	}

	private function setAccountRule(string $userId, string $contains, int $accountId): int {
		return $this->insertRow('budget_import_rules', [
			'user_id' => $userId,
			'name' => 'Move ' . $contains,
			'pattern' => '',
			'field' => 'description',
			'match_type' => 'contains',
			'priority' => 0,
			'active' => true,
			'apply_on_import' => true,
			'schema_version' => 2,
			'stop_processing' => true,
			'criteria' => json_encode(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
				['type' => 'condition', 'field' => 'description', 'matchType' => 'contains', 'pattern' => $contains, 'negate' => false],
			]]]),
			// Saved before Set Account was limited to the owner's accounts
			'actions' => json_encode(['version' => 2, 'actions' => [['type' => 'set_account', 'value' => $accountId, 'behavior' => 'always']]]),
			'created_at' => $this->now(),
		]);
	}

	private function storedBalance(int $accountId): float {
		return (float)$this->service(AccountMapper::class)->findById($accountId)->getBalance();
	}

	public function testARowIsNotMovedIntoAnAccountSharedWithItsOwner(): void {
		$now = $this->now();
		$joint = $this->makeAccount(['name' => 'Joint', 'openingBalance' => 100.0, 'balance' => 100.0])->getId();
		$share = $this->insertRow('budget_shares', [
			'owner_user_id' => $this->userId, 'shared_with_user_id' => $this->bob, 'status' => 'accepted',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_share_items', [
			'share_id' => $share, 'entity_type' => 'account', 'entity_id' => $joint, 'permission' => 'write',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$bobs = $this->makeAccount(['name' => 'Bob current', 'openingBalance' => 500.0, 'balance' => 500.0], $this->bob)->getId();
		$row = $this->makeTransaction($bobs, ['description' => 'JOINT GROCERIES', 'amount' => '40.00']);
		$rule = $this->setAccountRule($this->bob, 'JOINT', $joint);

		$result = $this->service(ImportRuleService::class)->applyRulesToTransactions($this->bob, [$rule], []);

		$this->assertSame(0, $result['success']);
		$this->assertSame($bobs, (int)$this->fetchRow('budget_transactions', $row)['account_id']);
		$this->assertEqualsWithDelta(100.0, $this->storedBalance($joint), 0.001);
	}

	public function testARuleCannotBeSavedToMoveRowsIntoAnotherUsersAccount(): void {
		$now = $this->now();
		$joint = $this->makeAccount(['name' => 'Joint'])->getId();
		$share = $this->insertRow('budget_shares', [
			'owner_user_id' => $this->userId, 'shared_with_user_id' => $this->bob, 'status' => 'accepted',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_share_items', [
			'share_id' => $share, 'entity_type' => 'account', 'entity_id' => $joint, 'permission' => 'write',
			'created_at' => $now, 'updated_at' => $now,
		]);

		$this->expectException(\InvalidArgumentException::class);
		$this->service(ImportRuleService::class)->create(
			userId: $this->bob,
			name: 'Joint shopping',
			criteria: ['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
				['type' => 'condition', 'field' => 'description', 'matchType' => 'contains', 'pattern' => 'JOINT', 'negate' => false],
			]]],
			schemaVersion: 2,
			actions: ['version' => 2, 'actions' => [['type' => 'set_account', 'value' => $joint, 'behavior' => 'always']]],
		);
	}

	public function testAMoveBetweenTheOwnersAccountsRecomputesBoth(): void {
		$current = $this->makeAccount(['name' => 'Current', 'openingBalance' => 500.0, 'balance' => 500.0])->getId();
		$savings = $this->makeAccount(['name' => 'Savings', 'openingBalance' => 0.0, 'balance' => 0.0])->getId();
		$row = $this->makeTransaction($current, ['description' => 'TO SAVINGS', 'amount' => '40.00', 'type' => 'credit']);
		$rule = $this->setAccountRule($this->userId, 'TO SAVINGS', $savings);

		$result = $this->service(ImportRuleService::class)->applyRulesToTransactions($this->userId, [$rule], []);

		$this->assertSame(1, $result['success']);
		$this->assertSame($savings, (int)$this->fetchRow('budget_transactions', $row)['account_id']);
		$this->assertEqualsWithDelta(500.0, $this->storedBalance($current), 0.001);
		$this->assertEqualsWithDelta(40.0, $this->storedBalance($savings), 0.001);
	}
}
