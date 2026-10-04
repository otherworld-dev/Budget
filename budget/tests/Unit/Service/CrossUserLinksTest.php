<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\TransactionService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * A backup restore gives everything the restoring user owns a new id. These
 * cover the links that cross to another user's data, which the archive's id
 * maps can't reach: Alice shares her joint account, a category and her rent
 * bill with Bob, Bob shares his current account back, and both have bills
 * and rows on the other's accounts.
 */
class CrossUserLinksTest extends TestCase {
	private const CREATED = '2026-01-01 10:00:00';

	private InMemoryCrossUserLinks $links;

	protected function setUp(): void {
		$links = null;
		$transactionService = $this->createMock(TransactionService::class);
		$transactionService->method('deleteScheduledBillTransactions')
			->willReturnCallback(function (int $billId) use (&$links) {
				$links->deleteScheduledRowsOf($billId);
			});
		$links = new InMemoryCrossUserLinks($this->createMock(IDBConnection::class), $transactionService);
		$this->links = $links;

		$entity = static fn (string $user, string $name, array $extra = []) => ['user_id' => $user, 'name' => $name, 'created_at' => self::CREATED] + $extra;
		$row = static fn (int $account, string $date, string $amount, string $type, array $extra = []) => $extra + ['account_id' => $account, 'date' => $date, 'amount' => $amount, 'type' => $type, 'status' => 'cleared', 'bill_id' => null, 'linked_transaction_id' => null, 'category_id' => null];

		$this->links->tables = [
			'budget_accounts' => [
				10 => $entity('alice', 'Joint'),
				11 => $entity('alice', 'Old savings'),
				50 => $entity('bob', 'Bob current'),
			],
			'budget_categories' => [
				20 => $entity('alice', 'Food'),
			],
			'budget_bills' => [
				// Alice's rent, paid from Bob's account
				30 => $entity('alice', 'Rent', ['account_id' => 50, 'destination_account_id' => null, 'category_id' => null, 'split_template' => null, 'paid_undo_state' => null]),
				// Bob's bills on Alice's accounts
				40 => $entity('bob', 'Netflix', [
					'account_id' => 10, 'destination_account_id' => null, 'category_id' => 20, 'auto_pay_enabled' => true,
					'split_template' => json_encode([['categoryId' => 20, 'amount' => 5.5]]),
					'paid_undo_state' => json_encode(['previousState' => [], 'createdTransactionIds' => [101], 'scheduledTransactionIds' => [100], 'linkedTransactionId' => null]),
				]),
				41 => $entity('bob', 'Gym', ['account_id' => 11, 'destination_account_id' => null, 'category_id' => null, 'auto_pay_enabled' => true, 'split_template' => null, 'paid_undo_state' => null]),
				42 => $entity('bob', 'Into joint', ['account_id' => 50, 'destination_account_id' => 10, 'category_id' => null, 'auto_pay_enabled' => true, 'is_transfer' => true, 'split_template' => null, 'paid_undo_state' => null]),
			],
			'budget_shares' => [
				1 => ['owner_user_id' => 'alice', 'shared_with_user_id' => 'bob', 'status' => 'accepted'],
				2 => ['owner_user_id' => 'bob', 'shared_with_user_id' => 'alice', 'status' => 'accepted'],
			],
			'budget_share_items' => [
				1 => ['share_id' => 1, 'entity_type' => 'account', 'entity_id' => 10, 'permission' => 'write'],
				2 => ['share_id' => 1, 'entity_type' => 'category', 'entity_id' => 20, 'permission' => 'read'],
				3 => ['share_id' => 1, 'entity_type' => 'bill', 'entity_id' => 30, 'permission' => 'write'],
				4 => ['share_id' => 1, 'entity_type' => 'account', 'entity_id' => 11, 'permission' => 'write'],
				5 => ['share_id' => 2, 'entity_type' => 'account', 'entity_id' => 50, 'permission' => 'write'],
			],
			'budget_transactions' => [
				// Bob's Netflix in Alice's joint account: next one pending, last one paid
				100 => $row(10, '2026-10-15', '9.99', 'debit', ['bill_id' => 40, 'status' => 'scheduled']),
				101 => $row(10, '2026-09-15', '9.99', 'debit', ['bill_id' => 40]),
				// Bob's transfer from his account into the joint one
				102 => $row(50, '2026-09-01', '100.00', 'debit', ['bill_id' => 42, 'linked_transaction_id' => 103]),
				103 => $row(10, '2026-09-01', '100.00', 'credit', ['bill_id' => 42, 'linked_transaction_id' => 102]),
				// Alice's rent in Bob's account
				104 => $row(50, '2026-10-01', '800.00', 'debit', ['bill_id' => 30, 'status' => 'scheduled']),
				105 => $row(50, '2026-09-01', '800.00', 'debit', ['bill_id' => 30]),
				// Bob's shopping, filed under Alice's category
				106 => $row(50, '2026-09-20', '30.00', 'debit', ['category_id' => 20]),
			],
		];
	}

