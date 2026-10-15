<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\TransactionService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * What a share recipient's own data lets go of when a share ends or
 * narrows: Alice shares her joint account and her Food and Fuel categories
 * with Bob, Carol shares her account and a category with him too, and Bob
 * has filed his own data under Alice's and Carol's.
 */
class CrossUserLinksLostAccessTest extends TestCase {
	private const CREATED = '2026-01-01 10:00:00';

	private InMemoryCrossUserLinks $links;

	/** @var int[] bills whose pending rows were removed */
	private array $prebookingsDropped = [];

	protected function setUp(): void {
		$links = null;
		$transactionService = $this->createMock(TransactionService::class);
		$transactionService->method('deleteScheduledBillTransactions')
			->willReturnCallback(function (int $billId) use (&$links) {
				$this->prebookingsDropped[] = $billId;
				$links->deleteScheduledRowsOf($billId);
			});
		$links = new InMemoryCrossUserLinks($this->createMock(IDBConnection::class), $transactionService);
		$this->links = $links;

		$entity = static fn (string $user, string $name, array $extra = []) => ['user_id' => $user, 'name' => $name, 'created_at' => self::CREATED] + $extra;
		$row = static fn (int $account, array $extra = []) => $extra + ['account_id' => $account, 'date' => '2026-09-01', 'amount' => '10.00', 'type' => 'debit', 'status' => 'cleared', 'bill_id' => null, 'category_id' => null];
		$bill = static fn (string $user, string $name, ?int $account, array $extra = []) => $entity($user, $name, $extra + [
			'account_id' => $account, 'destination_account_id' => null, 'category_id' => null, 'auto_pay_enabled' => true, 'split_template' => null,
		]);

		$this->links->tables = [
			'budget_accounts' => [
				10 => $entity('alice', 'Joint'),
				11 => $entity('alice', 'Private'),
				50 => $entity('bob', 'Bob current'),
				60 => $entity('carol', 'Carol main'),
			],
			'budget_categories' => [
				20 => $entity('alice', 'Food'),
				21 => $entity('alice', 'Fuel'),
				70 => $entity('bob', 'Bob fun'),
				80 => $entity('carol', 'Carol food'),
			],
			'budget_shares' => [
				1 => ['owner_user_id' => 'alice', 'shared_with_user_id' => 'bob', 'status' => 'accepted'],
				2 => ['owner_user_id' => 'carol', 'shared_with_user_id' => 'bob', 'status' => 'accepted'],
			],
			'budget_share_items' => [
				1 => ['share_id' => 1, 'entity_type' => 'account', 'entity_id' => 10, 'permission' => 'write'],
				2 => ['share_id' => 1, 'entity_type' => 'category', 'entity_id' => 20, 'permission' => 'read'],
				3 => ['share_id' => 1, 'entity_type' => 'category', 'entity_id' => 21, 'permission' => 'full'],
				4 => ['share_id' => 2, 'entity_type' => 'account', 'entity_id' => 60, 'permission' => 'write'],
				5 => ['share_id' => 2, 'entity_type' => 'category', 'entity_id' => 80, 'permission' => 'read'],
			],
			'budget_bills' => [
				40 => $bill('bob', 'Netflix', 10, ['category_id' => 20, 'split_template' => json_encode([['categoryId' => 21, 'amount' => 3], ['categoryId' => 70, 'amount' => 2]])]),
				41 => $bill('bob', 'Into joint', 50, ['destination_account_id' => 10, 'is_transfer' => true]),
				42 => $bill('bob', 'Own', 50, ['category_id' => 70]),
				43 => $bill('bob', 'On Carol', 60, ['category_id' => 80]),
				44 => $bill('alice', 'Rent', 10, ['category_id' => 20]),
			],
			'budget_recurring_income' => [
				45 => $entity('bob', 'Lodger', ['account_id' => 10, 'category_id' => 20]),
				46 => $entity('alice', 'Salary', ['account_id' => 10, 'category_id' => 20]),
			],
			'budget_savings_goals' => [
				47 => $entity('bob', 'Holiday', ['account_id' => 10]),
			],
			'budget_pen_recur' => [
				48 => $entity('bob', 'Monthly pension', ['source_account_id' => 10, 'auto_post_enabled' => true]),
			],
			'budget_pen_contribs' => [
				49 => $entity('bob', 'Paid in September', ['source_account_id' => 10]),
			],
			'budget_import_rules' => [
				39 => $entity('bob', 'Supermarkets', ['category_id' => 20]),
			],
			'budget_transactions' => [
				100 => $row(50, ['category_id' => 20]),
				101 => $row(10, ['category_id' => 20]),
				102 => $row(50, ['category_id' => 70]),
				103 => $row(10, ['bill_id' => 40, 'status' => 'scheduled']),
				104 => $row(50, ['bill_id' => 41, 'status' => 'scheduled']),
				105 => $row(60, ['category_id' => 80]),
			],
			'budget_tx_splits' => [
				110 => ['transaction_id' => 100, 'category_id' => 21],
				111 => ['transaction_id' => 101, 'category_id' => 21],
				112 => ['transaction_id' => 102, 'category_id' => 70],
			],
			'budget_expense_shares' => [
				120 => ['user_id' => 'bob', 'transaction_id' => 101],
				121 => ['user_id' => 'bob', 'transaction_id' => 100],
				122 => ['user_id' => 'alice', 'transaction_id' => 101],
				123 => ['user_id' => 'bob', 'transaction_id' => 105],
			],
		];
	}

