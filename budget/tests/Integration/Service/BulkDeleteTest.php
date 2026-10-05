<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\TransactionService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Bulk delete runs a chunk of rows per database transaction (T6-5), against
 * the real database: an id the user can't see fails only itself, and a chunk
 * the database aborts (on PostgreSQL every statement after a failed one is
 * refused) is redone row by row with the same result.
 */
class BulkDeleteTest extends IntegrationTestCase {
	private const CHILD_TABLES = ['budget_tx_splits', 'budget_transaction_tags', 'budget_attachments', 'budget_expense_shares'];

	public function testDeletesTheUsersRowsWithTheirChildrenAndOnlyFailsWhatTheyCanNotSee(): void {
		$account = $this->makeAccount(['balance' => 0.0])->getId();
		$category = $this->makeCategory();
		$rows = [];
		$children = [];
		foreach (['10.00', '20.00', '30.00'] as $amount) {
			$id = $this->makeTransaction($account, ['amount' => $amount]);
			$rows[] = $id;
			$children = array_merge($children, $this->hangChildrenOn($id, $category));
		}
		$kept = $this->makeTransaction($account, ['amount' => '5.00']);
		$othersAccount = $this->makeAccount(['name' => 'Not yours'], $this->newUserId())->getId();
		$othersRow = $this->makeTransaction($othersAccount);

		$result = $this->service(TransactionService::class)
			->bulkDelete($this->userId, [$rows[0], $othersRow, $rows[1], 999999999, $rows[2]]);

		$this->assertSame(3, $result['success']);
		$this->assertSame(2, $result['failed']);
		$this->assertSame([$othersRow, 999999999], array_column($result['errors'], 'id'));
		foreach ($rows as $id) {
			$this->assertNull($this->fetchRow('budget_transactions', $id));
		}
		foreach ($children as [$table, $id]) {
			$this->assertNull($this->fetchRow($table, $id), "$table row $id survived its transaction");
		}
		$this->assertNotNull($this->fetchRow('budget_transactions', $kept));
		$this->assertNotNull($this->fetchRow('budget_transactions', $othersRow));
		// Recomputed from what is left: the 5.00 debit
		$this->assertEqualsWithDelta(-5.0, (float)$this->fetchRow('budget_accounts', $account)['balance'], 0.001);
		foreach (self::CHILD_TABLES as $table) {
			$this->assertSame(0, $this->countOrphans($table, 'transaction_id'), "$table orphans");
		}
	}

	/**
	 * A bank-synced row whose import id was dismissed already: recording
	 * the dismissal again fails on the unique index and is ignored. On
	 * PostgreSQL that failure aborts the chunk's transaction, which must
	 * not lose or miscount anything.
	 */
	public function testAChunkTheDatabaseAbortsIsRedoneWithTheSameResult(): void {
		$account = $this->makeAccount()->getId();
		$synced = $this->makeTransaction($account, ['import_id' => 'simplefin:tx-1']);
		$this->insertRow('budget_dismiss_imp', [
			'account_id' => $account, 'import_id' => 'simplefin:tx-1', 'dismissed_at' => $this->now(),
		]);
		$plain = $this->makeTransaction($account);
		$another = $this->makeTransaction($account, ['import_id' => 'simplefin:tx-2']);

		$result = $this->service(TransactionService::class)->bulkDelete($this->userId, [$synced, $plain, $another]);

		$this->assertSame(['success' => 3, 'failed' => 0, 'errors' => []], $result);
		foreach ([$synced, $plain, $another] as $id) {
			$this->assertNull($this->fetchRow('budget_transactions', $id));
		}
		$this->assertSame(1, $this->countRows('budget_dismiss_imp', ['account_id' => $account, 'import_id' => 'simplefin:tx-1']));
		$this->assertSame(1, $this->countRows('budget_dismiss_imp', ['account_id' => $account, 'import_id' => 'simplefin:tx-2']));
		$this->assertFalse($this->db()->inTransaction());
	}
}