	/**
	 * Alice restores her own backup on the same server: what she shares and
	 * everything of Bob's that used it follows her data to its new ids.
	 */
	public function testAnOwnersRestoreCarriesEveryLinkToTheRestoredRows(): void {
		$this->links->capture('alice');
		$this->links->clearUser('alice');

		// The import puts Alice's data back under new ids...
		$t = &$this->links->tables;
		$t['budget_accounts'][210] = ['user_id' => 'alice', 'name' => 'Joint', 'created_at' => self::CREATED];
		$t['budget_categories'][220] = ['user_id' => 'alice', 'name' => 'Food', 'created_at' => self::CREATED];
		$t['budget_bills'][230] = ['user_id' => 'alice', 'name' => 'Rent', 'created_at' => self::CREATED, 'account_id' => 50];
		// ...and her rows keep their links to Bob's bills and transfer leg,
		// because each is the row she had here
		$this->assertTrue($this->links->keepsTransactionLink(100, '2026-10-15', 9.99, 'debit', 10, 'Joint', self::CREATED, 'billId', 40));
		$this->assertTrue($this->links->keepsTransactionLink(103, '2026-09-01', 100.0, 'credit', 10, 'Joint', self::CREATED, 'linkedId', 102));
		$this->assertFalse($this->links->keepsTransactionLink(103, '2026-09-01', 100.0, 'credit', 10, 'Joint', self::CREATED, 'linkedId', 999));
		$this->assertFalse($this->links->keepsTransactionLink(101, '2026-09-16', 9.99, 'debit', 10, 'Joint', self::CREATED, 'billId', 40));
		// The same row in an account that isn't the one it was in
		$this->assertFalse($this->links->keepsTransactionLink(100, '2026-10-15', 9.99, 'debit', 10, 'Holiday', self::CREATED, 'billId', 40));
		$this->assertFalse($this->links->keepsTransactionLink(100, '2026-10-15', 9.99, 'debit', 11, 'Old savings', self::CREATED, 'billId', 40));
		$t['budget_transactions'][300] = ['account_id' => 210, 'date' => '2026-10-15', 'amount' => '9.99', 'type' => 'debit', 'status' => 'scheduled', 'bill_id' => 40];
		$t['budget_transactions'][301] = ['account_id' => 210, 'date' => '2026-09-15', 'amount' => '9.99', 'type' => 'debit', 'status' => 'cleared', 'bill_id' => 40];
		$t['budget_transactions'][303] = ['account_id' => 210, 'date' => '2026-09-01', 'amount' => '100.00', 'type' => 'credit', 'status' => 'cleared', 'bill_id' => 42, 'linked_transaction_id' => 102];
		unset($t);

		$result = $this->links->apply([
			'accounts' => [10 => 210],
			'categories' => [20 => 220],
			'bills' => [30 => 230],
			'transactions' => [100 => 300, 101 => 301, 103 => 303],
		]);
		$t = $this->links->tables;

		// Shares follow the restored rows; the account the backup didn't
		// have is no longer shared
		$this->assertSame(210, $t['budget_share_items'][1]['entity_id']);
		$this->assertSame(220, $t['budget_share_items'][2]['entity_id']);
		$this->assertSame(230, $t['budget_share_items'][3]['entity_id']);
		$this->assertArrayNotHasKey(4, $t['budget_share_items']);
		$this->assertSame(50, $t['budget_share_items'][5]['entity_id'], "Bob's own share is not Alice's to change");
		$this->assertSame(1, $result['sharesDropped']);

		// Bob's bills point at the restored account and category
		$this->assertSame(210, $t['budget_bills'][40]['account_id']);
		$this->assertSame(220, $t['budget_bills'][40]['category_id']);
		$this->assertTrue($t['budget_bills'][40]['auto_pay_enabled']);
		$this->assertSame([['categoryId' => 220, 'amount' => 5.5]], json_decode($t['budget_bills'][40]['split_template'], true));
		$this->assertSame(210, $t['budget_bills'][42]['destination_account_id']);
		// His Mark Unpaid names the restored rows
		$snapshot = json_decode($t['budget_bills'][40]['paid_undo_state'], true);
		$this->assertSame([301], $snapshot['createdTransactionIds']);
		$this->assertSame([300], $snapshot['scheduledTransactionIds']);
		// His transfer leg pairs with the restored one
		$this->assertSame(303, $t['budget_transactions'][102]['linked_transaction_id']);
		// His shopping keeps its category
		$this->assertSame(220, $t['budget_transactions'][106]['category_id']);

		// Alice's rent rows in Bob's account follow her restored bill
		$this->assertSame(230, $t['budget_transactions'][104]['bill_id']);
		$this->assertSame(230, $t['budget_transactions'][105]['bill_id']);

		// The account the backup didn't have: Bob's gym bill loses it and
		// stops auto-paying rather than marking itself paid with nothing booked
		$this->assertNull($t['budget_bills'][41]['account_id']);
		$this->assertFalse($t['budget_bills'][41]['auto_pay_enabled']);
		$this->assertSame(1, $result['othersDetached']);
	}

