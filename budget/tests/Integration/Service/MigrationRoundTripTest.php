<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\AccountBalanceCalculator;
use OCA\Budget\Service\MigrationService;
use OCA\Budget\Service\SettingService;
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
	 * Import rules and saved reports name accounts, categories and tags by
	 * id inside JSON. The restore used to copy those ids as they were, and
	 * never read a rule's group back at all.
	 */
	public function testRulesAndSavedReportsPointAtTheRestoredRows(): void {
		$ids = $this->seedEveryTable($this->userId);
		$now = $this->now();
		$this->insertRow('budget_import_rules', [
			'user_id' => $this->userId, 'name' => 'Coffee', 'pattern' => '', 'field' => 'description',
			'match_type' => 'contains', 'priority' => 1, 'active' => true, 'created_at' => $now,
			'schema_version' => 2, 'group_name' => 'Eating out',
			'actions' => json_encode(['version' => 2, 'actions' => [
				['type' => 'set_category', 'value' => $ids['takeaway']],
				['type' => 'add_tags', 'value' => [$ids['tag']], 'behavior' => 'merge'],
			]]),
			'criteria' => json_encode(['version' => 2, 'root' => ['operator' => 'AND', 'conditions' => [
				['type' => 'condition', 'field' => 'account', 'matchType' => 'equals', 'pattern' => (string)$ids['current']],
			]]]),
		]);
		$this->insertRow('budget_saved_reports', [
			'user_id' => $this->userId, 'name' => 'Card only', 'created_at' => $now, 'updated_at' => $now,
			'config' => json_encode(['reportType' => 'summary', 'accountIds' => [$ids['card']], 'tagIds' => [$ids['tag']]]),
		]);
		$target = $this->newUserId();

		$this->migration->importAll($target, $this->migration->exportAll($this->userId)['content']);

		$idOf = fn (string $table, string $name): int => (int)$this->db()->executeQuery(
			'SELECT id FROM *PREFIX*' . $table . ' WHERE user_id = ? AND name = ?', [$target, $name]
		)->fetchOne();
		$tag = (int)$this->db()->executeQuery('SELECT id FROM *PREFIX*budget_tags WHERE user_id = ?', [$target])->fetchOne();

		$rule = $this->db()->executeQuery(
			'SELECT group_name, actions, criteria FROM *PREFIX*budget_import_rules WHERE user_id = ? AND name = ?', [$target, 'Coffee']
		)->fetch();
		$this->assertSame('Eating out', $rule['group_name']);
		$actions = json_decode($rule['actions'], true)['actions'];
		$this->assertSame($idOf('budget_categories', 'Takeaway'), $actions[0]['value']);
		$this->assertSame([$tag], $actions[1]['value']);
		$this->assertSame((string)$idOf('budget_accounts', 'Current'), json_decode($rule['criteria'], true)['root']['conditions'][0]['pattern']);

		$config = json_decode((string)$this->db()->executeQuery(
			'SELECT config FROM *PREFIX*budget_saved_reports WHERE user_id = ? AND name = ?', [$target, 'Card only']
		)->fetchOne(), true);
		$this->assertSame([$idOf('budget_accounts', 'Card')], $config['accountIds']);
		$this->assertSame([$tag], $config['tagIds']);
	}

	/**
	 * Bank connections stay through a restore, but every account comes back
	 * under a new id: a mapping left alone pointed at an account that no
	 * longer existed and bank sync silently stopped importing into it.
	 */
	public function testABankMappingFollowsItsAccountThroughARestore(): void {
		$ids = $this->seedEveryTable($this->userId);
		$archive = $this->migration->exportAll($this->userId)['content'];

		$this->migration->importAll($this->userId, $archive);

		$mapped = $this->db()->executeQuery(
			'SELECT a.name, a.user_id FROM *PREFIX*budget_bam m'
			. ' INNER JOIN *PREFIX*budget_bc c ON c.id = m.connection_id'
			. ' INNER JOIN *PREFIX*budget_accounts a ON a.id = m.budget_account_id'
			. ' WHERE c.user_id = ?',
			[$this->userId]
		)->fetchAll();
		$this->assertSame([['name' => 'Current', 'user_id' => $this->userId]], $mapped);
		// fetchAll() + array_column, not fetchFirstColumn(): NC 30's result
		// adapter doesn't have it
		$accountIds = array_column($this->db()->executeQuery(
			'SELECT id FROM *PREFIX*budget_accounts WHERE user_id = ?', [$this->userId]
		)->fetchAll(), 'id');
		$this->assertNotContains($ids['current'], array_map('intval', $accountIds), 'The account really came back under a new id');
	}

	/**
	 * A backup of a different account that happens to carry the same id (one
	 * from another server) must not inherit the bank feed: the mapping is
	 * unlinked instead, for the user to choose the account again.
	 */
	public function testABankMappingIsUnlinkedWhenTheBackupHoldsADifferentAccount(): void {
		$this->seedEveryTable($this->userId);
		$archive = $this->renameArchivedAccounts($this->migration->exportAll($this->userId)['content']);

		$this->migration->importAll($this->userId, $archive);

		$this->assertSame(1, $this->countRows('budget_bam', ['budget_account_id' => null]));
		$this->assertSame([], $this->danglingReferences());
	}

	private function renameArchivedAccounts(string $zipContent): string {
		$path = tempnam(sys_get_temp_dir(), 'budget-it-');
		file_put_contents($path, $zipContent);
		$zip = new \ZipArchive();
		$zip->open($path);
		$accounts = json_decode((string)$zip->getFromName('accounts.json'), true);
		foreach ($accounts as &$account) {
			$account['name'] .= ' (other server)';
		}
		unset($account);
		$zip->addFromString('accounts.json', json_encode($accounts));
		$zip->close();
		$content = (string)file_get_contents($path);
		unlink($path);
		return $content;
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
	 * The dashboard's tile filters, the Accounts tile's order and the muted
	 * budget alerts name accounts and categories by id, and a restore copied
	 * them as they were: on another server they named whatever held those
	 * ids there, on the same one nothing at all (R1-6).
	 */
	public function testDashboardAndAlertSettingsPointAtTheRestoredRows(): void {
		$current = $this->makeAccount(['name' => 'Current'])->getId();
		$card = $this->makeAccount(['name' => 'Card', 'type' => 'credit_card'])->getId();
		$groceries = $this->makeCategory(['name' => 'Groceries']);
		$dining = $this->makeCategory(['name' => 'Dining']);
		$settings = $this->service(SettingService::class);
		$settings->set($this->userId, 'dashboard_widgets_config', json_encode([
			'tileSettings' => ['spendingChart' => ['accountId' => (string)$card, 'hiddenCategories' => [$groceries]]],
			'settings' => ['accountsTile' => ['order' => [$card, $current], 'hidden' => [$current]]],
		]));
		$settings->set($this->userId, 'budget_alert_muted_categories', json_encode([$dining]));
		$target = $this->newUserId();

		$this->migration->importAll($target, $this->migration->exportAll($this->userId)['content']);

		$idOf = fn (string $table, string $name): int => (int)$this->db()->executeQuery(
			'SELECT id FROM *PREFIX*' . $table . ' WHERE user_id = ? AND name = ?', [$target, $name]
		)->fetchOne();
		$widgets = json_decode((string)$settings->get($target, 'dashboard_widgets_config'), true);
		$this->assertSame((string)$idOf('budget_accounts', 'Card'), $widgets['tileSettings']['spendingChart']['accountId']);
		$this->assertSame([$idOf('budget_categories', 'Groceries')], $widgets['tileSettings']['spendingChart']['hiddenCategories']);
		$this->assertSame([$idOf('budget_accounts', 'Card'), $idOf('budget_accounts', 'Current')], $widgets['settings']['accountsTile']['order']);
		$this->assertSame([$idOf('budget_accounts', 'Current')], $widgets['settings']['accountsTile']['hidden']);
		$this->assertSame([$idOf('budget_categories', 'Dining')], json_decode((string)$settings->get($target, 'budget_alert_muted_categories'), true));
	}

	/**
	 * "In credit" describes a liability's opening balance, not today's
	 * balance. The restore signed today's balance with it, so a card opened
	 * at 0 owed that an overpayment had put 40.22 in credit came back 40.22
	 * owed with an opening balance of -80.44 (T3-1), and a loan opened in
	 * credit that now owes came back in credit.
	 */
	public function testLiabilitiesComeBackOnTheSameSideWhicheverWayTheirLedgerWent(): void {
		$card = $this->makeAccount(['name' => 'Card', 'type' => 'credit_card', 'liabilityInCredit' => false])->getId();
		$this->makeTransaction($card, ['type' => 'credit', 'amount' => '100.00']);
		$this->makeTransaction($card, ['type' => 'debit', 'amount' => '59.78']);
		$loan = $this->makeAccount(['name' => 'Loan', 'type' => 'loan', 'openingBalance' => 10.0, 'liabilityInCredit' => true])->getId();
		$this->makeTransaction($loan, ['type' => 'debit', 'amount' => '30.00']);
		$mortgage = $this->makeAccount(['name' => 'Mortgage', 'type' => 'mortgage', 'openingBalance' => -1000.0])->getId();
		$this->makeTransaction($mortgage, ['type' => 'credit', 'amount' => '100.00']);
		$accounts = $this->service(AccountMapper::class);
		foreach ([$card, $loan, $mortgage] as $id) {
			$this->service(AccountBalanceCalculator::class)->recalculate($accounts->findById($id));
		}
		$target = $this->newUserId();

		$this->migration->importAll($target, $this->migration->exportAll($this->userId)['content']);

		$restored = [];
		foreach ($accounts->findAll($target) as $account) {
			$restored[$account->getName()] = [(float)$account->getOpeningBalance(), (float)$account->getBalance(), $account->getLiabilityInCredit()];
		}
		ksort($restored);
		$this->assertSame(['Card' => [0.0, 40.22, false], 'Loan' => [10.0, -20.0, true], 'Mortgage' => [-1000.0, -900.0, null]], $restored);
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
