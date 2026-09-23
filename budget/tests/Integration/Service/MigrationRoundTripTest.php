<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Tests\Integration\DataModel;
use OCA\Budget\Tests\Integration\FullDataset;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Backup export -> import, end to end against the real database.
 *
 * #351 lost tags, splits and about fifteen other kinds of data on every
 * migration for years, because a table nobody registered in the backup
 * registry is silently left out of the archive. The registry's own sanity
 * test checks ordering, not omissions. This one seeds a row in EVERY budget
 * table and counts them on the other side.
 */
class MigrationRoundTripTest extends IntegrationTestCase {
	use FullDataset;

	/** Written by exportAll() and read back by importAll() (the 6 bespoke types) */
	private const BESPOKE = [
		'budget_categories',
		'budget_accounts',
		'budget_transactions',
		'budget_bills',
		'budget_import_rules',
		'budget_settings',
	];

	/**
	 * Left out on purpose, per MigrationService's own docblock: instance state
	 * (audit log, idempotency keys, fetched exchange rates), bank-sync
	 * connections (credentials are instance-specific), shares (they name
	 * users on the old server), attachments (file ids do not survive) and a
	 * legacy table nothing reads.
	 */
	private const NOT_EXPORTED = [
		'budget_attachments',
		'budget_audit_log',
		'budget_bam',
		'budget_bc',
		'budget_exchange_rates',
		'budget_forecasts',
		'budget_idem_keys',
		'budget_share_auto',
		'budget_share_items',
		'budget_shares',
	];

	private MigrationService $migration;

	protected function setUp(): void {
		parent::setUp();
		$this->migration = $this->service(MigrationService::class);
	}

	public function testEveryBudgetTableIsEitherExportedOrDeliberatelyLeftOut(): void {
		$known = array_merge($this->exportedTables(), self::NOT_EXPORTED);
		sort($known);

		$this->assertSame(
			$known,
			$this->budgetTables(),
			'A budget table is neither in the backup registry (MigrationService::EXTRA_TABLES_PRE/_POST) '
			. 'nor listed as deliberately not exported. Register it, or add it to NOT_EXPORTED with a reason.'
		);
		$this->assertEqualsCanonicalizing(
			array_keys(DataModel::TABLE_SCOPES),
			$this->budgetTables(),
			'DataModel::TABLE_SCOPES must list every budget table, and FullDataset must seed it'
		);
	}

	public function testTheSeedCoversEveryTable(): void {
		$this->seedEveryTable($this->userId);

		$empty = array_keys(array_filter(
			$this->countEveryTable($this->userId),
			static fn (int $n): bool => $n === 0
		));

		$this->assertSame(['budget_exchange_rates'], $empty, 'FullDataset must put at least one row in every user table');
	}

	public function testRoundTripIntoAnotherUserKeepsEveryExportedRow(): void {
		$this->seedEveryTable($this->userId);
		$before = $this->countEveryTable($this->userId);
		$target = $this->newUserId();

		$archive = $this->migration->exportAll($this->userId)['content'];
		$this->migration->importAll($target, $archive);
		$after = $this->countEveryTable($target);

		foreach ($this->exportedTables() as $table) {
			$this->assertSame($before[$table], $after[$table], "Row count changed for {$table} across export/import");
		}
		foreach (self::NOT_EXPORTED as $table) {
			$this->assertSame(0, $after[$table], "{$table} is documented as not exported but arrived anyway");
		}
		$this->assertSame([], $this->danglingReferences(), 'An imported row points at an id that does not exist');
	}