	/**
	 * A backup from another server can hold the same ids for different
	 * things. Nothing is carried on an id alone: every link to Alice's
	 * data is cut, and nothing of Bob's is left pointing at a dead id.
	 */
	public function testARestoreFromElsewhereCutsTheLinksCleanly(): void {
		$this->links->capture('alice');
		$this->links->clearUser('alice');

		$t = &$this->links->tables;
		$t['budget_accounts'][210] = ['user_id' => 'alice', 'name' => 'Main', 'created_at' => '2025-05-05 09:00:00'];
		$t['budget_categories'][220] = ['user_id' => 'alice', 'name' => 'Food', 'created_at' => '2025-05-05 09:00:00'];
		$t['budget_bills'][230] = ['user_id' => 'alice', 'name' => 'Rent', 'created_at' => '2025-05-05 09:00:00'];
		$this->assertFalse($this->links->keepsTransactionLink(100, '2026-10-15', 12.0, 'debit', 10, 'Main', '2025-05-05 09:00:00', 'billId', 40));
		// Restored without its dead bill link, as the import does
		$t['budget_transactions'][301] = ['account_id' => 210, 'date' => '2026-09-15', 'amount' => '12.00', 'type' => 'debit', 'status' => 'cleared', 'bill_id' => null];
		unset($t);

		$result = $this->links->apply([
			'accounts' => [10 => 210],
			'categories' => [20 => 220],
			'bills' => [30 => 230],
			'transactions' => [101 => 301],
		]);
		$t = $this->links->tables;

		$this->assertSame([5], array_keys($t['budget_share_items']));
		$this->assertSame(4, $result['sharesDropped']);

		foreach ([40, 41] as $billId) {
			$this->assertNull($t['budget_bills'][$billId]['account_id']);
			$this->assertFalse($t['budget_bills'][$billId]['auto_pay_enabled']);
		}
		$this->assertNull($t['budget_bills'][40]['category_id']);
		$this->assertSame([['categoryId' => null, 'amount' => 5.5]], json_decode($t['budget_bills'][40]['split_template'], true));
		$this->assertNull($t['budget_bills'][42]['destination_account_id']);
		$this->assertFalse($t['budget_bills'][42]['auto_pay_enabled']);
		// The rows it names didn't come back, so a revert would leave the
		// payment standing
		$this->assertNull($t['budget_bills'][40]['paid_undo_state']);
		$this->assertNull($t['budget_transactions'][102]['linked_transaction_id']);
		$this->assertNull($t['budget_transactions'][106]['category_id']);

		// Alice's rent is gone: its pending row in Bob's account goes, as on
		// a delete, and the paid one keeps the money without the dead link
		$this->assertArrayNotHasKey(104, $t['budget_transactions']);
		$this->assertNull($t['budget_transactions'][105]['bill_id']);
		$this->assertGreaterThanOrEqual(6, $result['othersDetached']);
	}

