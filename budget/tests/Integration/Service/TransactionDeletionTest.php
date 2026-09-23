<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Tests\Integration\FullDataset;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * No child table has a foreign key, so a transaction deleted without its
 * children orphans them for good: every cleanup query, factory reset's
 * included, finds child rows by joining back through budget_transactions
 * (#359). These delete through the real services and then look for rows
 * pointing at nothing.
 */
class TransactionDeletionTest extends IntegrationTestCase {
	use FullDataset;

	private const CHILD_TABLES = [
		'budget_tx_splits',
		'budget_transaction_tags',
		'budget_attachments',
		'budget_expense_shares',
	];

	private TransactionService $transactions;
	private int $accountId;
	private int $categoryId;

	protected function setUp(): void {
		parent::setUp();
		$this->transactions = $this->service(TransactionService::class);
		$this->accountId = $this->makeAccount()->getId();
		$this->categoryId = $this->makeCategory();
	}

	public function testDeletingATransactionRemovesEveryChildRowAndNothingElse(): void {
		$doomed = $this->makeTransaction($this->accountId, ['amount' => '10.00']);
		$doomedChildren = $this->hangChildrenOn($doomed, $this->categoryId);
		$kept = $this->makeTransaction($this->accountId, ['amount' => '10.00']);
		$keptChildren = $this->hangChildrenOn($kept, $this->categoryId);

		$this->transactions->delete($doomed, $this->userId);

		$this->assertNull($this->fetchRow('budget_transactions', $doomed));
		foreach ($doomedChildren as [$table, $id]) {
			$this->assertNull($this->fetchRow($table, $id), "{$table} row {$id} survived its transaction");
		}
		foreach ($keptChildren as [$table, $id]) {
			$this->assertNotNull($this->fetchRow($table, $id), "{$table} row {$id} of another transaction was deleted");
		}
		$this->assertNoOrphans();
	}

	public function testDeletingABillsScheduledPlaceholdersTakesTheirChildrenWithThem(): void {
		$bill = $this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Phone', 'amount' => '30.00', 'frequency' => 'monthly',
			'account_id' => $this->accountId, 'is_active' => true, 'created_at' => $this->now(),
		]);
		$future = date('Y-m-d', strtotime('+10 days'));
		$first = $this->makeTransaction($this->accountId, ['bill_id' => $bill, 'status' => 'scheduled', 'date' => $future]);
		$second = $this->makeTransaction($this->accountId, ['bill_id' => $bill, 'status' => 'scheduled', 'date' => $future]);
		$this->hangChildrenOn($first, $this->categoryId);
		$this->hangChildrenOn($second, $this->categoryId);
		// A cleared payment of the same bill is history, not a placeholder
		$paid = $this->makeTransaction($this->accountId, ['bill_id' => $bill, 'status' => 'cleared']);

		$this->transactions->deleteScheduledBillTransactions($bill);

		$this->assertNull($this->fetchRow('budget_transactions', $first));
		$this->assertNull($this->fetchRow('budget_transactions', $second));
		$this->assertNotNull($this->fetchRow('budget_transactions', $paid));
		$this->assertNoOrphans();
	}

	public function testDeletingATransferLegUnlinksItsPartner(): void {
		$savings = $this->makeAccount(['name' => 'Savings', 'type' => 'savings'])->getId();
		$out = $this->makeTransaction($this->accountId, ['amount' => '20.00']);
		$in = $this->makeTransaction($savings, ['amount' => '20.00', 'type' => 'credit', 'linked_transaction_id' => $out]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET linked_transaction_id = ? WHERE id = ?', [$in, $out]);

		$this->transactions->delete($out, $this->userId);

		$partner = $this->fetchRow('budget_transactions', $in);
		$this->assertNotNull($partner);
		$this->assertNull($partner['linked_transaction_id']);
	}

	public function testDeletingAPensionFundingLegKeepsTheContributionButDetachesIt(): void {
		$pension = $this->insertRow('budget_pensions', [
			'user_id' => $this->userId, 'name' => 'SIPP', 'type' => 'personal', 'currency' => 'GBP',
			'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);
		$contribution = $this->insertRow('budget_pen_contribs', [
			'user_id' => $this->userId, 'pension_id' => $pension, 'amount' => '100.00', 'date' => '2026-03-01',
			'created_at' => $this->now(), 'source_account_id' => $this->accountId,
		]);
		$leg = $this->makeTransaction($this->accountId, ['amount' => '100.00', 'pension_contrib_id' => $contribution]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_pen_contribs SET transaction_id = ? WHERE id = ?', [$leg, $contribution]);

		$this->transactions->delete($leg, $this->userId);

		$row = $this->fetchRow('budget_pen_contribs', $contribution);
		$this->assertNotNull($row, 'The pension contribution itself must survive');
		$this->assertNull($row['transaction_id']);
	}

	public function testFactoryResetLeavesNoOrphanedTransactionChildren(): void {
		$this->seedEveryTable($this->userId);

		$this->service(FactoryResetService::class)->executeFactoryReset($this->userId);

		foreach (['budget_tx_splits', 'budget_attachments', 'budget_expense_shares'] as $table) {
			$this->assertSame(0, $this->countOrphans($table, 'transaction_id'), "Factory reset orphaned {$table} rows");
		}
	}

	/**
	 * No table here has a foreign key, so nothing cascades: a reset that
	 * deleted transactions before their tag links (as it once did) left them
	 * pointing at deleted transactions and tags for good.
	 */
	public function testFactoryResetLeavesNoDanglingReferencesAnywhere(): void {
		$this->seedEveryTable($this->userId);

		$this->service(FactoryResetService::class)->executeFactoryReset($this->userId);

		$this->assertSame([], $this->danglingReferences());
	}

	/**
	 * "Delete ALL user data except audit logs", says FactoryResetService -
	 * everything a backup would restore, and also what no backup holds:
	 * shares the user granted, bank connections and their mappings (deleted
	 * without reading the stored credentials, which here are not even valid
	 * ciphertext), idempotency keys and the legacy forecasts table.
	 */
	public function testFactoryResetRemovesEverythingButTheAuditLog(): void {
		$this->seedEveryTable($this->userId);

		$this->service(FactoryResetService::class)->executeFactoryReset($this->userId);

		$survivors = array_filter(
			$this->countEveryTable($this->userId),
			static fn (int $n, string $table): bool => $n > 0 && $table !== 'budget_audit_log',
			ARRAY_FILTER_USE_BOTH
		);
		$this->assertSame([], $survivors, 'Tables that still hold the user\'s rows after a factory reset');
		$this->assertSame(1, $this->countUserRows('budget_audit_log', $this->userId), 'The audit log is kept for compliance');
	}

	/**
	 * A share another user granted TO this user is the other user's: their
	 * reset would revoke it, this user's must not.
	 */
	public function testFactoryResetKeepsSharesOtherUsersGrantedToTheUser(): void {
		$owner = $this->newUserId();
		$ownerAccount = $this->makeAccount(['name' => 'Joint'], $owner)->getId();
		$share = $this->insertRow('budget_shares', [
			'owner_user_id' => $owner, 'shared_with_user_id' => $this->userId, 'status' => 'accepted',
			'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);
		$item = $this->insertRow('budget_share_items', [
			'share_id' => $share, 'entity_type' => 'account', 'entity_id' => $ownerAccount, 'permission' => 'write',
			'created_at' => $this->now(), 'updated_at' => $this->now(),
		]);
		$this->seedEveryTable($this->userId);

		$this->service(FactoryResetService::class)->executeFactoryReset($this->userId);

		$this->assertNotNull($this->fetchRow('budget_shares', $share));
		$this->assertNotNull($this->fetchRow('budget_share_items', $item));
		$this->assertNotNull($this->fetchRow('budget_accounts', $ownerAccount));
	}

	private function assertNoOrphans(): void {
		foreach (self::CHILD_TABLES as $table) {
			$this->assertSame(0, $this->countOrphans($table, 'transaction_id'), "Orphaned rows in {$table}");
		}
	}
}
