<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\CrossUserLinks;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\SharedExpenseService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * A share that ends, against the real database: what the recipient's own
 * data still pointed at in the owner's lets go of it. The decisions are unit
 * tested in CrossUserLinksLostAccessTest; this proves the SQL behind them.
 */
class ShareRevokeLinksTest extends IntegrationTestCase {
	private CrossUserLinks $links;
	private string $bob;

	protected function setUp(): void {
		parent::setUp();
		$this->links = $this->service(CrossUserLinks::class);
		$this->bob = $this->newUserId();
	}

	/**
	 * Alice ($this->userId) shares her joint account (write) and her Food
	 * and Fuel categories with Bob. Bob files his own data under them.
	 *
	 * @return array<string, int>
	 */
	private function sharedWorld(): array {
		$now = $this->now();
		$ids = [];
		$ids['joint'] = $this->makeAccount(['name' => 'Joint'])->getId();
		$ids['food'] = $this->makeCategory(['name' => 'Food']);
		$ids['fuel'] = $this->makeCategory(['name' => 'Fuel']);
		$ids['bobAccount'] = $this->makeAccount(['name' => 'Bob current'], $this->bob)->getId();
		$ids['bobFun'] = $this->makeCategory(['name' => 'Bob fun'], $this->bob);
		$ids['share'] = $this->insertRow('budget_shares', [
			'owner_user_id' => $this->userId, 'shared_with_user_id' => $this->bob, 'status' => 'accepted',
			'created_at' => $now, 'updated_at' => $now,
		]);
		foreach ([['account', $ids['joint'], 'write'], ['category', $ids['food'], 'read'], ['category', $ids['fuel'], 'full']] as [$type, $id, $permission]) {
			$ids['item_' . $type . '_' . $id] = $this->insertRow('budget_share_items', [
				'share_id' => $ids['share'], 'entity_type' => $type, 'entity_id' => $id, 'permission' => $permission,
				'created_at' => $now, 'updated_at' => $now,
			]);
		}

		$ids['netflix'] = $this->insertRow('budget_bills', [
			'user_id' => $this->bob, 'name' => 'Netflix', 'amount' => '9.99', 'frequency' => 'monthly', 'due_day' => 15,
			'account_id' => $ids['joint'], 'category_id' => $ids['food'], 'is_active' => true, 'auto_pay_enabled' => true,
			'split_template' => json_encode([['categoryId' => $ids['fuel'], 'amount' => 5.99], ['categoryId' => $ids['bobFun'], 'amount' => 4]]),
			'next_due_date' => '2026-10-15', 'created_at' => $now,
		]);
		$ids['pending'] = $this->makeTransaction($ids['joint'], ['bill_id' => $ids['netflix'], 'status' => 'scheduled', 'date' => '2026-10-15', 'amount' => '9.99']);
		$ids['income'] = $this->insertRow('budget_recurring_income', [
			'user_id' => $this->bob, 'name' => 'Lodger', 'amount' => '300.00', 'frequency' => 'monthly',
			'account_id' => $ids['joint'], 'category_id' => $ids['food'], 'created_at' => $now, 'is_active' => true,
		]);
		$ids['goal'] = $this->insertRow('budget_savings_goals', [
			'user_id' => $this->bob, 'name' => 'Holiday', 'target_amount' => '1000.00', 'current_amount' => '0.00',
			'created_at' => $now, 'account_id' => $ids['joint'],
		]);
		$ids['pension'] = $this->insertRow('budget_pensions', [
			'user_id' => $this->bob, 'name' => 'Workplace', 'type' => 'workplace', 'currency' => 'GBP',
			'current_balance' => '0.00', 'created_at' => $now, 'updated_at' => $now,
		]);
		$ids['penRecur'] = $this->insertRow('budget_pen_recur', [
			'user_id' => $this->bob, 'pension_id' => $ids['pension'], 'amount' => '100.00', 'frequency' => 'monthly',
			'source_account_id' => $ids['joint'], 'next_due_date' => '2026-10-20', 'is_active' => true,
			'auto_post_enabled' => true, 'created_at' => $now, 'updated_at' => $now,
		]);
		$ids['rule'] = $this->insertRow('budget_import_rules', [
			'user_id' => $this->bob, 'name' => 'Tesco', 'pattern' => 'TESCO', 'field' => 'description',
			'match_type' => 'contains', 'category_id' => $ids['food'], 'priority' => 0, 'active' => true,
			'created_at' => $now, 'schema_version' => 1,
		]);
		$ids['bobRow'] = $this->makeTransaction($ids['bobAccount'], ['category_id' => $ids['food']]);
		$ids['bobSplit'] = $this->makeSplitTransaction($ids['bobAccount'], [[$ids['fuel'], '6.00'], [$ids['bobFun'], '4.00']]);
		$ids['aliceRow'] = $this->makeTransaction($ids['joint'], ['category_id' => $ids['food'], 'description' => 'Alice groceries']);
		$ids['contact'] = $this->makeContact($this->bob);
		$ids['shareOfAlices'] = $this->makeExpenseShare($ids['aliceRow'], $ids['contact'], $this->bob);
		$ids['shareOfBobs'] = $this->makeExpenseShare($ids['bobRow'], $ids['contact'], $this->bob);
		return $ids;
	}