	/**
	 * Alice resets her data (or her account is deleted): nothing comes back,
	 * so everything of Bob's that used her accounts, category, bill and rows
	 * is cut, the way her own single deletes do it.
	 */
	public function testAResetWithNothingToCarryCutsEveryLink(): void {
		$this->links->capture('alice');
		$this->links->clearUser('alice');

		$this->links->apply([]);
		$t = $this->links->tables;

		foreach ([40, 41] as $billId) {
			$this->assertNull($t['budget_bills'][$billId]['account_id']);
			$this->assertFalse($t['budget_bills'][$billId]['auto_pay_enabled']);
		}
		$this->assertNull($t['budget_bills'][40]['category_id']);
		$this->assertSame([['categoryId' => null, 'amount' => 5.5]], json_decode($t['budget_bills'][40]['split_template'], true));
		$this->assertNull($t['budget_bills'][42]['destination_account_id']);
		$this->assertNull($t['budget_transactions'][102]['linked_transaction_id']);
		$this->assertNull($t['budget_transactions'][106]['category_id']);
		$this->assertArrayNotHasKey(104, $t['budget_transactions']);
		$this->assertNull($t['budget_transactions'][105]['bill_id']);
	}

	/**
	 * Bob's recurring pension payment is funded from Alice's joint account.
	 * Cut loose by her reset, it kept auto-posting, with no bank leg.
	 */
	public function testAResetStopsAPensionPaymentItFundedFromAutoPosting(): void {
		$this->links->tables['budget_pen_recur'][60] = ['user_id' => 'bob', 'name' => null, 'created_at' => self::CREATED, 'source_account_id' => 10, 'auto_post_enabled' => true];
		$this->links->capture('alice');
		$this->links->clearUser('alice');

		$this->links->apply([]);

		$this->assertNull($this->links->tables['budget_pen_recur'][60]['source_account_id']);
		$this->assertFalse($this->links->tables['budget_pen_recur'][60]['auto_post_enabled']);
	}