	private function endShare(int $shareId): void {
		unset($this->links->tables['budget_shares'][$shareId]);
		foreach ($this->links->tables['budget_share_items'] as $id => $item) {
			if ($item['share_id'] === $shareId) {
				unset($this->links->tables['budget_share_items'][$id]);
			}
		}
	}

	public function testARevokedRecipientLetsGoOfEverythingOfTheOwners(): void {
		$this->endShare(1);

		$result = $this->links->cutLostAccess('bob', 'alice');
		$t = $this->links->tables;

		// Bob's bills on Alice's account let go of it and stop auto-paying;
		// their pending rows go with it
		$this->assertNull($t['budget_bills'][40]['account_id']);
		$this->assertNull($t['budget_bills'][40]['category_id']);
		$this->assertFalse($t['budget_bills'][40]['auto_pay_enabled']);
		$this->assertNull($t['budget_bills'][41]['destination_account_id']);
		$this->assertSame(50, $t['budget_bills'][41]['account_id']);
		$this->assertFalse($t['budget_bills'][41]['auto_pay_enabled']);
		$this->assertEqualsCanonicalizing([40, 41], $this->prebookingsDropped);
		$this->assertArrayNotHasKey(103, $t['budget_transactions']);
		$this->assertArrayNotHasKey(104, $t['budget_transactions']);
		// The split template keeps its parts, without Alice's category
		$this->assertSame([['categoryId' => null, 'amount' => 3], ['categoryId' => 70, 'amount' => 2]], json_decode($t['budget_bills'][40]['split_template'], true));

		// His income, goal, recurring pension payment and rule
		$this->assertNull($t['budget_recurring_income'][45]['account_id']);
		$this->assertNull($t['budget_recurring_income'][45]['category_id']);
		$this->assertNull($t['budget_savings_goals'][47]['account_id']);
		$this->assertNull($t['budget_pen_recur'][48]['source_account_id']);
		// It would go on posting with no bank leg, as when an account is deleted
		$this->assertFalse($t['budget_pen_recur'][48]['auto_post_enabled']);
		$this->assertNull($t['budget_import_rules'][39]['category_id']);
		// A pension payment already made keeps the account it came from
		$this->assertSame(10, $t['budget_pen_contribs'][49]['source_account_id']);

		// His own rows and split parts go to No category
		$this->assertNull($t['budget_transactions'][100]['category_id']);
		$this->assertNull($t['budget_tx_splits'][110]['category_id']);

		// His splits with contacts are his own record of what they owe him:
		// kept, Alice's transaction included (it just stops showing)
		$this->assertSame([120, 121, 122, 123], array_keys($t['budget_expense_shares']));
		$this->assertSame(11, $result['detached']);

		// Alice's own data is hers, and Bob's links to Carol's are untouched
		$this->assertSame(20, $t['budget_transactions'][101]['category_id']);
		$this->assertSame(21, $t['budget_tx_splits'][111]['category_id']);
		$this->assertSame(10, $t['budget_bills'][44]['account_id']);
		$this->assertSame(10, $t['budget_recurring_income'][46]['account_id']);
		$this->assertArrayHasKey(122, $t['budget_expense_shares']);
		$this->assertSame(60, $t['budget_bills'][43]['account_id']);
		$this->assertSame(80, $t['budget_bills'][43]['category_id']);
		$this->assertTrue($t['budget_bills'][43]['auto_pay_enabled']);
		$this->assertSame(80, $t['budget_transactions'][105]['category_id']);
		$this->assertArrayHasKey(123, $t['budget_expense_shares']);
		// And his own
		$this->assertSame(70, $t['budget_bills'][42]['category_id']);
		$this->assertSame(70, $t['budget_tx_splits'][112]['category_id']);
	}