	/** @return array<string, mixed> */
	private function splitPartsOf(int $transactionId): array {
		$qb = $this->db()->getQueryBuilder();
		$qb->select('category_id')->from('budget_tx_splits')
			->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId)))
			->orderBy('id');
		$result = $qb->executeQuery();
		$parts = array_map(static fn ($v) => $v === null ? null : (int)$v, $result->fetchAll(\PDO::FETCH_COLUMN));
		$result->closeCursor();
		return $parts;
	}

	public function testARevokedRecipientsDataLetsGoOfTheOwners(): void {
		$w = $this->sharedWorld();
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_share_items WHERE share_id = ?', [$w['share']]);
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_shares WHERE id = ?', [$w['share']]);

		$result = $this->links->cutLostAccess($this->bob, $this->userId);

		$netflix = $this->fetchRow('budget_bills', $w['netflix']);
		$this->assertNull($netflix['account_id']);
		$this->assertNull($netflix['category_id']);
		$this->assertFalse((bool)$netflix['auto_pay_enabled']);
		$this->assertSame([null, $w['bobFun']], array_column(json_decode($netflix['split_template'], true), 'categoryId'));
		$this->assertNull($this->fetchRow('budget_transactions', $w['pending']));
		$income = $this->fetchRow('budget_recurring_income', $w['income']);
		$this->assertNull($income['account_id']);
		$this->assertNull($income['category_id']);
		$this->assertNull($this->fetchRow('budget_savings_goals', $w['goal'])['account_id']);
		$penRecur = $this->fetchRow('budget_pen_recur', $w['penRecur']);
		$this->assertNull($penRecur['source_account_id']);
		$this->assertFalse((bool)$penRecur['auto_post_enabled']);
		$this->assertNull($this->fetchRow('budget_import_rules', $w['rule'])['category_id']);
		$this->assertNull($this->fetchRow('budget_transactions', $w['bobRow'])['category_id']);
		$this->assertSame([null, $w['bobFun']], $this->splitPartsOf($w['bobSplit']));
		// Bob's splits with his contact are his own record of what they owe
		// him, the one of Alice's transaction included
		$this->assertNotNull($this->fetchRow('budget_expense_shares', $w['shareOfAlices']));
		$this->assertNotNull($this->fetchRow('budget_expense_shares', $w['shareOfBobs']));
		$this->assertSame(['detached' => 10], $result);

		// Alice's own row is hers
		$this->assertSame($w['food'], (int)$this->fetchRow('budget_transactions', $w['aliceRow'])['category_id']);
	}

	public function testOnlyTheCategoryTakenOffTheShareIsCut(): void {
		$w = $this->sharedWorld();
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_share_items WHERE id = ?', [$w['item_category_' . $w['fuel']]]);

		$this->links->cutLostAccess($this->bob, $this->userId);

		$netflix = $this->fetchRow('budget_bills', $w['netflix']);
		$this->assertSame($w['joint'], (int)$netflix['account_id']);
		$this->assertSame($w['food'], (int)$netflix['category_id']);
		$this->assertTrue((bool)$netflix['auto_pay_enabled']);
		$this->assertSame([null, $w['bobFun']], array_column(json_decode($netflix['split_template'], true), 'categoryId'));
		$this->assertNotNull($this->fetchRow('budget_transactions', $w['pending']));
		$this->assertSame($w['food'], (int)$this->fetchRow('budget_transactions', $w['bobRow'])['category_id']);
		$this->assertSame([null, $w['bobFun']], $this->splitPartsOf($w['bobSplit']));
		$this->assertNotNull($this->fetchRow('budget_expense_shares', $w['shareOfAlices']));
	}

	/**
	 * Bob's split of Alice's transaction outlives the share: listed under a
	 * neutral label with its own amount, counted in the balance with his
	 * contact and settleable, with nothing of Alice's transaction in it.
	 */
	public function testARevokedRecipientsSplitWithAContactStaysUsable(): void {
		$w = $this->sharedWorld();
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_share_items WHERE share_id = ?', [$w['share']]);
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_shares WHERE id = ?', [$w['share']]);
		$this->links->cutLostAccess($this->bob, $this->userId);

		$this->assertTheSplitOfAlicesTransactionStaysUsable($w);
	}

	/** The same for a transaction Alice deleted, which takes only her own splits with it */
	public function testASplitOfATransactionTheOwnerDeletedStaysUsable(): void {
		$w = $this->sharedWorld();
		$this->db()->executeStatement('DELETE FROM *PREFIX*budget_transactions WHERE id = ?', [$w['aliceRow']]);

		$this->assertTheSplitOfAlicesTransactionStaysUsable($w);
	}

	/** @param array<string, int> $w */
	private function assertTheSplitOfAlicesTransactionStaysUsable(array $w): void {
		$expenses = $this->service(SharedExpenseService::class);
		$visible = $this->service(GranularShareService::class)->getVisibleAccountIds($this->bob);

		$details = $expenses->getContactDetails($w['contact'], $this->bob, $visible);

		$listed = [];
		foreach ($details['shares'] as $item) {
			$listed[$item['share']['id']] = $item['transaction'];
		}
		$this->assertSame(['id' => $w['aliceRow'], 'date' => '', 'description' => 'Shared expense', 'amount' => null], $listed[$w['shareOfAlices']]);
		$this->assertSame('Test transaction', $listed[$w['shareOfBobs']]['description']);
		$this->assertStringNotContainsString('Alice groceries', json_encode($details));
		// Both open splits of 5.00 count
		$this->assertEqualsWithDelta(10.0, $details['balance'], 0.001);

		$expenses->settleSelectedShares($this->bob, [$w['shareOfAlices']], '2026-10-04');

		$this->assertTrue((bool)$this->fetchRow('budget_expense_shares', $w['shareOfAlices'])['is_settled']);
		$this->assertEqualsWithDelta(5.0, $expenses->getContactDetails($w['contact'], $this->bob, $visible)['balance'], 0.001);
	}
}