	/**
	 * Bob restores his own backup: his bills on Alice's accounts may keep
	 * them while he can still write to them, and only if each is the bill
	 * he had here, pointing at the same account before.
	 */
	public function testARecipientsBillKeepsASharedAccountOnlyWhileItIsTheSameAndStillWritable(): void {
		$this->links->capture('bob');
		$this->links->clearUser('bob');

		$this->assertTrue($this->links->keepsReference('bill', 40, 'Netflix', self::CREATED, 'account_id', 10));
		$this->assertTrue($this->links->keepsReference('bill', 40, 'Netflix', self::CREATED, 'category_id', 20), 'A category needs only to be visible');
		$this->assertTrue($this->links->keepsSplitCategory(40, 'Netflix', self::CREATED, 20));
		$this->assertTrue($this->links->keepsReference('bill', 42, 'Into joint', self::CREATED, 'destination_account_id', 10));

		// A different bill that happens to have the old id
		$this->assertFalse($this->links->keepsReference('bill', 40, 'Spotify', self::CREATED, 'account_id', 10));
		$this->assertFalse($this->links->keepsReference('bill', 40, 'Netflix', '2026-02-02 10:00:00', 'account_id', 10));
		// Not what it pointed at before
		$this->assertFalse($this->links->keepsReference('bill', 40, 'Netflix', self::CREATED, 'account_id', 11));
		$this->assertFalse($this->links->keepsSplitCategory(40, 'Netflix', self::CREATED, 21));
		// His own account from before the restore is not someone else's
		$this->assertFalse($this->links->keepsReference('bill', 42, 'Into joint', self::CREATED, 'account_id', 50));
	}

	public function testAReadOnlyShareDoesNotKeepAnAccount(): void {
		$this->links->tables['budget_share_items'][1]['permission'] = 'read';
		$this->links->capture('bob');

		$this->assertFalse($this->links->keepsReference('bill', 40, 'Netflix', self::CREATED, 'account_id', 10));
		$this->assertTrue($this->links->keepsReference('bill', 40, 'Netflix', self::CREATED, 'category_id', 20));
	}

	public function testAShareThatIsNotAcceptedDoesNotKeepAnything(): void {
		$this->links->tables['budget_shares'][1]['status'] = 'pending';
		$this->links->capture('bob');

		$this->assertFalse($this->links->keepsReference('bill', 40, 'Netflix', self::CREATED, 'account_id', 10));
		$this->assertFalse($this->links->keepsReference('bill', 40, 'Netflix', self::CREATED, 'category_id', 20));
	}

	/**
	 * Bob's Mark Unpaid on a bill paid into Alice's account names rows the
	 * backup can't hold. They stay named only while they're the rows the
	 * bill named here, and still there.
	 */
	public function testARecipientsSnapshotKeepsRowsInTheSharedAccount(): void {
		$this->links->capture('bob');
		$this->links->clearUser('bob');

		$this->assertTrue($this->links->keepsSnapshotTransaction('bill', 40, 'Netflix', self::CREATED, 101));
		$this->assertTrue($this->links->keepsSnapshotTransaction('bill', 40, 'Netflix', self::CREATED, 100));
		$this->assertFalse($this->links->keepsSnapshotTransaction('bill', 40, 'Netflix', self::CREATED, 105), 'Not one the snapshot named');
		$this->assertFalse($this->links->keepsSnapshotTransaction('bill', 40, 'Spotify', self::CREATED, 101));

		unset($this->links->tables['budget_transactions'][101]);
		$this->assertFalse($this->links->keepsSnapshotTransaction('bill', 40, 'Netflix', self::CREATED, 101), 'Since deleted');
	}

	public function testFingerprintsNeedANameAndACreationTime(): void {
		$this->assertNull(\OCA\Budget\Service\CrossUserLinks::fingerprint('Rent', null));
		$this->assertNull(\OCA\Budget\Service\CrossUserLinks::fingerprint('', self::CREATED));
		$this->assertSame(
			\OCA\Budget\Service\CrossUserLinks::fingerprint('Rent', '2026-01-01 10:00:00'),
			\OCA\Budget\Service\CrossUserLinks::fingerprint(' Rent ', '2026-01-01T10:00:00+00:00')
		);
		$this->assertSame(
			\OCA\Budget\Service\CrossUserLinks::rowFingerprint('2026-09-01', '100.00', 'debit'),
			\OCA\Budget\Service\CrossUserLinks::rowFingerprint('2026-09-01 00:00:00', 100.0, 'debit')
		);
	}
}