	public function testRoundTripRestoresRelationshipsBetweenTheNewRows(): void {
		$this->seedEveryTable($this->userId);
		$target = $this->newUserId();

		$this->migration->importAll($target, $this->migration->exportAll($this->userId)['content']);

		// The transfer pair points at each other
		$pairs = $this->db()->executeQuery(
			'SELECT t.id, t.linked_transaction_id FROM *PREFIX*budget_transactions t'
			. ' INNER JOIN *PREFIX*budget_accounts a ON a.id = t.account_id'
			. ' WHERE a.user_id = ? AND t.linked_transaction_id IS NOT NULL',
			[$target]
		)->fetchAll();
		$this->assertCount(2, $pairs);
		$links = array_column($pairs, 'linked_transaction_id', 'id');
		foreach ($links as $id => $partner) {
			$this->assertSame((int)$id, (int)$links[(int)$partner], 'Transfer legs must link to each other');
		}

		// The split parent keeps its parts and its flag
		$split = $this->db()->executeQuery(
			'SELECT t.id, t.is_split, COUNT(s.id) AS parts FROM *PREFIX*budget_transactions t'
			. ' INNER JOIN *PREFIX*budget_accounts a ON a.id = t.account_id'
			. ' INNER JOIN *PREFIX*budget_tx_splits s ON s.transaction_id = t.id'
			. ' WHERE a.user_id = ? GROUP BY t.id, t.is_split',
			[$target]
		)->fetchAll();
		$this->assertCount(1, $split);
		$this->assertSame(2, (int)$split[0]['parts']);
		$this->assertTrue((bool)$split[0]['is_split']);

		// Bill, reconciliation session and pension contribution references
		// resolve to the target user's own rows
		$refs = $this->db()->executeQuery(
			'SELECT b.user_id AS bill_owner, r.user_id AS recon_owner FROM *PREFIX*budget_transactions t'
			. ' INNER JOIN *PREFIX*budget_accounts a ON a.id = t.account_id'
			. ' INNER JOIN *PREFIX*budget_bills b ON b.id = t.bill_id'
			. ' INNER JOIN *PREFIX*budget_recon_sessions r ON r.id = t.recon_session_id'
			. ' WHERE a.user_id = ?',
			[$target]
		)->fetchAll();
		$this->assertSame([['bill_owner' => $target, 'recon_owner' => $target]], $refs);
		$pension = $this->db()->executeQuery(
			'SELECT pc.user_id FROM *PREFIX*budget_transactions t'
			. ' INNER JOIN *PREFIX*budget_accounts a ON a.id = t.account_id'
			. ' INNER JOIN *PREFIX*budget_pen_contribs pc ON pc.id = t.pension_contrib_id'
			. ' WHERE a.user_id = ?',
			[$target]
		)->fetchOne();
		$this->assertSame($target, $pension);
	}

	public function testRestoringOverExistingDataReplacesItRowForRow(): void {
		$this->seedEveryTable($this->userId);
		$before = $this->countEveryTable($this->userId);

		$this->migration->importAll($this->userId, $this->migration->exportAll($this->userId)['content']);
		$after = $this->countEveryTable($this->userId);

		foreach ($this->exportedTables() as $table) {
			$this->assertSame($before[$table], $after[$table], "Restoring over the same user changed the row count of {$table}");
		}
	}

	/**
	 * importAll() wipes the user's transactions through the mapper directly
	 * (MigrationService::clearUserData), not TransactionService::
	 * deleteWithChildren(). Splits and transaction tags are registry tables
	 * and get cleared first, but attachments are not in
	 * the registry, so every attachment row is orphaned by a restore - the
	 * exact leak #359 closed for ordinary deletes.
	 */
	#[Group('known-bug')]
	public function testRestoringOverExistingDataLeavesNoOrphans(): void {
		$this->seedEveryTable($this->userId);

		$this->migration->importAll($this->userId, $this->migration->exportAll($this->userId)['content']);

		$this->assertSame(0, $this->countOrphans('budget_attachments', 'transaction_id'), 'budget_attachments rows orphaned by a restore');
		$this->assertSame([], $this->danglingReferences());
	}

	/**
	 * importAccounts() copies the account columns one by one and several are
	 * missing from its list, so a restore silently resets them.
	 */
	#[Group('known-bug')]
	public function testAccountSettingsSurviveTheRoundTrip(): void {
		$source = $this->makeAccount([
			'name' => 'Savings',
			'type' => 'savings',
			'walletAddress' => 'bc1qexampleaddress',
			'interestEnabled' => true,
			'interestRate' => 4.25,
			'compoundingFrequency' => 'monthly',
			'lastReconciled' => '2026-03-31',
			'statementDay' => 12,
		]);
		$target = $this->newUserId();

		$this->migration->importAll($target, $this->migration->exportAll($this->userId)['content']);

		$restored = $this->service(AccountMapper::class)->findAll($target);
		$this->assertCount(1, $restored);
		$ignore = ['id' => 0, 'userId' => 0, 'openingBalance' => 0, 'updatedAt' => 0];
		$this->assertEquals(
			array_diff_key($source->toArrayFull(), $ignore),
			array_diff_key($restored[0]->toArrayFull(), $ignore)
		);
	}

	/**
	 * @return string[] tables written to and read back from the archive
	 */
	private function exportedTables(): array {
		$registry = new \ReflectionClass(MigrationService::class);
		$tables = self::BESPOKE;
		foreach (['EXTRA_TABLES_PRE', 'EXTRA_TABLES_POST'] as $constant) {
			foreach ($registry->getConstant($constant) as $spec) {
				$tables[] = $spec['table'];
			}
		}
		sort($tables);
		return $tables;
	}
}
