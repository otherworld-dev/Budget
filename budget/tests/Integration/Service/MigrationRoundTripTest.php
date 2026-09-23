<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Tests\Integration\DataModel;
use OCA\Budget\Tests\Integration\FullDataset;
use OCA\Budget\Tests\Integration\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

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
	 * deleteWithChildren(). Attachments are not in the registry, so they
	 * need their own clear or every one is orphaned by a restore - the exact
	 * leak #359 closed for ordinary deletes.
	 */
	public function testRestoringOverExistingDataLeavesNoOrphans(): void {
		$this->seedEveryTable($this->userId);

		$this->migration->importAll($this->userId, $this->migration->exportAll($this->userId)['content']);

		$this->assertSame(0, $this->countOrphans('budget_attachments', 'transaction_id'), 'budget_attachments rows orphaned by a restore');
		$this->assertSame([], $this->danglingReferences());
	}

	/**
	 * Bank connections, their mappings, shares the user granted and API
	 * idempotency keys are not in a backup, so a restore must leave them
	 * alone - clearing them would destroy them for good. Only a factory
	 * reset removes them.
	 */
	public function testRestoringOverExistingDataKeepsWhatNoBackupHolds(): void {
		$this->seedEveryTable($this->userId);
		$kept = ['budget_bc', 'budget_bam', 'budget_shares', 'budget_share_items', 'budget_share_auto', 'budget_idem_keys'];
		$before = array_map(fn (string $table) => $this->countUserRows($table, $this->userId), array_combine($kept, $kept));

		$this->migration->importAll($this->userId, $this->migration->exportAll($this->userId)['content']);

		foreach ($kept as $table) {
			$this->assertGreaterThan(0, $before[$table], "The seed must put a row in {$table}");
			$this->assertSame($before[$table], $this->countUserRows($table, $this->userId), "A restore deleted {$table} rows");
		}
	}

	/**
	 * The table-level import used to bind every value as a string, so a
	 * boolean false reached PostgreSQL as '' and any backup holding a tag
	 * failed to restore there. The archive also carries booleans in whatever
	 * form the source database returned them - true/false from PostgreSQL,
	 * 0/1 (numbers or strings) from SQLite and MySQL - and each must restore
	 * to the same value on every database.
	 */
	#[DataProvider('booleanForms')]
	public function testRestoreKeepsBooleansWhateverFormTheArchiveHoldsThem(string $form): void {
		$food = $this->makeCategory(['name' => 'Food']);
		$set = $this->makeTagSet($food);
		$hiddenTag = $this->makeTag($set);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_tags SET name = ? WHERE id = ?', ['Hidden', $hiddenTag]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_tags SET hidden = ? WHERE id = ?', [true, $hiddenTag], [\OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT]);
		$this->makeTag($set);
		$this->insertRow('budget_recurring_income', [
			'user_id' => $this->userId, 'name' => 'Old job', 'amount' => '100.00', 'frequency' => 'monthly',
			'created_at' => $this->now(), 'is_active' => false,
		]);
		$target = $this->newUserId();

		$archive = $this->rewriteArchiveBooleans($this->migration->exportAll($this->userId)['content'], $form);
		$this->migration->importAll($target, $archive);

		$tags = $this->db()->executeQuery(
			'SELECT name, hidden FROM *PREFIX*budget_tags WHERE user_id = ? ORDER BY name', [$target]
		)->fetchAll();
		$this->assertSame(['Hidden', 'Tesco'], array_column($tags, 'name'));
		$this->assertTrue((bool)$tags[0]['hidden']);
		$this->assertFalse((bool)$tags[1]['hidden']);
		$active = $this->db()->executeQuery(
			'SELECT is_active FROM *PREFIX*budget_recurring_income WHERE user_id = ?', [$target]
		)->fetchOne();
		$this->assertFalse((bool)$active);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function booleanForms(): array {
		return [
			'as this database exported them' => ['native'],
			'JSON booleans (PostgreSQL)' => ['bool'],
			'digit strings (SQLite, MySQL)' => ['digits'],
			'integers' => ['int'],
		];
	}

	/**
	 * importAccounts() used to copy the account columns one by one and
	 * several were missing from its list, so a restore silently reset them.
	 */
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
	 * Rewrite every boolean-looking value in the archive's table-level files
	 * (the known boolean columns of the registry tables) into $form.
	 */
	private function rewriteArchiveBooleans(string $zipContent, string $form): string {
		if ($form === 'native') {
			return $zipContent;
		}
		$booleanColumns = ['hidden', 'is_active'];
		$path = tempnam(sys_get_temp_dir(), 'budget-it-');
		file_put_contents($path, $zipContent);
		$zip = new \ZipArchive();
		$zip->open($path);
		foreach (['tags.json', 'recurring_income.json'] as $file) {
			$rows = json_decode((string)$zip->getFromName($file), true);
			foreach ($rows as &$row) {
				foreach ($booleanColumns as $column) {
					if (!array_key_exists($column, $row) || $row[$column] === null) {
						continue;
					}
					$truthy = filter_var($row[$column], FILTER_VALIDATE_BOOLEAN);
					$row[$column] = match ($form) {
						'bool' => $truthy,
						'digits' => $truthy ? '1' : '0',
						'int' => $truthy ? 1 : 0,
					};
				}
			}
			unset($row);
			$zip->addFromString($file, json_encode($rows));
		}
		$zip->close();
		$content = (string)file_get_contents($path);
		unlink($path);
		return $content;
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