	public function testOnlyWhatStoppedBeingSharedIsCut(): void {
		// Alice stops sharing Fuel; the account and Food stay shared
		unset($this->links->tables['budget_share_items'][3]);

		$this->links->cutLostAccess('bob', 'alice');
		$t = $this->links->tables;

		$this->assertNull($t['budget_tx_splits'][110]['category_id']);
		$this->assertSame([['categoryId' => null, 'amount' => 3], ['categoryId' => 70, 'amount' => 2]], json_decode($t['budget_bills'][40]['split_template'], true));
		$this->assertSame(10, $t['budget_bills'][40]['account_id']);
		$this->assertSame(20, $t['budget_bills'][40]['category_id']);
		$this->assertTrue($t['budget_bills'][40]['auto_pay_enabled']);
		$this->assertSame(20, $t['budget_transactions'][100]['category_id']);
		$this->assertArrayHasKey(120, $t['budget_expense_shares']);
		$this->assertSame([], $this->prebookingsDropped);
	}

	public function testAnAccountCutToReadOnlyIsKept(): void {
		// Bob can no longer write to the joint account, but still sees it: a
		// bill on it is refused when it next pays and works again if write
		// comes back, so nothing is cut
		$this->links->tables['budget_share_items'][1]['permission'] = 'read';

		$result = $this->links->cutLostAccess('bob', 'alice');
		$t = $this->links->tables;

		$this->assertSame(['detached' => 0], $result);
		$this->assertSame(10, $t['budget_bills'][40]['account_id']);
		$this->assertSame(10, $t['budget_recurring_income'][45]['account_id']);
		$this->assertArrayHasKey(120, $t['budget_expense_shares']);
	}

	public function testASharePendingAgainGivesNothingBack(): void {
		// Re-shared after a revoke, not yet accepted: Bob can't see any of it
		$this->links->tables['budget_shares'][1]['status'] = 'pending';

		$this->links->cutLostAccess('bob', 'alice');

		$this->assertNull($this->links->tables['budget_bills'][40]['account_id']);
		$this->assertNull($this->links->tables['budget_transactions'][100]['category_id']);
	}

	public function testTheOwnerThemselvesIsNeverCut(): void {
		$this->assertSame(['detached' => 0], $this->links->cutLostAccess('alice', 'alice'));
		$this->assertSame(20, $this->links->tables['budget_bills'][44]['category_id']);
	}
}
