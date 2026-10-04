<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\LegacyBillRows;
use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\ShareItem;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Enum\AccountType;
use OCA\Budget\Enum\Currency;
use OCA\Budget\Migration\Version001000104Date20260916;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Service for exporting and importing all user data for migration between instances.
 */
class MigrationService {
	/**
	 * The archive format. Bump the minor version whenever the archive gains
	 * columns or files: 2.54's importer writes every key it finds into its
	 * tables, so a newer archive fails there with SQL errors, and only a
	 * newer version number makes its preview warn first. 1.3.0 added the
	 * pension post undo state and anchor date, the recurring income receipt
	 * undo state and the category creator.
	 */
	public const EXPORT_VERSION = '1.3.0';
	private const APP_ID = 'budget';

	/**
	 * Uncompressed size limits for a backup archive. A zip of a few KB can
	 * inflate to gigabytes, and every entry used to be read whole into
	 * memory. A real backup's largest file (transactions) is a few MB per
	 * ten thousand rows, so these leave generous room.
	 */
	public const MAX_ENTRY_BYTES = 200 * 1024 * 1024;
	public const MAX_TOTAL_BYTES = 500 * 1024 * 1024;

	/**
	 * Table-level round-trip specs (#351): everything beyond the five bespoke
	 * entity types (categories, accounts, transactions, bills, import rules)
	 * and settings. Each entry becomes <key>.json in the archive. Keys:
	 *   table   physical table name (without the oc_ prefix)
	 *   scope   'user' (has a user_id column), or ['joins' => [[table,
	 *           localColumn], …]] — a chain ending at a table with user_id
	 *   idMap   record this table's old => new ids under this key
	 *   fk      [column => ['map' => idMapKey, 'onMissing' => 'null'|'drop',
	 *           'shared' => keep an id another user shares with this one,
	 *           while CrossUserLinks::keepsReference() allows it]]
	 *   entity  the share item type of the table's rows, for 'shared'
	 *   jsonFk  [column => ['map' => idMapKey,
	 *           'shape' => 'idList'|'idKeyedObject'|'idValuedObject']]
	 *   undoSnapshot  ['column' => JSON column, 'required' => key a valid
	 *           snapshot has, 'idLists' => keys holding transaction id lists,
	 *           'accounts' => the row's account columns]: see
	 *           remapUndoSnapshot()
	 *   jsonKeyFk  [JSON column => [key => ['map' => idMapKey, 'shared' =>
	 *           share item type of ids another user may share with this
	 *           one]]]: id lists under keys of a JSON object; an id that
	 *           didn't come back is left out, see remapJsonKeyIds()
	 *   snapshotRefs  [JSON column => [key => idMapKey]]: an undo snapshot
	 *           naming one row by id, moved to its restored id, or dropped
	 *           whole when that row didn't come back: see remapSnapshotRefs()
	 *   billKeyedType  dismissals of this suggestion_type are keyed by a
	 *           bill id: see remapBillKeyedDismissal()
	 *   userLink  a column naming a Nextcloud user the row is linked to,
	 *           kept only when the restoring user could link it today
	 *           (ContactLinkPolicy)
	 *
	 * PRE entries import after categories/accounts, before transactions and
	 * bills (bills remap their tagIds through the tags map). POST entries
	 * import after bills. Order within each phase is dependency order.
	 *
	 * Restore and factory reset both clear a user's data from this list
	 * (UserTableCleaner), so a table registered here is also wiped by both.
	 *
	 * Deliberately NOT exported: audit log and idempotency keys (instance
	 * state), bank-sync connections/mappings (provider agreements and
	 * credentials are instance-specific; a restore keeps them and moves the
	 * mappings to the restored accounts, see bankMappingTargets()), shares (reference other Nextcloud
	 * users), attachments (file ids do not survive), fetched exchange-rate
	 * cache (manual rates ARE exported), legacy tables nothing reads.
	 */
	public const EXTRA_TABLES_PRE = [
		'tag_sets' => [
			'table' => 'budget_tag_sets',
			'scope' => ['joins' => [['budget_categories', 'category_id']]],
			'idMap' => 'tag_sets',
			'fk' => ['category_id' => ['map' => 'categories', 'onMissing' => 'drop']],
		],
		'tags' => [
			'table' => 'budget_tags',
			'scope' => 'user',
			'idMap' => 'tags',
			'fk' => ['tag_set_id' => ['map' => 'tag_sets', 'onMissing' => 'null']],
		],
	];

	public const EXTRA_TABLES_POST = [
		'transaction_tags' => [
			'table' => 'budget_transaction_tags',
			'scope' => ['joins' => [['budget_transactions', 'transaction_id'], ['budget_accounts', 'account_id']]],
			'fk' => [
				'transaction_id' => ['map' => 'transactions', 'onMissing' => 'drop'],
				'tag_id' => ['map' => 'tags', 'onMissing' => 'drop'],
			],
		],
		'tx_splits' => [
			'table' => 'budget_tx_splits',
			'scope' => ['joins' => [['budget_transactions', 'transaction_id'], ['budget_accounts', 'account_id']]],
			'fk' => [
				'transaction_id' => ['map' => 'transactions', 'onMissing' => 'drop'],
				'category_id' => ['map' => 'categories', 'onMissing' => 'null'],
			],
		],
		'recurring_income' => [
			'table' => 'budget_recurring_income',
			'scope' => 'user',
			// Share items follow it to its new id (CrossUserLinks)
			'idMap' => 'recurring_income',
			'entity' => ShareItem::TYPE_RECURRING_INCOME,
			'fk' => [
				// An account or category shared with the user is kept while it
				// still is (CrossUserLinks::keepsReference())
				'account_id' => ['map' => 'accounts', 'onMissing' => 'null', 'shared' => true],
				'category_id' => ['map' => 'categories', 'onMissing' => 'null', 'shared' => true],
			],
			// Mark Unreceived's snapshot names the credit it booked
			'undoSnapshot' => [
				'column' => 'received_undo_state',
				'required' => 'nextExpectedDate',
				'idLists' => ['transactionIds'],
				'accounts' => ['account_id'],
			],
		],
		'savings_goals' => [
			'table' => 'budget_savings_goals',
			'scope' => 'user',
			// Share items follow it to its new id (CrossUserLinks)
			'idMap' => 'savings_goals',
			'fk' => [
				'account_id' => ['map' => 'accounts', 'onMissing' => 'null'],
				'tag_id' => ['map' => 'tags', 'onMissing' => 'null'],
			],
		],
		'assets' => [
			'table' => 'budget_assets',
			'scope' => 'user',
			'idMap' => 'assets',
		],
		'asset_snaps' => [
			'table' => 'budget_asset_snaps',
			'scope' => 'user',
			'fk' => ['asset_id' => ['map' => 'assets', 'onMissing' => 'drop']],
		],
		'pensions' => [
			'table' => 'budget_pensions',
			'scope' => 'user',
			'idMap' => 'pensions',
		],
		'pen_contribs' => [
			'table' => 'budget_pen_contribs',
			'scope' => 'user',
			'idMap' => 'pen_contribs',
			'fk' => [
				'pension_id' => ['map' => 'pensions', 'onMissing' => 'drop'],
				'transaction_id' => ['map' => 'transactions', 'onMissing' => 'null'],
				'source_account_id' => ['map' => 'accounts', 'onMissing' => 'null'],
			],
		],
		'pen_recur' => [
			'table' => 'budget_pen_recur',
			'scope' => 'user',
			'fk' => [
				'pension_id' => ['map' => 'pensions', 'onMissing' => 'drop'],
				'source_account_id' => ['map' => 'accounts', 'onMissing' => 'null'],
			],
			// Undo of the last Post now deletes the contribution it names
			'snapshotRefs' => ['post_undo_state' => ['contributionId' => 'pen_contribs']],
		],
		'pen_snaps' => [
			'table' => 'budget_pen_snaps',
			'scope' => 'user',
			'fk' => ['pension_id' => ['map' => 'pensions', 'onMissing' => 'drop']],
		],
		'interest_rates' => [
			'table' => 'budget_interest_rates',
			'scope' => 'user',
			'fk' => ['account_id' => ['map' => 'accounts', 'onMissing' => 'drop']],
		],
		'manual_rates' => [
			'table' => 'budget_manual_rates',
			'scope' => 'user',
		],
		'import_templates' => [
			'table' => 'budget_import_templates',
			'scope' => 'user',
			'fk' => ['account_id' => ['map' => 'accounts', 'onMissing' => 'null']],
			'jsonFk' => ['account_mapping' => ['map' => 'accounts', 'shape' => 'idValuedObject']],
		],
		'imp_links' => [
			'table' => 'budget_imp_links',
			'scope' => 'user',
			'fk' => ['budget_account_id' => ['map' => 'accounts', 'onMissing' => 'drop']],
		],
		'saved_reports' => [
			'table' => 'budget_saved_reports',
			'scope' => 'user',
			// The report's account and tag filters (ReportsModule's
			// getReportConfig())
			'jsonKeyFk' => ['config' => [
				'accountIds' => ['map' => 'accounts', 'shared' => ShareItem::TYPE_ACCOUNT],
				'tagIds' => ['map' => 'tags'],
			]],
		],
		'nw_snaps' => [
			'table' => 'budget_nw_snaps',
			'scope' => 'user',
		],
		'bgt_snapshots' => [
			'table' => 'budget_bgt_snapshots',
			'scope' => 'user',
			'fk' => ['category_id' => ['map' => 'categories', 'onMissing' => 'drop']],
		],
		'recon_sessions' => [
			'table' => 'budget_recon_sessions',
			'scope' => 'user',
			'idMap' => 'recon_sessions',
			'fk' => ['account_id' => ['map' => 'accounts', 'onMissing' => 'drop']],
		],
		'dscn' => [
			'table' => 'budget_dscn',
			'scope' => 'user',
			'jsonFk' => [
				'selected_debt_ids' => ['map' => 'accounts', 'shape' => 'idList'],
				'rate_overrides' => ['map' => 'accounts', 'shape' => 'idKeyedObject'],
			],
		],
		'cat_mutes' => [
			'table' => 'budget_cat_mutes',
			'scope' => 'user',
			'fk' => ['category_id' => ['map' => 'categories', 'onMissing' => 'drop']],
		],
		'contacts' => [
			'table' => 'budget_contacts',
			'scope' => 'user',
			'idMap' => 'contacts',
			// A linked contact's shared expenses reach that user
			'userLink' => 'nextcloud_user_id',
		],
		'expense_shares' => [
			'table' => 'budget_expense_shares',
			'scope' => 'user',
			'fk' => [
				'transaction_id' => ['map' => 'transactions', 'onMissing' => 'drop'],
				'contact_id' => ['map' => 'contacts', 'onMissing' => 'drop'],
			],
		],
		'settlements' => [
			'table' => 'budget_settlements',
			'scope' => 'user',
			'fk' => ['contact_id' => ['map' => 'contacts', 'onMissing' => 'drop']],
		],
		'dismissed_sugg' => [
			'table' => 'budget_dismissed_sugg',
			'scope' => 'user',
			// A dismissed unrecorded payment (#394) is keyed by its bill's id
			'billKeyedType' => 'unrecorded',
		],
		'dismiss_imp' => [
			'table' => 'budget_dismiss_imp',
			'scope' => ['joins' => [['budget_accounts', 'account_id']]],
			'fk' => ['account_id' => ['map' => 'accounts', 'onMissing' => 'drop']],
		],
		// Project budgets (#391). Allocations carry their own user_id so
		// clearTable() can find them after budget_projects is cleared.
		'projects' => [
			'table' => 'budget_projects',
			'scope' => 'user',
			'idMap' => 'projects',
			'fk' => ['category_id' => ['map' => 'categories', 'onMissing' => 'drop']],
		],
		'project_allocs' => [
			'table' => 'budget_project_allocs',
			'scope' => 'user',
			'fk' => [
				'project_id' => ['map' => 'projects', 'onMissing' => 'drop'],
				'category_id' => ['map' => 'categories', 'onMissing' => 'drop'],
			],
		],
	];

	private UserTableCleaner $tableCleaner;

	/** @var array<string, array<string, 'bool'|'int'|'string'>|null> table => column bindings, per restore */
	private array $bindingCache = [];

	/** Restored bills that lost an account they used, per restore */
	private int $billsDetached = 0;

	/** Restored contacts whose link to a Nextcloud user was removed, per restore */
	private int $userLinksRemoved = 0;

	public function __construct(
		private AccountMapper $accountMapper,
		private TransactionMapper $transactionMapper,
		private CategoryMapper $categoryMapper,
		private BillMapper $billMapper,
		private ImportRuleMapper $importRuleMapper,
		private SettingMapper $settingMapper,
		private IDBConnection $db,
		private ?IL10N $l = null,
		private ?SchemaProbe $schemaProbe = null,
		// Always wired through DI; without it a restore leaves links to
		// other users' data out, as it did before
		private ?CrossUserLinks $crossUserLinks = null,
		// Always wired through DI; without it no contact keeps its link to
		// a Nextcloud user
		private ?ContactLinkPolicy $contactLinks = null,
	) {
		$this->tableCleaner = new UserTableCleaner($db);
	}

	/** Translate when a translator is wired (always, through DI). */
	private function t(string $text, array $parameters = []): string {
		return $this->l !== null ? $this->l->t($text, $parameters) : vsprintf($text, $parameters);
	}

	/** Translate a count, as t() does */
	private function n(string $singular, string $plural, int $count): string {
		return $this->l !== null
			? $this->l->n($singular, $plural, $count)
			: str_replace('%n', (string)$count, $count === 1 ? $singular : $plural);
	}

	/**
	 * Rows read per query, and per write, while exporting. The export used to
	 * load every transaction as an entity, then encode each data set whole,
	 * pretty-printed, and build the zip as one string: a ledger of about
	 * 110,000 transactions ran out of a 512 MB memory limit, so no backup
	 * could be made at all.
	 */
	private const EXPORT_BATCH = 1000;

	/**
	 * Export all user data as a ZIP archive, returned as a string.
	 *
	 * @return array{content: string, filename: string, contentType: string}
	 */
	public function exportAll(string $userId): array {
		$export = $this->exportToFile($userId);
		try {
			$content = file_get_contents($export['path']);
		} finally {
			@unlink($export['path']);
		}
		if ($content === false) {
			throw new \RuntimeException('Failed to read the export archive');
		}

		return [
			'content' => $content,
			'filename' => $export['filename'],
			'contentType' => $export['contentType'],
		];
	}

	/**
	 * Export all user data as a ZIP archive in a temporary file, which the
	 * caller must delete.
	 *
	 * Memory stays flat whatever the size of the ledger: each data set is
	 * read a batch of rows at a time and written straight to its own
	 * temporary JSON file, and the zip is put together from those files. The
	 * archive holds the same files with the same JSON as before, only not
	 * pretty-printed, so every restore that read the old one reads this.
	 *
	 * @return array{path: string, filename: string, contentType: string}
	 */
	public function exportToFile(string $userId): array {
		$parts = [];
		$counts = [];
		$zipPath = null;
		try {
			$writeList = function (string $key, iterable $rows) use (&$parts, &$counts): void {
				$parts[$key] = self::tempPath();
				$counts[$key] = self::writeJsonList($parts[$key], $rows);
			};

			$writeList('categories', self::mapEach($this->categoryMapper->findAll($userId), fn (Category $c) => $c->jsonSerialize()));
			// Accounts with full (decrypted) data
			$writeList('accounts', self::mapEach($this->accountMapper->findAll($userId), fn (Account $a) => $a->toArrayFull()));
			$writeList('transactions', $this->exportTransactionRows($userId));
			// The undo snapshot of the last payment stays out of the API's
			// JSON, so the backup adds it: without it no restored bill could
			// be marked unpaid (#365).
			$writeList('bills', self::mapEach($this->billMapper->findAll($userId), fn (Bill $b) => $b->jsonSerialize() + [
				'paidUndoState' => self::decodeJsonColumn($b->getPaidUndoState()),
			]));
			$writeList('import_rules', self::mapEach($this->importRuleMapper->findAll($userId), fn (ImportRule $r) => $r->jsonSerialize()));

			// Settings are one JSON object of key => value
			$settings = [];
			foreach ($this->settingMapper->findAll($userId) as $setting) {
				$settings[$setting->getKey()] = $setting->getValue();
			}
			$parts['settings'] = self::tempPath();
			self::writeFile($parts['settings'], self::encodeJson($settings));
			$counts['settings'] = count($settings);

			// Everything else round-trips at the table level (#351)
			foreach (self::EXTRA_TABLES_PRE + self::EXTRA_TABLES_POST as $key => $spec) {
				$writeList($key, $this->exportTableRows($userId, $spec));
			}

			$manifestPath = self::tempPath();
			$parts = ['manifest' => $manifestPath] + $parts;
			self::writeFile($manifestPath, self::encodeJson([
				'version' => self::EXPORT_VERSION,
				'appId' => self::APP_ID,
				'exportedAt' => date('c'),
				'counts' => $counts,
				// Receipt attachments reference files in the user's Files space by
				// instance-specific fileId — the files themselves are not part of
				// this archive, and attachment links are not restored on import.
				'attachmentsNote' => 'Receipt files are not included; file references do not survive export/import.',
				'excluded' => 'Not exported: audit log and idempotency keys (instance state), bank-sync connections (provider agreements and credentials are instance-specific and must be re-established), shares (reference users on the old server), receipt attachments (see attachmentsNote), fetched exchange-rate cache (manual rates are included).',
			]));

			$zipPath = self::tempPath();
			$zip = new \ZipArchive();
			if ($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
				throw new \RuntimeException('Failed to create ZIP archive');
			}
			// One JSON file per data set, manifest first. Each is read from
			// its file when the archive is written out.
			foreach ($parts as $key => $path) {
				if (!$zip->addFile($path, $key . '.json')) {
					$zip->close();
					throw new \RuntimeException('Failed to create ZIP archive');
				}
			}
			if (!$zip->close()) {
				throw new \RuntimeException('Failed to create ZIP archive');
			}

			$result = [
				'path' => $zipPath,
				'filename' => 'budget_export_' . date('Y-m-d_His') . '.zip',
				'contentType' => 'application/zip',
			];
			$zipPath = null;
			return $result;
		} finally {
			foreach ($parts as $path) {
				@unlink($path);
			}
			if ($zipPath !== null) {
				@unlink($zipPath);
			}
		}
	}

	/**
	 * Import data from a ZIP archive.
	 * This performs a full replacement of existing data.
	 *
	 * @param string $zipContent The raw ZIP file content
	 * @return array{success: bool, message: string, counts: array, warnings: string[]}
	 */
	public function importAll(string $userId, string $zipContent): array {
		$importData = $this->parseZipArchive($zipContent);

		$this->validateImportData($importData);

		// Use transaction to ensure atomicity
		$this->db->beginTransaction();

		try {
			// What links this user's data to other users' (shares, bills on
			// shared accounts), read before it is deleted
			$this->crossUserLinks?->capture($userId);
			$this->billsDetached = 0;
			$this->userLinksRemoved = 0;
			// Bank connections stay through a restore, so their account
			// mappings must follow the accounts to their new ids
			$bankMappings = $this->readBankMappings($userId);

			// Delete all existing data for user
			$this->clearUserData($userId);

			// Import in dependency order with ID remapping
			$idMaps = $this->importData($userId, $importData);

			$bankTargets = self::bankMappingTargets($bankMappings, $importData['accounts'] ?? [], $idMaps['accounts'] ?? []);
			$this->rePointBankMappings($bankTargets);
			$bankLinksRemoved = count(array_filter($bankTargets, static fn ($accountId): bool => $accountId === null));

			// Point those links at the restored rows, or cut them
			$links = $this->crossUserLinks?->apply($idMaps) ?? ['sharesDropped' => 0, 'othersDetached' => 0];

			// The ledger invariant, balance = opening balance + net(ledger),
			// for every restored account. The opening balance is what the
			// user set, so an account restored with one (every backup since
			// March 2026) keeps it and has its balance rebuilt from the
			// restored ledger, exactly as the next recalculation would. One
			// from an older backup, which has none, keeps its balance and
			// gets the opening balance that implies. Deriving the opening
			// balance from a balance re-signed by "in credit" (which only
			// describes the opening balance) restored any card or loan whose
			// ledger had crossed zero on the wrong side.
			$balances = new AccountBalanceCalculator($this->accountMapper, $this->transactionMapper);
			foreach (($idMaps['accounts'] ?? []) as $newAccountId) {
				$account = $this->accountMapper->findById($newAccountId);
				if ($account->getOpeningBalance() !== null) {
					$balances->recalculate($account);
					continue;
				}
				$net = $this->transactionMapper->getNetChangeAll($newAccountId);
				// At the account currency's precision: rounding to 2dp here
				// shifted a restored crypto balance on its next recompute (#331).
				$dp = Currency::decimalsFor($account->getCurrency());
				$account->setOpeningBalance(round(((float)$account->getBalance()) - $net, $dp));
				$this->accountMapper->update($account);
			}

			// A backup made before 3.0 holds the bill rows the upgrade to
			// 3.0 repairs. Restored as they were, an unpaid occurrence
			// counted as paid, and paying it booked it a second time. Run
			// once the balances are in, so the repair moves them as the
			// upgrade did, and after the links above, which point other
			// users' bill snapshots at the restored rows.
			if (version_compare(self::archiveVersion($importData), '1.3.0', '<')) {
				$this->repairLegacyBillRows(array_values($idMaps['accounts'] ?? []), $balances);
			}

			$this->db->commit();

			return [
				'success' => true,
				'message' => 'Import completed successfully',
				'counts' => $this->countData($importData),
				'warnings' => $this->restoreWarnings($links['sharesDropped'], $this->billsDetached, $links['othersDetached'], $this->userLinksRemoved, $bankLinksRemoved),
			];
		} catch (\Throwable $e) {
			// PHP errors too: only \Exception used to roll back
			$this->db->rollBack();
			throw $e;
		}
	}

	/**
	 * The upgrade's repairs of 2.54.0's bill rows (migrations 109 and 114,
	 * LegacyBillRows), applied to the restored accounts' rows, with the
	 * balances they move rebuilt.
	 *
	 * @param int[] $accountIds the restored accounts
	 */
	private function repairLegacyBillRows(array $accountIds, AccountBalanceCalculator $balances): void {
		$pending = LegacyBillRows::unpaidPlaceholders($this->db, $accountIds);
		$payments = LegacyBillRows::rescheduledPayments($this->db, $accountIds);
		LegacyBillRows::setStatus($this->db, array_keys($pending), 'scheduled');
		LegacyBillRows::setStatus($this->db, array_keys($payments), 'cleared');
		foreach (array_unique(array_values($pending + $payments)) as $accountId) {
			$balances->recalculate($this->accountMapper->findById($accountId));
		}
	}

	/**
	 * The user's bank account mappings that point at an account, with that
	 * account's name and creation time, read before the restore clears it.
	 *
	 * @return list<array{id: int, accountId: int, name: mixed, createdAt: mixed}>
	 */
	private function readBankMappings(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('m.id', 'm.budget_account_id', 'a.name', 'a.created_at')
			->from('budget_bam', 'm')
			->innerJoin('m', 'budget_bc', 'c', $qb->expr()->eq('m.connection_id', 'c.id'))
			->leftJoin('m', 'budget_accounts', 'a', $qb->expr()->eq('m.budget_account_id', 'a.id'))
			->where($qb->expr()->eq('c.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->isNotNull('m.budget_account_id'));
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = [
				'id' => (int)$row['id'],
				'accountId' => (int)$row['budget_account_id'],
				'name' => $row['name'] ?? null,
				'createdAt' => $row['created_at'] ?? null,
			];
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * Where each bank account mapping points after a restore: the restored
	 * id of the account it pointed at, or null to unlink it.
	 *
	 * Bank connections aren't in the backup and a restore keeps them, but
	 * every account comes back under a new id, so a mapping left alone
	 * pointed at an account that no longer existed and bank sync silently
	 * stopped importing into it. A mapping follows its account only when the
	 * backup holds that account under the id it had here, with the same name
	 * and creation time (CrossUserLinks::fingerprint()): true for the user's
	 * own backup restored on the same server, and never for one from
	 * another server, where the same id meant a different account. Anything
	 * else is unlinked, and the user picks the account again in bank sync.
	 *
	 * @param list<array{id: int, accountId: int, name: mixed, createdAt: mixed}> $mappings
	 * @param array<int, array<string, mixed>> $archivedAccounts the archive's accounts
	 * @param array<int, int> $accountMap old account id => restored id
	 * @return array<int, int|null> mapping id => account id, or null to unlink
	 */
	public static function bankMappingTargets(array $mappings, array $archivedAccounts, array $accountMap): array {
		$archived = [];
		foreach ($archivedAccounts as $account) {
			if (is_array($account) && isset($account['id'])) {
				$archived[(int)$account['id']] = CrossUserLinks::fingerprint($account['name'] ?? null, $account['createdAt'] ?? null);
			}
		}
		$targets = [];
		foreach ($mappings as $mapping) {
			$oldId = $mapping['accountId'];
			$before = CrossUserLinks::fingerprint($mapping['name'], $mapping['createdAt']);
			$targets[$mapping['id']] = $before !== null && ($archived[$oldId] ?? null) === $before
				? ($accountMap[$oldId] ?? null)
				: null;
		}
		return $targets;
	}

	/**
	 * @param array<int, int|null> $targets mapping id => account id, or null
	 */
	private function rePointBankMappings(array $targets): void {
		foreach ($targets as $mappingId => $accountId) {
			$qb = $this->db->getQueryBuilder();
			$qb->update('budget_bam')
				->set('budget_account_id', $qb->createNamedParameter($accountId, $accountId === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($mappingId, IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
	}

	/**
	 * What a restore couldn't keep of the links between this user's data
	 * and other people's, said in words.
	 *
	 * @return string[]
	 */
	private function restoreWarnings(int $sharesDropped, int $billsDetached, int $othersDetached, int $userLinksRemoved = 0, int $bankLinksRemoved = 0): array {
		$warnings = [];
		if ($sharesDropped > 0) {
			$warnings[] = $this->n(
				'%n item you shared could not be matched to the restored data, so it is no longer shared. Share it again in Settings if you still want to.',
				'%n items you shared could not be matched to the restored data, so they are no longer shared. Share them again in Settings if you still want to.',
				$sharesDropped
			);
		}
		if ($billsDetached > 0) {
			$warnings[] = $this->n(
				'%n bill or transfer lost its account in the restore: the account is not in this backup, or not shared with you any more. Its auto-pay is off; edit it to choose an account.',
				'%n bills or transfers lost their account in the restore: the account is not in this backup, or not shared with you any more. Their auto-pay is off; edit them to choose an account.',
				$billsDetached
			);
		}
		if ($othersDetached > 0) {
			$warnings[] = $this->n(
				'%n item of someone you share with used an account, category or bill that did not come back in this restore, and has been unlinked from it.',
				'%n items of people you share with used an account, category or bill that did not come back in this restore, and have been unlinked from it.',
				$othersDetached
			);
		}
		if ($userLinksRemoved > 0) {
			$warnings[] = $this->n(
				'%n contact was linked to a Nextcloud user you can\'t share with on this server, so the link was removed.',
				'%n contacts were linked to Nextcloud users you can\'t share with on this server, so the links were removed.',
				$userLinksRemoved
			);
		}
		if ($bankLinksRemoved > 0) {
			// bankMappingTargets(): a feed only follows the same account
			$warnings[] = $this->n(
				'%n bank account link could not be matched to an account in this backup, so Bank Sync no longer imports into it. Choose the account again in Bank Sync.',
				'%n bank account links could not be matched to accounts in this backup, so Bank Sync no longer imports into them. Choose the accounts again in Bank Sync.',
				$bankLinksRemoved
			);
		}
		return $warnings;
	}

	/**
	 * Preview import without executing.
	 *
	 * @return array{valid: bool, manifest: array, counts: array, warnings: array}
	 */
	public function previewImport(string $zipContent): array {
		$importData = $this->parseZipArchive($zipContent);
		$warnings = [];

		// Older formats import (missing data takes its defaults); a newer one
		// may hold data this version can't restore
		$manifest = is_array($importData['manifest'] ?? null) ? $importData['manifest'] : [];
		$version = is_scalar($manifest['version'] ?? null) ? (string)$manifest['version'] : 'unknown';
		if (version_compare($version, self::EXPORT_VERSION, '>')) {
			$warnings[] = $this->t('This backup was made by a newer version of Budget (backup format %1$s, this server reads up to %2$s). Some of its data may not be restored. Update Budget first if you can.', [$version, self::EXPORT_VERSION]);
		}

		return [
			'valid' => true,
			'manifest' => $manifest,
			'counts' => $this->countData($importData),
			'warnings' => $warnings
		];
	}

	/**
	 * Per-data-set row counts (manifest excluded). 'importRules' keeps its
	 * historical camelCase key for the preview UI.
	 */
	private function countData(array $data): array {
		$counts = [];
		foreach ($data as $key => $rows) {
			if ($key === 'manifest') {
				continue;
			}
			$counts[$key === 'import_rules' ? 'importRules' : $key] = is_array($rows) ? count($rows) : 0;
		}
		return $counts;
	}

	/**
	 * The user's transactions as the archive holds them
	 * (Transaction::jsonSerialize()), read EXPORT_BATCH rows at a time in id
	 * order.
	 *
	 * @return \Generator<int, array<string, mixed>>
	 */
	private function exportTransactionRows(string $userId): \Generator {
		$lastId = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('t.*')
				->from('budget_transactions', 't')
				->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
				->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->gt('t.id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
				->orderBy('t.id', 'ASC')
				->setMaxResults(self::EXPORT_BATCH);
			$rows = $this->fetchBatch($qb);
			foreach ($rows as $row) {
				$lastId = (int)$row['id'];
				yield Transaction::fromRow($row)->jsonSerialize();
			}
		} while (count($rows) === self::EXPORT_BATCH);
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fetchBatch(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[] = $row;
		}
		$result->closeCursor();
		return $rows;
	}

	/**
	 * Apply $fn to each item, lazily.
	 *
	 * @template T
	 * @param iterable<T> $items
	 * @param callable(T): mixed $fn
	 */
	private static function mapEach(iterable $items, callable $fn): \Generator {
		foreach ($items as $item) {
			yield $fn($item);
		}
	}

	/**
	 * Write a JSON list to $path one item at a time.
	 *
	 * @param iterable<mixed> $items
	 * @return int the number of items written
	 */
	private static function writeJsonList(string $path, iterable $items): int {
		$handle = fopen($path, 'wb');
		if ($handle === false) {
			throw new \RuntimeException('Failed to write the export');
		}
		$count = 0;
		try {
			self::writeBytes($handle, '[');
			foreach ($items as $item) {
				self::writeBytes($handle, ($count > 0 ? ',' : '') . self::encodeJson($item));
				$count++;
			}
			self::writeBytes($handle, ']');
		} finally {
			fclose($handle);
		}
		return $count;
	}

	private static function writeFile(string $path, string $content): void {
		if (file_put_contents($path, $content) !== strlen($content)) {
			throw new \RuntimeException('Failed to write the export');
		}
	}

	/**
	 * @param resource $handle
	 */
	private static function writeBytes($handle, string $bytes): void {
		if (fwrite($handle, $bytes) !== strlen($bytes)) {
			throw new \RuntimeException('Failed to write the export');
		}
	}

	/**
	 * A value as JSON. Bytes that aren't valid UTF-8 (which SQLite will
	 * store) become U+FFFD: json_encode() refuses them otherwise, and the
	 * whole export failed over one bad character.
	 */
	private static function encodeJson(mixed $value): string {
		$json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE);
		if ($json === false) {
			throw new \RuntimeException('Failed to encode the export: ' . json_last_error_msg());
		}
		return $json;
	}

	private static function tempPath(): string {
		$path = tempnam(sys_get_temp_dir(), 'budget_export_');
		if ($path === false) {
			throw new \RuntimeException('Failed to create a temporary file for the export');
		}
		return $path;
	}

	/**
	 * Parse a ZIP archive into import data.
	 */
	private function parseZipArchive(string $zipContent): array {
		$tempFile = tempnam(sys_get_temp_dir(), 'budget_import_');
		file_put_contents($tempFile, $zipContent);

		$zip = new \ZipArchive();
		if ($zip->open($tempFile) !== true) {
			unlink($tempFile);
			throw new \InvalidArgumentException('Invalid ZIP file');
		}

		$data = [];
		$requiredFiles = ['manifest.json', 'categories.json', 'accounts.json', 'transactions.json'];

		// Check for required files
		foreach ($requiredFiles as $file) {
			if ($zip->locateName($file) === false) {
				$zip->close();
				unlink($tempFile);
				throw new \InvalidArgumentException("Missing required file: $file");
			}
		}

		// Parse all JSON files
		$files = [
			'manifest' => 'manifest.json',
			'categories' => 'categories.json',
			'accounts' => 'accounts.json',
			'transactions' => 'transactions.json',
			'bills' => 'bills.json',
			'import_rules' => 'import_rules.json',
			'settings' => 'settings.json'
		];
		// Table-level files (#351) — absent in pre-1.2 exports, treated as empty
		foreach (array_keys(self::EXTRA_TABLES_PRE + self::EXTRA_TABLES_POST) as $key) {
			$files[$key] = $key . '.json';
		}

		// Refuse oversized entries before reading any of them. The sizes in
		// the zip's directory are only what the archive claims, so each read
		// is also capped at its claimed size + 1 byte: an entry that unpacks
		// to more than it declared is caught without inflating it further.
		// (getFromName() allocates its length up front, so the cap must be
		// the declared size, not the limit.)
		$total = 0;
		$declared = [];
		foreach ($files as $filename) {
			$stat = $zip->statName($filename);
			if ($stat === false) {
				continue;
			}
			$size = max(0, (int)($stat['size'] ?? 0));
			$declared[$filename] = $size;
			$total += $size;
			if ($size > static::MAX_ENTRY_BYTES || $total > static::MAX_TOTAL_BYTES) {
				$zip->close();
				unlink($tempFile);
				throw new \InvalidArgumentException($this->tooLargeMessage($filename, $size > static::MAX_ENTRY_BYTES));
			}
		}

		foreach ($files as $key => $filename) {
			$content = isset($declared[$filename])
				? $zip->getFromName($filename, $declared[$filename] + 1)
				: false;
			if ($content !== false) {
				if (strlen($content) > $declared[$filename]) {
					$zip->close();
					unlink($tempFile);
					throw new \InvalidArgumentException($this->tooLargeMessage($filename, true));
				}
				$decoded = json_decode($content, true);
				if (json_last_error() !== JSON_ERROR_NONE) {
					$zip->close();
					unlink($tempFile);
					throw new \InvalidArgumentException("Invalid JSON in $filename: " . json_last_error_msg());
				}
				$data[$key] = $decoded;
			} else {
				$data[$key] = ($key === 'settings') ? [] : [];
			}
		}

		$zip->close();
		unlink($tempFile);

		return $data;
	}

	private function tooLargeMessage(string $filename, bool $singleEntry): string {
		return $singleEntry
			? $this->t('This backup cannot be imported: %1$s is larger than %2$s MB when unpacked', [$filename, (string)(static::MAX_ENTRY_BYTES / 1024 / 1024)])
			: $this->t('This backup cannot be imported: its files add up to more than %1$s MB when unpacked', [(string)(static::MAX_TOTAL_BYTES / 1024 / 1024)]);
	}

	/**
	 * Keys of the bespoke data sets that must hold a single value. The ids
	 * become array keys and query parameters, so a list or an object there
	 * crashed the restore part way with a PHP error and a stack trace.
	 */
	private const SCALAR_KEYS = [
		'categories' => ['id', 'parentId', 'name', 'type'],
		'accounts' => ['id', 'name', 'type', 'currency', 'balance', 'openingBalance'],
		'transactions' => ['id', 'accountId', 'categoryId', 'billId', 'linkedTransactionId', 'reconSessionId', 'pensionContribId', 'amount', 'date', 'type'],
		'bills' => ['id', 'accountId', 'destinationAccountId', 'categoryId', 'name'],
		'import_rules' => ['id', 'categoryId', 'name'],
	];

	/**
	 * Validate import data structure.
	 */
	private function validateImportData(array $data): void {
		if (empty($data['manifest'])) {
			throw new \InvalidArgumentException('Missing manifest');
		}

		if (($data['manifest']['appId'] ?? '') !== self::APP_ID) {
			throw new \InvalidArgumentException('Invalid export file: wrong application');
		}

		$this->validateShapes($data);

		// Validate categories have required fields
		foreach ($data['categories'] ?? [] as $i => $cat) {
			if (empty($cat['name']) || empty($cat['type'])) {
				throw new \InvalidArgumentException("Invalid category at index $i: missing name or type");
			}
		}

		// Validate accounts have required fields
		foreach ($data['accounts'] ?? [] as $i => $acc) {
			if (empty($acc['name']) || empty($acc['type'])) {
				throw new \InvalidArgumentException("Invalid account at index $i: missing name or type");
			}
		}

		// Validate transactions have required fields
		foreach ($data['transactions'] ?? [] as $i => $txn) {
			if (!isset($txn['accountId']) || !isset($txn['amount']) || empty($txn['date'])) {
				throw new \InvalidArgumentException("Invalid transaction at index $i: missing required fields");
			}
		}
	}

	/**
	 * Every data set is a list of objects (settings: an object of single
	 * values), and every id a single value. An archive holding anything else
	 * is refused before anything is touched, rather than failing part way.
	 */
	private function validateShapes(array $data): void {
		$tables = self::EXTRA_TABLES_PRE + self::EXTRA_TABLES_POST;
		foreach ($data as $key => $rows) {
			if ($key === 'manifest') {
				continue;
			}
			if (!is_array($rows)) {
				throw $this->notInFormat($key);
			}
			if ($key === 'settings') {
				foreach ($rows as $value) {
					if ($value !== null && !is_scalar($value)) {
						throw $this->notInFormat($key);
					}
				}
				continue;
			}
			$scalarKeys = self::SCALAR_KEYS[$key] ?? ['id', ...array_keys($tables[$key]['fk'] ?? [])];
			foreach ($rows as $row) {
				if (!is_array($row)) {
					throw $this->notInFormat($key);
				}
				foreach ($scalarKeys as $scalarKey) {
					if (isset($row[$scalarKey]) && !is_scalar($row[$scalarKey])) {
						throw $this->notInFormat($key);
					}
				}
			}
		}
	}

	private function notInFormat(string $key): \InvalidArgumentException {
		return new \InvalidArgumentException($this->t('This backup cannot be imported: %1$s is not in the expected format', [$key . '.json']));
	}

	/**
	 * Clear all existing data for a user.
	 */
	private function clearUserData(string $userId): void {
		// Delete in reverse dependency order

		// Table-level extras first — the join-scoped ones (transaction tags,
		// splits, dismissed imports, tag sets) resolve their owner through
		// parents that are deleted further down (#351)
		$this->tableCleaner->clearRegisteredTables($userId);

		// Attachment rows are not in the registry (file ids do not survive an
		// export), but they hang off transactions: without this every restore
		// over existing data orphaned them. The files stay in the user's Files.
		$this->tableCleaner->clearTable($userId, ['table' => 'budget_attachments', 'scope' => 'user']);

		// Transactions reference accounts and categories. One bulk delete: the
		// children deleteWithChildren() would cascade to are all cleared above.
		$this->transactionMapper->deleteAll($userId);

		// Bills reference accounts and categories
		$bills = $this->billMapper->findAll($userId);
		foreach ($bills as $bill) {
			$this->billMapper->delete($bill);
		}

		// Import rules reference categories
		$rules = $this->importRuleMapper->findAll($userId);
		foreach ($rules as $rule) {
			$this->importRuleMapper->delete($rule);
		}

		// Accounts (no dependencies on other user entities)
		$accounts = $this->accountMapper->findAll($userId);
		foreach ($accounts as $account) {
			$this->accountMapper->delete($account);
		}

		// Categories (self-referential, delete children first by sorting)
		$categories = $this->categoryMapper->findAll($userId);
		// Sort so children (with parentId) come before parents
		usort($categories, fn ($a, $b) => ($b->getParentId() ?? 0) <=> ($a->getParentId() ?? 0));
		foreach ($categories as $category) {
			$this->categoryMapper->delete($category);
		}

		// Settings (use deleteAll for efficiency)
		$this->settingMapper->deleteAll($userId);
	}

	/**
	 * Import all data with ID remapping.
	 *
	 * @return array<string, array<int, int>> Maps of old ID => new ID per entity type
	 */
	private function importData(string $userId, array $data): array {
		$idMaps = [
			'categories' => [],
			'accounts' => []
		];

		// 1. Import categories (topological sort for parent relationships)
		$idMaps['categories'] = $this->importCategories($userId, $data['categories'] ?? []);

		// 2. Import accounts
		$idMaps['accounts'] = $this->importAccounts($userId, $data['accounts'] ?? [], version_compare(self::archiveVersion($data), '1.1.0', '<'));

		// 2b. Tag sets and tags — before transactions/bills so tag references
		// can be remapped (#351)
		foreach (self::EXTRA_TABLES_PRE as $key => $spec) {
			$this->importTable($userId, $key, $spec, $data[$key] ?? [], $idMaps);
		}

		// 3. Import transactions with ID remapping
		$archivedBillIds = [];
		foreach ($data['bills'] ?? [] as $billData) {
			if (isset($billData['id'])) {
				$archivedBillIds[] = (int)$billData['id'];
			}
		}
		$archivedAccounts = [];
		foreach ($data['accounts'] ?? [] as $accountData) {
			if (isset($accountData['id'])) {
				$archivedAccounts[(int)$accountData['id']] = $accountData;
			}
		}
		$txResult = $this->importTransactions($userId, $data['transactions'] ?? [], $idMaps, $archivedBillIds, $archivedAccounts);
		$idMaps['transactions'] = $txResult['map'];
		// Restore transfer pair links between the freshly imported rows (#351)
		$this->fixupTransactionColumn('linked_transaction_id', $txResult['links'], $txResult['map']);

		// 4. Import bills with ID remapping
		$idMaps['bills'] = $this->importBills($userId, $data['bills'] ?? [], $idMaps);

		// 5. Import import rules with ID remapping
		$idMaps['import_rules'] = $this->importImportRules($userId, $data['import_rules'] ?? [], $idMaps);

		// 6. Import settings
		$this->importSettings($userId, $data['settings'] ?? [], $idMaps);

		// 7. Everything else, table-level in dependency order (#351)
		foreach (self::EXTRA_TABLES_POST as $key => $spec) {
			$this->importTable($userId, $key, $spec, $data[$key] ?? [], $idMaps);
		}

		// 7b. tx_splits above imports through the same generic machinery as
		// every other registry table, which has no notion that a parent's
		// is_split flag exists — and the flag written by importTransactions()
		// (step 3, before any of this ran) can't be trusted either: a
		// pre-#351 backup carries no isSplit at all, and even a current one
		// can carry false for what was really a NULL-flag original. Read back
		// which transactions actually received parts and mark exactly those,
		// or a restored split's parts stop counting anywhere (#360).
		$this->markSplitParents($idMaps['transactions']);

		// 8. Point transactions at the new ids of their late-imported
		// references (bills come after transactions; reconciliation sessions
		// and pension contributions only exist after step 7)
		$this->fixupTransactionColumn('bill_id', $txResult['billRefs'], $idMaps['bills']);
		$this->fixupTransactionColumn('recon_session_id', $txResult['reconRefs'], $idMaps['recon_sessions'] ?? []);
		$this->fixupTransactionColumn('pension_contrib_id', $txResult['pensionRefs'], $idMaps['pen_contribs'] ?? []);

		return $idMaps;
	}

	/**
	 * Import categories with topological sort for parent relationships.
	 *
	 * @return array<int, int> Map of old ID => new ID
	 */
	private function importCategories(string $userId, array $categories): array {
		if (empty($categories)) {
			return [];
		}

		$idMap = [];

		// Sort categories: parents first (null parentId), then children
		$sorted = $this->topologicalSortCategories($categories);

		foreach ($sorted as $catData) {
			$oldId = $catData['id'];

			$category = new Category();
			$category->setUserId($userId);
			$category->setName($catData['name']);
			$category->setType($catData['type']);
			$category->setIcon($catData['icon'] ?? null);
			$category->setColor($catData['color'] ?? null);
			$category->setBudgetAmount($catData['budgetAmount'] ?? null);
			$category->setBudgetPeriod($catData['budgetPeriod'] ?? null);
			$category->setSortOrder($catData['sortOrder'] ?? 0);
			// Exported all along but never read back, so a restore put every
			// category back into reports and budgets (#391)
			$category->setExcludedFromReports(self::flag($catData, 'excludedFromReports', false));
			$category->setExcludedFromBudget(self::flag($catData, 'excludedFromBudget', false));
			$category->setBudgetRollover(self::flag($catData, 'budgetRollover', false));
			$category->setRolloverStart($catData['rolloverStart'] ?? null);
			$category->setCreatedAt($catData['createdAt'] ?? date('Y-m-d H:i:s'));
			// The Full control recipient who added it, so they can still delete
			// it after a restore. Imported into that same person's account it
			// is simply theirs, which NULL already says.
			$createdBy = $catData['createdBy'] ?? null;
			$category->setCreatedBy(is_string($createdBy) && $createdBy !== '' && $createdBy !== $userId ? $createdBy : null);

			// Remap parent ID
			if (!empty($catData['parentId']) && isset($idMap[$catData['parentId']])) {
				$category->setParentId($idMap[$catData['parentId']]);
			}

			$inserted = $this->categoryMapper->insert($category);
			$idMap[$oldId] = $inserted->getId();
		}

		return $idMap;
	}

	/**
	 * Topological sort categories so parents are imported before children.
	 */
	private function topologicalSortCategories(array $categories): array {
		$result = [];
		$pending = $categories;
		$processedIds = []; // Old IDs that have been processed

		// First pass: add all categories without parents
		foreach ($pending as $key => $cat) {
			if (empty($cat['parentId'])) {
				$result[] = $cat;
				$processedIds[] = $cat['id'];
				unset($pending[$key]);
			}
		}

		// Subsequent passes: add categories whose parents are processed
		$maxIterations = count($categories) + 1;
		$iterations = 0;

		while (!empty($pending) && $iterations < $maxIterations) {
			foreach ($pending as $key => $cat) {
				if (in_array($cat['parentId'], $processedIds)) {
					$result[] = $cat;
					$processedIds[] = $cat['id'];
					unset($pending[$key]);
				}
			}
			$iterations++;
		}

		// If there are still pending items, they have invalid parent references
		// Add them anyway with null parent
		foreach ($pending as $cat) {
			$cat['parentId'] = null;
			$result[] = $cat;
		}

		return $result;
	}

	/**
	 * Account properties importAccounts() sets itself rather than copying
	 * from the archive: identity, and the balance pair and in-credit flag,
	 * which a backup older than format 1.1.0 holds in another sign
	 * convention. The balance is rebuilt from the imported ledger afterwards
	 * (importAll()).
	 */
	private const ACCOUNT_PROPERTIES_NOT_COPIED = ['id', 'userId', 'balance', 'openingBalance', 'liabilityInCredit'];

	/**
	 * The backup format version an archive declares, or "0" (older than any)
	 * when it declares none that reads as a version.
	 */
	private static function archiveVersion(array $importData): string {
		$manifest = $importData['manifest'] ?? null;
		$version = is_array($manifest) ? ($manifest['version'] ?? null) : null;
		return is_string($version) && preg_match('/^\d+(\.\d+)*$/', $version) === 1 ? $version : '0';
	}

	/**
	 * Every other Account property, read off the entity itself.
	 *
	 * The export writes Account::toArrayFull(), every column. The import used
	 * to copy them back by a hand-written list, which silently fell behind:
	 * wallet address, interest settings, accrued interest and the
	 * last-reconciled date were reset by every restore. Deriving the list
	 * from the entity means a new column round-trips without anyone having to
	 * remember this method.
	 *
	 * @return string[]
	 */
	public static function copiedAccountProperties(): array {
		$properties = [];
		foreach ((new \ReflectionClass(Account::class))->getProperties() as $property) {
			$name = $property->getName();
			if (!str_starts_with($name, '_') && !$property->isStatic()
				&& !in_array($name, self::ACCOUNT_PROPERTIES_NOT_COPIED, true)) {
				$properties[] = $name;
			}
		}
		return $properties;
	}

	/**
	 * A boolean from an archived entity. A backup can hold "false" or "0" as
	 * strings, which a bare cast (or the entity's own settype) turns true -
	 * how auto-pay came back switched on for restored bills (#335).
	 */
	private static function flag(array $data, string $key, bool $default): bool {
		if (!isset($data[$key])) {
			return $default;
		}
		return filter_var($data[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
	}

	/**
	 * Import accounts.
	 *
	 * @param bool $legacySigns the archive predates format 1.1.0, when a
	 *                          liability held what was owed as a positive number
	 * @return array<int, int> Map of old ID => new ID
	 */
	private function importAccounts(string $userId, array $accounts, bool $legacySigns = false): array {
		$idMap = [];
		$fieldTypes = (new Account())->getFieldTypes();
		$now = date('Y-m-d H:i:s');

		foreach ($accounts as $accData) {
			$oldId = $accData['id'];

			$account = new Account();
			$account->setUserId($userId);

			// Every exported column, typed by the entity. A key missing from
			// an older archive keeps its default below.
			foreach (self::copiedAccountProperties() as $property) {
				if (!array_key_exists($property, $accData)) {
					continue;
				}
				$value = $accData[$property];
				if ($value !== null && ($fieldTypes[$property] ?? null) === 'boolean') {
					// A backup can hold "false", which a bare cast turns true (#335)
					$value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
				}
				if ($value === null && in_array($property, ['name', 'type', 'currency', 'createdAt', 'updatedAt'], true)) {
					continue;
				}
				$account->{'set' . ucfirst($property)}($value);
			}

			// The archive's balances are already signed, and "in credit" is
			// what the user declared about the opening balance: both come
			// back as they were. Signing today's balance with that flag put
			// a card or loan whose ledger had crossed zero on the wrong side.
			// The balance is rebuilt from the opening balance and the ledger
			// once the ledger is in (importAll()); an archive without an
			// opening balance keeps its balance instead.
			$type = (string)($accData['type'] ?? '');
			$liability = AccountType::tryFrom($type)?->isLiability() ?? false;
			$declared = array_key_exists('liabilityInCredit', $accData) && $accData['liabilityInCredit'] !== null
				? filter_var($accData['liabilityInCredit'], FILTER_VALIDATE_BOOLEAN)
				: null;
			$balance = is_numeric($accData['balance'] ?? null) ? (float)$accData['balance'] : 0.0;
			$opening = is_numeric($accData['openingBalance'] ?? null) ? (float)$accData['openingBalance'] : null;
			if ($liability && $legacySigns) {
				// Before format 1.1.0 a liability held what was owed as a
				// positive number. The upgrade to 1.1.0 negated positive
				// balances and opening balances, and so does this.
				$balance = $balance > 0 ? -$balance : $balance;
				$opening = $opening !== null && $opening > 0 ? -$opening : $opening;
			}
			$account->setBalance($balance);
			$account->setOpeningBalance($opening);
			$account->setLiabilityInCredit($liability ? $declared : null);

			// Defaults for archives that predate a column (or hold null in a
			// NOT NULL one)
			if ($account->getCurrency() === '') {
				$account->setCurrency('USD');
			}
			if ($account->getExcludedFromReports() === null) {
				// Both flags were dropped by every restore before #372 — the
				// exclude-from-reports one had been lost since #286.
				$account->setExcludedFromReports(false);
			}
			if ($account->getClosed() === null) {
				$account->setClosed(false);
			}
			if ($account->getCreatedAt() === null) {
				$account->setCreatedAt($now);
			}
			if ($account->getUpdatedAt() === null) {
				$account->setUpdatedAt($now);
			}

			$inserted = $this->accountMapper->insert($account);
			$idMap[$oldId] = $inserted->getId();
		}

		return $idMap;
	}

	/**
	 * Import transactions with ID remapping.
	 */
	/**
	 * @return array{map: array<int,int>, links: array<int,int>, billRefs: array<int,int>}
	 *                                                                                     map: old id => new id; links: NEW id => OLD linkedTransactionId;
	 *                                                                                     billRefs: NEW id => OLD billId. Links and bill references are
	 *                                                                                     restored in a fixup pass once both sides have new ids — dropping
	 *                                                                                     them unlinked every transfer pair on migration (#351), which then
	 *                                                                                     counted as income/expense in reports.
	 *
	 * @param int[]|null $archivedBillIds the archive's bill ids: a row carrying
	 *                                    any other bill id was booked by another user's bill (see below).
	 *                                    Null treats every bill id as the archive's own.
	 * @param array<int, array<string, mixed>> $archivedAccounts the archive's accounts by id
	 */
	private function importTransactions(string $userId, array $transactions, array $idMaps, ?array $archivedBillIds = null, array $archivedAccounts = []): array {
		$map = [];
		$links = [];
		$billRefs = [];
		$reconRefs = [];
		$pensionRefs = [];
		$ownBills = $archivedBillIds === null ? null : array_flip($archivedBillIds);
		$archivedIds = [];
		foreach ($transactions as $txnData) {
			if (isset($txnData['id'])) {
				$archivedIds[(int)$txnData['id']] = true;
			}
		}
		foreach ($transactions as $txnData) {
			// Skip if account doesn't exist in map (shouldn't happen with valid export)
			$oldAccountId = $txnData['accountId'];
			if (!isset($idMaps['accounts'][$oldAccountId])) {
				continue;
			}

			// A row booked into this user's account by someone else's bill
			// (the account is shared with them) keeps that bill only if it is
			// the row the user had here; the bill, not being this user's, is
			// untouched by the restore. Otherwise a pending one is left out:
			// restored without its bill, nothing could clear or remove it,
			// and it went on to charge the account on its date.
			$billId = !empty($txnData['billId']) ? (int)$txnData['billId'] : null;
			$foreignBill = $billId !== null && $ownBills !== null && !isset($ownBills[$billId]);
			$account = $archivedAccounts[(int)$oldAccountId] ?? [];
			$keepsForeignBill = $foreignBill && $this->keepsTransactionLink($txnData, $account, 'billId', $billId);
			if ($foreignBill && !$keepsForeignBill && ($txnData['status'] ?? null) === 'scheduled') {
				continue;
			}
			// Likewise the other leg of a transfer to or from an account of
			// someone else's
			$linkedId = !empty($txnData['linkedTransactionId']) ? (int)$txnData['linkedTransactionId'] : null;
			$keepsForeignLeg = $linkedId !== null && !isset($archivedIds[$linkedId])
				&& $this->keepsTransactionLink($txnData, $account, 'linkedId', $linkedId);

			$transaction = new Transaction();
			$transaction->setAccountId($idMaps['accounts'][$oldAccountId]);
			$transaction->setDate($txnData['date']);
			$transaction->setDescription($txnData['description'] ?? '');
			$transaction->setVendor($txnData['vendor'] ?? null);
			$transaction->setAmount($txnData['amount']);
			$transaction->setType($txnData['type'] ?? 'debit');
			$transaction->setReference($txnData['reference'] ?? null);
			$transaction->setNotes($txnData['notes'] ?? null);
			$transaction->setImportId($txnData['importId'] ?? null);
			$transaction->setReconciled(self::flag($txnData, 'reconciled', false));
			// Restore status — dropping it turned scheduled transactions into
			// cleared ones, silently corrupting balances after a migration (#274)
			$transaction->setStatus($txnData['status'] ?? null);
			$transaction->setExcludedFromForecast(self::flag($txnData, 'excludedFromForecast', false));
			// Without this the imported splits exist but the transaction
			// doesn't show as split (#351)
			$transaction->setIsSplit(!empty($txnData['isSplit']));
			$transaction->setCreatedAt($txnData['createdAt'] ?? date('Y-m-d H:i:s'));
			$transaction->setUpdatedAt($txnData['updatedAt'] ?? date('Y-m-d H:i:s'));

			// Remap category ID
			$oldCategoryId = $txnData['categoryId'] ?? null;
			if ($oldCategoryId !== null && isset($idMaps['categories'][$oldCategoryId])) {
				$transaction->setCategoryId($idMaps['categories'][$oldCategoryId]);
			}

			if ($keepsForeignBill) {
				$transaction->setBillId($billId);
			}
			if ($keepsForeignLeg) {
				$transaction->setLinkedTransactionId($linkedId);
			}

			$inserted = $this->transactionMapper->insert($transaction);
			if (isset($txnData['id'])) {
				$map[(int)$txnData['id']] = $inserted->getId();
			}
			if ($linkedId !== null && !$keepsForeignLeg) {
				$links[$inserted->getId()] = $linkedId;
			}
			if ($billId !== null && !$foreignBill) {
				$billRefs[$inserted->getId()] = $billId;
			}
			if (!empty($txnData['reconSessionId'])) {
				$reconRefs[$inserted->getId()] = (int)$txnData['reconSessionId'];
			}
			if (!empty($txnData['pensionContribId'])) {
				$pensionRefs[$inserted->getId()] = (int)$txnData['pensionContribId'];
			}
		}

		return [
			'map' => $map,
			'links' => $links,
			'billRefs' => $billRefs,
			'reconRefs' => $reconRefs,
			'pensionRefs' => $pensionRefs,
		];
	}

	/**
	 * Whether an archived transaction keeps its link to another user's bill
	 * or transfer leg (CrossUserLinks::keepsTransactionLink()).
	 */
	private function keepsTransactionLink(array $txnData, array $account, string $link, int $value): bool {
		return $this->crossUserLinks !== null && isset($txnData['id'])
			&& $this->crossUserLinks->keepsTransactionLink(
				(int)$txnData['id'], $txnData['date'] ?? null, $txnData['amount'] ?? null, $txnData['type'] ?? 'debit',
				(int)$txnData['accountId'], $account['name'] ?? null, $account['createdAt'] ?? null,
				$link, $value
			);
	}

	/**
	 * Second pass over freshly imported transactions: point linked transfer
	 * legs and bill references at the NEW ids.
	 *
	 * @param array<int,int> $refs newTransactionId => old referenced id
	 * @param array<int,int> $idMap old referenced id => new referenced id
	 */
	private function fixupTransactionColumn(string $column, array $refs, array $idMap): void {
		foreach ($refs as $newTxId => $oldRefId) {
			if (!isset($idMap[$oldRefId])) {
				continue;
			}
			$qb = $this->db->getQueryBuilder();
			$qb->update('budget_transactions')
				->set($column, $qb->createNamedParameter($idMap[$oldRefId], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
				->where($qb->expr()->eq('id', $qb->createNamedParameter($newTxId, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
			$qb->executeStatement();
		}
	}

	/**
	 * Read back which of the freshly imported transactions actually received
	 * split parts, and set is_split on exactly those (#360). See the call
	 * site (step 7b of importData()) for why the archived flag can't be
	 * trusted here.
	 *
	 * @param array<int,int> $transactionIdMap old id => new id, from importTransactions()
	 */
	private function markSplitParents(array $transactionIdMap): void {
		if ($transactionIdMap === []) {
			return;
		}

		// Chunked at 500 like every other unbounded id list in this class —
		// a restore is exactly where the biggest lists occur, and old SQLite
		// builds cap bound variables at 999.
		$splitParentIds = [];
		foreach (array_chunk(array_values($transactionIdMap), 500) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->selectDistinct('transaction_id')
				->from('budget_tx_splits')
				->where($qb->expr()->in(
					'transaction_id',
					$qb->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
				));
			$result = $qb->executeQuery();
			while ($row = $result->fetch()) {
				$splitParentIds[] = (int)$row['transaction_id'];
			}
			$result->closeCursor();
		}

		// Resolve the flag BOTH ways. The flag written by importTransactions()
		// came from the archive, and a post-#351 restore of a pre-#351 archive
		// claims is_split for parts that never made it into the backup —
		// leaving stray-true rows the read side lists as uncategorized while
		// the write side silently discards their category (#360). Parts are
		// the truth: true where parts arrived, false where none did.
		$withParts = array_flip($splitParentIds);
		$withoutParts = array_values(array_filter(
			array_values($transactionIdMap),
			static fn (int $id): bool => !isset($withParts[$id])
		));

		if ($splitParentIds !== []) {
			$this->setIsSplitFlag($splitParentIds, true);
		}
		if ($withoutParts !== []) {
			$this->setIsSplitFlag($withoutParts, false);
		}
	}

	/**
	 * @param int[] $ids
	 */
	private function setIsSplitFlag(array $ids, bool $isSplit): void {
		foreach (array_chunk($ids, 500) as $chunk) {
			$update = $this->db->getQueryBuilder();
			$update->update('budget_transactions')
				->set('is_split', $update->createNamedParameter($isSplit, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
				->where($update->expr()->in(
					'id',
					$update->createNamedParameter($chunk, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT_ARRAY)
				));
			$update->executeStatement();
		}
	}

	/**
	 * Remap a raw table row's foreign keys per its EXTRA_TABLES spec (#351).
	 * Returns the adjusted row, or null when a required reference cannot be
	 * mapped (the row would point at data that was not imported).
	 *
	 * @param array<string,mixed> $row raw column => value
	 */
	private function remapRow(array $row, array $spec, array $idMaps): ?array {
		$archived = $row;
		// Columns kept on another user's id, still shared with this user
		$keptShared = [];
		foreach ($spec['fk'] ?? [] as $column => $fkSpec) {
			$value = $row[$column] ?? null;
			if ($value === null || $value === '') {
				continue;
			}
			$mapped = $idMaps[$fkSpec['map']][(int)$value] ?? null;
			if ($mapped !== null) {
				$row[$column] = $mapped;
			} elseif (!empty($fkSpec['shared']) && $this->keepsSharedReference($spec, $archived, $column, (int)$value)) {
				$row[$column] = (int)$value;
				$keptShared[$column] = true;
			} elseif (($fkSpec['onMissing'] ?? 'null') === 'drop') {
				return null;
			} else {
				$row[$column] = null;
			}
		}

		if (isset($spec['undoSnapshot'])) {
			$snapshotSpec = $spec['undoSnapshot'];
			$column = $snapshotSpec['column'];
			if (($row[$column] ?? null) !== null) {
				$accountsCarried = true;
				$sharedAccount = false;
				foreach ($snapshotSpec['accounts'] ?? [] as $accountColumn) {
					$hadOne = ($archived[$accountColumn] ?? null) !== null && $archived[$accountColumn] !== '';
					$accountsCarried = $accountsCarried && (!$hadOne || $row[$accountColumn] !== null);
					$sharedAccount = $sharedAccount || isset($keptShared[$accountColumn]);
				}
				$entity = $spec['entity'] ?? null;
				$keepUnmapped = $sharedAccount && $entity !== null && isset($archived['id'])
					? fn (int $id): bool => $this->crossUserLinks->keepsSnapshotTransaction($entity, (int)$archived['id'], $archived['name'] ?? null, $archived['created_at'] ?? null, $id)
					: null;
				$snapshot = $this->remapUndoSnapshot($row[$column], $snapshotSpec['required'], $snapshotSpec['idLists'] ?? [], [], $accountsCarried, $keepUnmapped, $idMaps);
				$row[$column] = $snapshot === null ? null : json_encode($snapshot);
			}
		}

		foreach ($spec['jsonKeyFk'] ?? [] as $column => $keys) {
			if (($row[$column] ?? null) !== null && $row[$column] !== '') {
				$row[$column] = $this->remapJsonKeyIds((string)$row[$column], $keys, $idMaps);
			}
		}

		foreach ($spec['snapshotRefs'] ?? [] as $column => $refs) {
			if (($row[$column] ?? null) !== null && $row[$column] !== '') {
				$row[$column] = $this->remapSnapshotRefs($row[$column], $refs, $idMaps);
			}
		}

		if (isset($spec['billKeyedType']) && ($row['suggestion_type'] ?? null) === $spec['billKeyedType']) {
			return $this->remapBillKeyedDismissal($row, $idMaps);
		}

		foreach ($spec['jsonFk'] ?? [] as $column => $fkSpec) {
			$raw = $row[$column] ?? null;
			if ($raw === null || $raw === '') {
				continue;
			}
			$decoded = json_decode((string)$raw, true);
			if (!is_array($decoded)) {
				continue;
			}
			$map = $idMaps[$fkSpec['map']] ?? [];
			$shape = $fkSpec['shape'] ?? 'idList';
			$remapped = [];
			if ($shape === 'idKeyedObject') {
				foreach ($decoded as $oldId => $v) {
					if (isset($map[(int)$oldId])) {
						$remapped[$map[(int)$oldId]] = $v;
					}
				}
			} elseif ($shape === 'idValuedObject') {
				foreach ($decoded as $k => $oldId) {
					if (isset($map[(int)$oldId])) {
						$remapped[$k] = $map[(int)$oldId];
					}
				}
			} else {
				foreach ($decoded as $oldId) {
					if (isset($map[(int)$oldId])) {
						$remapped[] = $map[(int)$oldId];
					}
				}
			}
			$row[$column] = json_encode($remapped);
		}

		return $row;
	}

	/**
	 * Whether a registry row may keep a reference to another user's account
	 * or category that is still shared with this user (CrossUserLinks).
	 */
	private function keepsSharedReference(array $spec, array $archived, string $column, int $value): bool {
		return $this->crossUserLinks !== null && isset($spec['entity'], $archived['id'])
			&& $this->crossUserLinks->keepsReference($spec['entity'], (int)$archived['id'], $archived['name'] ?? null, $archived['created_at'] ?? null, $column, $value);
	}

	/**
	 * An undo snapshot (a bill's last payment, an income's last receipt) with
	 * the transactions it names moved to their restored ids.
	 *
	 * Copied as it was, a revert would delete whatever now holds the old
	 * ids. A row the backup doesn't hold was deleted before it was made, so
	 * it is left out, as a revert would have skipped it. If the item posted
	 * into an account the restore couldn't keep, its rows are out of reach:
	 * a revert would put it back to unpaid and leave the money where it was,
	 * so it comes back with no snapshot at all, as does one that doesn't
	 * read as a snapshot. On an account still shared with the user its rows
	 * sit in the other user's ledger, outside the backup, and each must be
	 * one $keepUnmapped vouches for, or the snapshot goes.
	 *
	 * @param mixed $raw the archived snapshot, decoded or as JSON
	 * @param string $required a key every valid snapshot has
	 * @param string[] $idLists keys holding lists of transaction ids
	 * @param string[] $idSingles keys holding one transaction id or null
	 * @param bool $accountsCarried every account the item posts into came through
	 * @param (callable(int): bool)|null $keepUnmapped for an item on a shared
	 *                                                 account: whether an id the backup doesn't hold stays
	 * @return array<string, mixed>|null
	 */
	private function remapUndoSnapshot(mixed $raw, string $required, array $idLists, array $idSingles, bool $accountsCarried, ?callable $keepUnmapped, array $idMaps): ?array {
		$snapshot = is_string($raw) ? json_decode($raw, true) : $raw;
		if (!is_array($snapshot) || !array_key_exists($required, $snapshot) || !$accountsCarried) {
			return null;
		}

		$transactionMap = $idMaps['transactions'] ?? [];
		// The restored id, the id itself when it stays, or null when it goes
		$resolve = static function (int $oldId) use ($transactionMap, $keepUnmapped): int|false|null {
			if (isset($transactionMap[$oldId])) {
				return $transactionMap[$oldId];
			}
			if ($keepUnmapped === null) {
				return null;
			}
			return $keepUnmapped($oldId) ? $oldId : false;
		};
		foreach ($idLists as $key) {
			if (!array_key_exists($key, $snapshot)) {
				continue;
			}
			if (!is_array($snapshot[$key])) {
				return null;
			}
			$ids = [];
			foreach ($snapshot[$key] as $oldId) {
				if (!is_numeric($oldId)) {
					return null;
				}
				$id = $resolve((int)$oldId);
				if ($id === false) {
					return null;
				}
				if ($id !== null) {
					$ids[] = $id;
				}
			}
			$snapshot[$key] = $ids;
		}
		foreach ($idSingles as $key) {
			$oldId = $snapshot[$key] ?? null;
			if ($oldId === null) {
				continue;
			}
			if (!is_numeric($oldId)) {
				return null;
			}
			$id = $resolve((int)$oldId);
			if ($id === false) {
				return null;
			}
			$snapshot[$key] = $id;
		}
		return $snapshot;
	}

	/**
	 * A JSON object's id lists moved to the restored ids. An id that didn't
	 * come back is left out, unless it is another user's still shared with
	 * this one. Copied as they were, a saved report's account and tag
	 * filters named the ids from before the restore, which matched nothing
	 * or, on another server, someone else's.
	 *
	 * @param array<string, array{map: string, shared?: string}> $keys
	 */
	private function remapJsonKeyIds(string $raw, array $keys, array $idMaps): string {
		$decoded = json_decode($raw, true);
		if (!is_array($decoded)) {
			return $raw;
		}
		foreach ($keys as $key => $fkSpec) {
			if (!isset($decoded[$key]) || !is_array($decoded[$key])) {
				continue;
			}
			$ids = [];
			foreach ($decoded[$key] as $oldId) {
				if (!is_numeric($oldId)) {
					continue;
				}
				$oldId = (int)$oldId;
				if (isset($idMaps[$fkSpec['map']][$oldId])) {
					$ids[] = $idMaps[$fkSpec['map']][$oldId];
				} elseif (isset($fkSpec['shared']) && $this->crossUserLinks?->isSharedWithUser($fkSpec['shared'], $oldId)) {
					$ids[] = $oldId;
				}
			}
			$decoded[$key] = $ids;
		}
		return (string)json_encode($decoded);
	}

	/**
	 * An undo snapshot whose keys name rows by id, with each moved to the
	 * row's restored id, as JSON; null when any of them didn't come back or
	 * it doesn't read as a snapshot.
	 *
	 * Copied as it was, a pension schedule's Undo looked for the contribution
	 * under its old id, didn't find it, and put the schedule's dates back
	 * anyway, so the occurrence posted again with the first one's money
	 * still in place.
	 *
	 * @param mixed $raw the archived snapshot, decoded or as JSON
	 * @param array<string, string> $refs snapshot key => id map key
	 */
	private function remapSnapshotRefs(mixed $raw, array $refs, array $idMaps): ?string {
		$snapshot = is_string($raw) ? json_decode($raw, true) : $raw;
		if (!is_array($snapshot)) {
			return null;
		}
		foreach ($refs as $key => $map) {
			$oldId = $snapshot[$key] ?? null;
			if (!is_numeric($oldId) || !isset($idMaps[$map][(int)$oldId])) {
				return null;
			}
			$snapshot[$key] = $idMaps[$map][(int)$oldId];
		}
		return json_encode($snapshot);
	}

	/**
	 * A dismissed unrecorded payment moved to its bill's restored id, or null
	 * to leave it out.
	 *
	 * The dismissal is keyed "billId:paidDate" (and its sha1), as
	 * BillService::unrecordedPaymentKey() builds it. Copied as it was, the
	 * payment came back on the card after every restore, and on a new server
	 * a different bill holding the old id could have its own payment hidden.
	 * One whose bill isn't in the backup applies to nothing.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>|null
	 */
	private function remapBillKeyedDismissal(array $row, array $idMaps): ?array {
		if (!is_string($row['pattern'] ?? null) || preg_match('/^(\d+):(.+)$/', $row['pattern'], $m) !== 1) {
			return null;
		}
		$newBillId = $idMaps['bills'][(int)$m[1]] ?? null;
		if ($newBillId === null) {
			return null;
		}
		$row['pattern'] = $newBillId . ':' . $m[2];
		$row['pattern_hash'] = sha1($row['pattern']);
		return $row;
	}

	/**
	 * A JSON column's value, decoded; null when empty or not JSON.
	 */
	private static function decodeJsonColumn(?string $raw): mixed {
		if ($raw === null || $raw === '') {
			return null;
		}
		return json_decode($raw, true);
	}

	/**
	 * Export one registry table's rows for the user (raw columns, snake_case),
	 * EXPORT_BATCH rows at a time in id order. user_id is dropped (reassigned
	 * on import); id is kept for remapping.
	 *
	 * @return \Generator<int, array<string, mixed>>
	 */
	private function exportTableRows(string $userId, array $spec): \Generator {
		$lastId = 0;
		do {
			$qb = $this->db->getQueryBuilder();
			$qb->select('t.*')->from($spec['table'], 't');
			if (($spec['scope'] ?? 'user') === 'user') {
				$qb->where($qb->expr()->eq('t.user_id', $qb->createNamedParameter($userId)));
			} else {
				// Chain of joins ending at a table that has user_id
				$prev = 't';
				$alias = 't';
				foreach ($spec['scope']['joins'] as $i => [$joinTable, $localColumn]) {
					$alias = 'j' . $i;
					$qb->innerJoin($prev, $joinTable, $alias, $qb->expr()->eq($prev . '.' . $localColumn, $alias . '.id'));
					$prev = $alias;
				}
				$qb->where($qb->expr()->eq($alias . '.user_id', $qb->createNamedParameter($userId)));
			}
			$qb->andWhere($qb->expr()->gt('t.id', $qb->createNamedParameter($lastId, IQueryBuilder::PARAM_INT)))
				->orderBy('t.id', 'ASC')
				->setMaxResults(self::EXPORT_BATCH);

			$rows = $this->fetchBatch($qb);
			foreach ($rows as $row) {
				$lastId = (int)$row['id'];
				unset($row['user_id']);
				yield $row;
			}
		} while (count($rows) === self::EXPORT_BATCH);
	}

	/**
	 * Import one registry table's rows: reassign user_id, remap foreign keys,
	 * drop rows whose required references were not imported, record this
	 * table's own old => new ids when later tables need them.
	 *
	 * @return int number of rows imported
	 */
	private function importTable(string $userId, string $key, array $spec, array $rows, array &$idMaps): int {
		$count = 0;
		$hasUserColumn = ($spec['scope'] ?? 'user') === 'user';
		$bindings = $rows === [] ? null : $this->columnBindings($spec['table']);
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$row = $this->remapRow($row, $spec, $idMaps);
			if ($row === null) {
				continue;
			}
			$row = $this->checkUserLink($userId, $spec, $row);
			$oldId = isset($row['id']) ? (int)$row['id'] : null;
			unset($row['id'], $row['user_id']);
			// Archive content is user-supplied: its keys become SQL column
			// identifiers below, so anything but plain snake_case is dropped
			$row = $this->filterRowColumns($row);
			if ($hasUserColumn) {
				$row['user_id'] = $userId;
			}

			$qb = $this->db->getQueryBuilder();
			$qb->insert($spec['table']);
			foreach ($row as $column => $value) {
				// A column this server does not have (a backup from a newer
				// version) is left out rather than failing the whole restore
				if ($bindings !== null && !isset($bindings[$column])) {
					continue;
				}
				[$bound, $type] = self::bindValue($value, $bindings[$column] ?? null);
				$qb->setValue($column, $qb->createNamedParameter($bound, $type));
			}
			$qb->executeStatement();
			$count++;

			if (isset($spec['idMap']) && $oldId !== null) {
				$idMaps[$spec['idMap']][$oldId] = (int)$this->db->lastInsertId('*PREFIX*' . $spec['table']);
			}
		}
		return $count;
	}

	/**
	 * A row's link to a Nextcloud user (the spec's 'userLink' column), kept
	 * only when the restoring user could make that link today. Copied as it
	 * was, a crafted backup linked a contact to anyone on the server, past
	 * the sharing settings creating a contact enforces, and that user was
	 * shown the contact's shared expenses.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function checkUserLink(string $userId, array $spec, array $row): array {
		$column = $spec['userLink'] ?? null;
		if ($column === null || ($row[$column] ?? null) === null || $row[$column] === '') {
			return $row;
		}
		if ($this->contactLinks === null || !is_string($row[$column]) || !$this->contactLinks->mayLink($userId, $row[$column])) {
			$row[$column] = null;
			$this->userLinksRemoved++;
		}
		return $row;
	}

	/**
	 * The target table's column bindings, read once per table. Null when no
	 * schema probe is wired (unit tests) or the schema cannot be read, in
	 * which case values bind by their own PHP type.
	 *
	 * @return array<string, 'bool'|'int'|'string'>|null
	 */
	private function columnBindings(string $table): ?array {
		if ($this->schemaProbe === null) {
			return null;
		}
		if (!array_key_exists($table, $this->bindingCache)) {
			try {
				$this->bindingCache[$table] = $this->schemaProbe->columnBindings($table);
			} catch (\Throwable $e) {
				$this->bindingCache[$table] = null;
			}
		}
		return $this->bindingCache[$table];
	}

	/**
	 * A raw archived value and the parameter type to bind it with.
	 *
	 * Every value used to go in as a string, so a boolean false reached
	 * PostgreSQL as '' ("invalid input syntax for type boolean") and a backup
	 * holding a single tag could not be restored there. The archive does not
	 * say what type a value had either: PostgreSQL exports booleans as
	 * true/false, SQLite and MySQL as 0/1 (as numbers or strings), so the
	 * target column decides. NULL always binds as NULL.
	 *
	 * @param 'bool'|'int'|'string'|null $binding the column's binding, or null
	 *                                            when unknown (bind by the value's PHP type)
	 * @return array{0: mixed, 1: int|string}
	 */
	public static function bindValue(mixed $value, ?string $binding): array {
		if ($value === null) {
			return [null, IQueryBuilder::PARAM_NULL];
		}
		$binding ??= match (true) {
			is_bool($value) => 'bool',
			is_int($value) => 'int',
			default => 'string',
		};

		if ($binding === 'bool') {
			if (is_string($value)) {
				// PostgreSQL's own text form
				$lower = strtolower(trim($value));
				if ($lower === 't' || $lower === 'f') {
					return [$lower === 't', IQueryBuilder::PARAM_BOOL];
				}
			}
			$bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
			return $bool === null ? [null, IQueryBuilder::PARAM_NULL] : [$bool, IQueryBuilder::PARAM_BOOL];
		}

		if ($binding === 'int') {
			if ($value === '') {
				return [null, IQueryBuilder::PARAM_NULL];
			}
			if (is_bool($value) || is_numeric($value)) {
				return [(int)$value, IQueryBuilder::PARAM_INT];
			}
			return [(string)$value, IQueryBuilder::PARAM_STR];
		}

		if (is_bool($value)) {
			return [$value ? '1' : '0', IQueryBuilder::PARAM_STR];
		}
		if (is_array($value)) {
			return [json_encode($value), IQueryBuilder::PARAM_STR];
		}
		return [$value, IQueryBuilder::PARAM_STR];
	}

	/**
	 * Keep only keys that are plausible column names (lowercase snake_case).
	 * Row keys come from an uploaded archive and are used as SQL identifiers
	 * in the import INSERT — never trust them further than this.
	 */
	private function filterRowColumns(array $row): array {
		$filtered = [];
		foreach ($row as $column => $value) {
			if (is_string($column) && preg_match('/^[a-z][a-z0-9_]*$/', $column) === 1) {
				$filtered[$column] = $value;
			}
		}
		return $filtered;
	}

	/**
	 * Import bills with ID remapping.
	 */
	/**
	 * @return array<int,int> Map of old bill ID => new bill ID
	 */
	private function importBills(string $userId, array $bills, array $idMaps): array {
		$map = [];
		foreach ($bills as $billData) {
			$bill = new Bill();
			$bill->setUserId($userId);
			$bill->setName($billData['name']);
			$bill->setDescription($billData['description'] ?? null);
			$bill->setAmount($billData['amount'] ?? 0.0);
			$bill->setAmountType($billData['amountType'] ?? null);
			$bill->setFrequency($billData['frequency'] ?? 'monthly');
			$bill->setDueDay($billData['dueDay'] ?? null);
			$bill->setDueMonth($billData['dueMonth'] ?? null);
			// Without the recurrence pattern a restored custom-frequency bill
			// has no schedule left and advances one day at a time when paid
			$bill->setCustomRecurrencePattern($billData['customRecurrencePattern'] ?? null);
			$bill->setAutoDetectPattern($billData['autoDetectPattern'] ?? null);
			// Coerce booleans with filter_var: a backup can store them as strings
			// ("false"), which is truthy under a bare cast — that silently turned
			// auto-pay ON for restored bills (#335).
			$bill->setIsActive(filter_var($billData['isActive'] ?? true, FILTER_VALIDATE_BOOLEAN));
			$bill->setLastPaidDate($billData['lastPaidDate'] ?? null);
			$bill->setNextDueDate($billData['nextDueDate'] ?? null);
			$bill->setNotes($billData['notes'] ?? null);
			$bill->setReminderDays($billData['reminderDays'] ?? null);
			$bill->setAutoPayEnabled(filter_var($billData['autoPayEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN));
			$bill->setAutoPayFailed(filter_var($billData['autoPayFailed'] ?? false, FILTER_VALIDATE_BOOLEAN));
			$bill->setIsTransfer(filter_var($billData['isTransfer'] ?? false, FILTER_VALIDATE_BOOLEAN));
			$bill->setTransferDescriptionPattern($billData['transferDescriptionPattern'] ?? null);
			// Tag references remap through the freshly imported tags (#351) —
			// they used to be carried over as ids that meant nothing here
			$newTagIds = [];
			foreach ((is_array($billData['tagIds'] ?? null) ? $billData['tagIds'] : []) as $oldTagId) {
				if (isset($idMaps['tags'][(int)$oldTagId])) {
					$newTagIds[] = $idMaps['tags'][(int)$oldTagId];
				}
			}
			$bill->setTagIdsArray($newTagIds);
			$bill->setStartDate($this->billStartDate($billData));
			$bill->setEndDate($billData['endDate'] ?? null);
			$bill->setRemainingPayments($billData['remainingPayments'] ?? null);
			// A split bill keeps its categories in the template (category_id
			// is NULL for it). Copied verbatim, every part pointed at a
			// category id from before the restore, so each payment's split was
			// refused and the payment was saved unsplit and uncategorised.
			$bill->setSplitTemplateArray(is_array($billData['splitTemplate'] ?? null)
				? $this->remapSplitTemplate($billData, $idMaps)
				: null);
			// The reminder job sends once per due date by remembering when it
			// last sent; without this every bill inside its reminder window
			// got the same reminder again after a restore.
			$bill->setLastReminderSent($billData['lastReminderSent'] ?? null);
			$bill->setExcludedFromForecast(filter_var($billData['excludedFromForecast'] ?? false, FILTER_VALIDATE_BOOLEAN));
			$bill->setCreateTransaction(filter_var($billData['createTransaction'] ?? true, FILTER_VALIDATE_BOOLEAN));
			$bill->setCreatedAt($billData['createdAt'] ?? date('Y-m-d H:i:s'));

			// Category and accounts move to the restored ids. One that
			// belongs to another user, shared with this one, isn't in the
			// backup: it is kept while it is still shared (an account
			// writable), and only for the bill this user had here, pointing
			// at it before. It used to be dropped every time, and a bill
			// paid from a shared account came back with no account.
			$bill->setCategoryId($this->restoredBillReference($billData, $idMaps, 'categoryId', 'category_id', 'categories'));
			$oldAccountId = $billData['accountId'] ?? null;
			$bill->setAccountId($this->restoredBillReference($billData, $idMaps, 'accountId', 'account_id', 'accounts'));
			$oldDestId = $billData['destinationAccountId'] ?? null;
			$bill->setDestinationAccountId($this->restoredBillReference($billData, $idMaps, 'destinationAccountId', 'destination_account_id', 'accounts'));

			$lostAccount = ($oldAccountId !== null && $bill->getAccountId() === null)
				|| ($bill->getIsTransfer() && $oldDestId !== null && $bill->getDestinationAccountId() === null);
			if ($lostAccount) {
				// With no account auto-pay only marks the bill paid and
				// records nothing, while still showing as on
				$bill->setAutoPayEnabled(false);
				$this->billsDetached++;
			}

			// Mark Unpaid works from this, and a paid one-time bill is only
			// listed while it has one (#365)
			if (($billData['paidUndoState'] ?? null) !== null) {
				$sharedAccount = ($oldAccountId !== null && !isset($idMaps['accounts'][(int)$oldAccountId]) && $bill->getAccountId() !== null)
					|| ($oldDestId !== null && !isset($idMaps['accounts'][(int)$oldDestId]) && $bill->getDestinationAccountId() !== null);
				$snapshot = $this->remapUndoSnapshot(
					$billData['paidUndoState'],
					'previousState',
					['createdTransactionIds', 'scheduledTransactionIds'],
					['linkedTransactionId'],
					!$lostAccount,
					$sharedAccount && isset($billData['id'])
						? fn (int $id): bool => $this->crossUserLinks->keepsSnapshotTransaction(ShareItem::TYPE_BILL, (int)$billData['id'], $billData['name'] ?? null, $billData['createdAt'] ?? null, $id)
						: null,
					$idMaps
				);
				if ($snapshot !== null && is_array($snapshot['previousState'])) {
					$bill->setPaidUndoState(json_encode($snapshot));
				}
			}

			$inserted = $this->billMapper->insert($bill);
			if (isset($billData['id'])) {
				$map[(int)$billData['id']] = $inserted->getId();
			}
		}

		return $map;
	}

	/**
	 * A bill's start date. One-time bills from a backup made before 2.52 have
	 * none: their date lived in next_due_date, which marking one paid
	 * cleared. Migration 104 rebuilt it once, at the upgrade, and a restore
	 * of such a backup brought the gap straight back, so the bill opened with
	 * an empty Due Date. The same rule fills it in here.
	 */
	private function billStartDate(array $billData): ?string {
		$startDate = $billData['startDate'] ?? null;
		if (($startDate !== null && $startDate !== '') || ($billData['frequency'] ?? null) !== 'one-time') {
			return $startDate;
		}
		return Version001000104Date20260916::dueDateFor(
			isset($billData['dueDay']) ? (int)$billData['dueDay'] : null,
			isset($billData['dueMonth']) ? (int)$billData['dueMonth'] : null,
			isset($billData['nextDueDate']) ? (string)$billData['nextDueDate'] : null,
			isset($billData['lastPaidDate']) ? (string)$billData['lastPaidDate'] : null
		);
	}

	/**
	 * A restored bill's category, account or destination: the restored id,
	 * another user's id it may keep (see importBills()), or null.
	 */
	private function restoredBillReference(array $billData, array $idMaps, string $key, string $column, string $map): ?int {
		$oldId = $billData[$key] ?? null;
		if ($oldId === null || $oldId === '') {
			return null;
		}
		if (isset($idMaps[$map][(int)$oldId])) {
			return $idMaps[$map][(int)$oldId];
		}
		return $this->billKeepsShared($billData, $column, (int)$oldId) ? (int)$oldId : null;
	}

	private function billKeepsShared(array $billData, string $column, int $value): bool {
		return $this->crossUserLinks !== null && isset($billData['id'])
			&& $this->crossUserLinks->keepsReference(ShareItem::TYPE_BILL, (int)$billData['id'], $billData['name'] ?? null, $billData['createdAt'] ?? null, $column, $value);
	}

	/**
	 * A split template's parts with their categories moved to the restored
	 * ids. A part whose category isn't in the backup stays, uncategorised,
	 * so the parts still add up to the bill, unless it is a category still
	 * shared with the user (as for the bill's own category).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function remapSplitTemplate(array $billData, array $idMaps): array {
		$parts = [];
		foreach ($billData['splitTemplate'] as $part) {
			if (!is_array($part)) {
				continue;
			}
			$oldCategoryId = $part['categoryId'] ?? null;
			if ($oldCategoryId !== null && $oldCategoryId !== '') {
				$categoryId = (int)$oldCategoryId;
				$part['categoryId'] = $idMaps['categories'][$categoryId]
					?? ($this->crossUserLinks !== null && isset($billData['id'])
						&& $this->crossUserLinks->keepsSplitCategory((int)$billData['id'], $billData['name'] ?? null, $billData['createdAt'] ?? null, $categoryId)
						? $categoryId
						: null);
			}
			$parts[] = $part;
		}
		return $parts;
	}

	/**
	 * Import import rules with ID remapping.
	 *
	 * @return array<int, int> old id => new id, which share items follow
	 */
	private function importImportRules(string $userId, array $rules, array $idMaps): array {
		$map = [];
		foreach ($rules as $ruleData) {
			$rule = new ImportRule();
			$rule->setUserId($userId);
			$rule->setName($ruleData['name']);
			$rule->setPattern($ruleData['pattern'] ?? '');
			$rule->setField($ruleData['field'] ?? 'description');
			$rule->setMatchType($ruleData['matchType'] ?? 'contains');
			$rule->setVendorName($ruleData['vendorName'] ?? null);
			$rule->setPriority($ruleData['priority'] ?? 0);
			$rule->setActive(self::flag($ruleData, 'active', true));
			$rule->setCreatedAt($ruleData['createdAt'] ?? date('Y-m-d H:i:s'));
			$rule->setUpdatedAt($ruleData['updatedAt'] ?? null);
			$rule->setSchemaVersion($ruleData['schemaVersion'] ?? 1);
			$rule->setApplyOnImport(self::flag($ruleData, 'applyOnImport', true));
			$rule->setStopProcessing(self::flag($ruleData, 'stopProcessing', true));
			// Exported all along but never read back, so every rule group
			// was lost by a restore
			$groupName = $ruleData['groupName'] ?? null;
			$rule->setGroupName(is_string($groupName) && $groupName !== '' ? $groupName : null);

			// Remap legacy category ID
			$oldCategoryId = $ruleData['categoryId'] ?? null;
			if ($oldCategoryId !== null) {
				$rule->setCategoryId($this->restoredRuleReference($ruleData, 'categories', $oldCategoryId, $idMaps));
			}

			// Import actions with ID remapping
			if (isset($ruleData['actions']) && is_array($ruleData['actions'])) {
				$rule->setActionsFromArray($this->remapRuleActions($ruleData, $ruleData['actions'], $idMaps));
			}

			// Conditions on the account name it by id
			if (isset($ruleData['criteria']) && is_array($ruleData['criteria'])) {
				$criteria = $ruleData['criteria'];
				if (isset($criteria['root']) && is_array($criteria['root'])) {
					$criteria['root'] = $this->remapRuleCriteria($ruleData, $criteria['root'], $idMaps);
				}
				$rule->setCriteriaFromArray($criteria);
			}

			$inserted = $this->importRuleMapper->insert($rule);
			if (isset($ruleData['id'])) {
				$map[(int)$ruleData['id']] = $inserted->getId();
			}
		}
		return $map;
	}

	/**
	 * A rule's actions with the accounts, categories and tags they name moved
	 * to the restored ids. Copied as they were, a tag action named tags that
	 * no longer existed and failed every import row it matched after the row
	 * was saved, and a category or account action could name someone
	 * else's. An action whose target didn't come back is left out.
	 *
	 * @param array<string, mixed> $actions
	 * @return array<string, mixed>
	 */
	private function remapRuleActions(array $ruleData, array $actions, array $idMaps): array {
		// Legacy v1 flat format: {categoryId: 5, vendor: "X"}
		if (array_key_exists('categoryId', $actions) && $actions['categoryId'] !== null) {
			$categoryId = $this->restoredRuleReference($ruleData, 'categories', $actions['categoryId'], $idMaps);
			if ($categoryId === null) {
				unset($actions['categoryId']);
			} else {
				$actions['categoryId'] = $categoryId;
			}
		}

		// v2 nested format: {version: 2, actions: [{type, value}, ...]}
		if (!isset($actions['actions']) || !is_array($actions['actions'])) {
			return $actions;
		}
		$kept = [];
		foreach ($actions['actions'] as $action) {
			$type = is_array($action) ? ($action['type'] ?? null) : null;
			$value = is_array($action) ? ($action['value'] ?? null) : null;
			if ($value !== null && ($type === 'set_category' || $type === 'set_account')) {
				$id = $this->restoredRuleReference($ruleData, $type === 'set_category' ? 'categories' : 'accounts', $value, $idMaps);
				if ($id === null) {
					continue;
				}
				$action['value'] = $id;
			} elseif ($type === 'add_tags' && is_array($value)) {
				$tagIds = [];
				foreach ($value as $oldTagId) {
					if (is_numeric($oldTagId) && isset($idMaps['tags'][(int)$oldTagId])) {
						$tagIds[] = $idMaps['tags'][(int)$oldTagId];
					}
				}
				if ($tagIds === [] && $value !== []) {
					continue;
				}
				$action['value'] = $tagIds;
			}
			$kept[] = $action;
		}
		$actions['actions'] = $kept;
		return $actions;
	}

	/**
	 * A rule's condition tree with every condition on the account moved to
	 * the restored account. Its pattern is the account's id
	 * (CriteriaEvaluator::matchAccount()), so copied as it was the rule
	 * never matched again. One whose account didn't come back is pointed at
	 * no account (0), which never matches: dropping the condition instead
	 * would widen the rule to every account.
	 *
	 * @param array<string, mixed> $node
	 * @return array<string, mixed>
	 */
	private function remapRuleCriteria(array $ruleData, array $node, array $idMaps, int $depth = 0): array {
		if ($depth > 10) {
			return $node;
		}
		if (isset($node['conditions']) && is_array($node['conditions'])) {
			foreach ($node['conditions'] as $i => $child) {
				if (is_array($child)) {
					$node['conditions'][$i] = $this->remapRuleCriteria($ruleData, $child, $idMaps, $depth + 1);
				}
			}
			return $node;
		}
		if (($node['field'] ?? null) === 'account' && isset($node['pattern'])) {
			$id = $this->restoredRuleReference($ruleData, 'accounts', $node['pattern'], $idMaps) ?? 0;
			$node['pattern'] = is_string($node['pattern']) ? (string)$id : $id;
		}
		return $node;
	}

	/**
	 * An account or category a restored rule names, at its restored id; the
	 * id itself when it is another user's the rule may keep
	 * (CrossUserLinks::ruleKeepsReference()); otherwise null.
	 *
	 * @param 'accounts'|'categories' $map
	 */
	private function restoredRuleReference(array $ruleData, string $map, mixed $oldId, array $idMaps): ?int {
		if (!is_numeric($oldId)) {
			return null;
		}
		$oldId = (int)$oldId;
		if (isset($idMaps[$map][$oldId])) {
			return $idMaps[$map][$oldId];
		}
		$type = $map === 'accounts' ? ShareItem::TYPE_ACCOUNT : ShareItem::TYPE_CATEGORY;
		return $this->crossUserLinks !== null && isset($ruleData['id'])
			&& $this->crossUserLinks->ruleKeepsReference((int)$ruleData['id'], $ruleData['name'] ?? null, $ruleData['createdAt'] ?? null, $type, $oldId)
			? $oldId
			: null;
	}

	/**
	 * Import settings. The ones that name accounts or categories by id move
	 * to the restored ids (remapSettingIds()).
	 *
	 * @param array<string, array<int, int>> $idMaps
	 */
	private function importSettings(string $userId, array $settings, array $idMaps = []): void {
		$now = date('Y-m-d H:i:s');

		foreach ($settings as $key => $value) {
			$setting = new Setting();
			$setting->setUserId($userId);
			$setting->setKey($key);
			$setting->setValue($this->remapSettingIds((string)$key, (string)$value, $idMaps));
			$setting->setCreatedAt($now);
			$setting->setUpdatedAt($now);
			$this->settingMapper->insert($setting);
		}
	}

	/**
	 * A setting's value with the account and category ids it names moved to
	 * the restored ids. Restored as they were, the dashboard's account
	 * filters, the categories a tile hides and the muted budget alerts named
	 * the ids from before the restore: nothing on the same server, and on
	 * another server whatever held those ids there. An id that didn't come
	 * back is dropped. A value that doesn't read as expected is left alone.
	 *
	 * @param array<string, array<int, int>> $idMaps
	 */
	private function remapSettingIds(string $key, string $value, array $idMaps): string {
		return match ($key) {
			'dashboard_widgets_config', 'dashboard_hero_config' => $this->remapDashboardConfig($value, $idMaps),
			// BudgetAlertService: the categories muted on the alerts tile
			'budget_alert_muted_categories' => $this->remapSettingIdList($value, $idMaps),
			// BudgetAlertService and AnomalyDetectionService: what was last
			// notified, per category
			'budget_alert_notified', 'anomaly_notified' => $this->remapSettingIdKeys($value, $idMaps),
			default => $value,
		};
	}

	/**
	 * The dashboard layout (DashboardModule). Ids sit in each tile's settings
	 * (an account filter, the categories it hides), in the account pickers
	 * ("trend-account-select", "hero-account-income-select" and so on) and
	 * in the Accounts tile's order and hidden list.
	 * Read as objects, not arrays, so an empty {} stays one: an array would
	 * come back as [], and the dashboard's next save of it would be lost.
	 *
	 * @param array<string, array<int, int>> $idMaps
	 */
	private function remapDashboardConfig(string $value, array $idMaps): string {
		$config = json_decode($value);
		if (!$config instanceof \stdClass) {
			return $value;
		}
		if (($config->tileSettings ?? null) instanceof \stdClass) {
			foreach (get_object_vars($config->tileSettings) as $tile) {
				if (!$tile instanceof \stdClass) {
					continue;
				}
				if (isset($tile->accountId) && is_numeric($tile->accountId)) {
					$id = $this->restoredSettingId((int)$tile->accountId, 'accounts', $idMaps);
					if ($id === null) {
						unset($tile->accountId);
					} else {
						$tile->accountId = is_string($tile->accountId) ? (string)$id : $id;
					}
				}
				if (isset($tile->hiddenCategories) && is_array($tile->hiddenCategories)) {
					$tile->hiddenCategories = $this->restoredSettingIds($tile->hiddenCategories, 'categories', $idMaps);
				}
			}
		}
		if (($config->settings ?? null) instanceof \stdClass) {
			foreach (get_object_vars($config->settings) as $name => $setting) {
				if ($name === 'accountsTile' && $setting instanceof \stdClass) {
					foreach (['order', 'hidden'] as $list) {
						if (isset($setting->{$list}) && is_array($setting->{$list})) {
							$setting->{$list} = $this->restoredSettingIds($setting->{$list}, 'accounts', $idMaps);
						}
					}
				} elseif (str_contains((string)$name, 'account') && str_ends_with((string)$name, '-select') && is_numeric($setting)) {
					$id = $this->restoredSettingId((int)$setting, 'accounts', $idMaps);
					if ($id === null) {
						unset($config->settings->{$name});
					} else {
						$config->settings->{$name} = is_string($setting) ? (string)$id : $id;
					}
				}
			}
		}
		return (string)json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * A JSON list of category ids, moved to the restored ids.
	 *
	 * @param array<string, array<int, int>> $idMaps
	 */
	private function remapSettingIdList(string $value, array $idMaps): string {
		$ids = json_decode($value, true);
		if (!is_array($ids) || !array_is_list($ids)) {
			return $value;
		}
		return (string)json_encode($this->restoredSettingIds($ids, 'categories', $idMaps));
	}

	/**
	 * A JSON object keyed by category id, with the keys moved to the
	 * restored ids.
	 *
	 * @param array<string, array<int, int>> $idMaps
	 */
	private function remapSettingIdKeys(string $value, array $idMaps): string {
		$entries = json_decode($value, true);
		if (!is_array($entries)) {
			return $value;
		}
		$restored = [];
		foreach ($entries as $oldId => $entry) {
			$id = is_numeric($oldId) ? $this->restoredSettingId((int)$oldId, 'categories', $idMaps) : null;
			if ($id !== null) {
				$restored[(string)$id] = $entry;
			}
		}
		return (string)json_encode((object)$restored, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
	}

	/**
	 * @param list<mixed> $ids
	 * @param 'accounts'|'categories' $map
	 * @return list<int>
	 */
	private function restoredSettingIds(array $ids, string $map, array $idMaps): array {
		$restored = [];
		foreach ($ids as $oldId) {
			$id = is_numeric($oldId) ? $this->restoredSettingId((int)$oldId, $map, $idMaps) : null;
			if ($id !== null) {
				$restored[] = $id;
			}
		}
		return $restored;
	}

	/**
	 * An account or category a setting names, at its restored id; the id
	 * itself when it is another user's still shared with this one (as a
	 * saved report's filter keeps it); null when it didn't come back.
	 *
	 * @param 'accounts'|'categories' $map
	 */
	private function restoredSettingId(int $oldId, string $map, array $idMaps): ?int {
		if (isset($idMaps[$map][$oldId])) {
			return (int)$idMaps[$map][$oldId];
		}
		$type = $map === 'accounts' ? ShareItem::TYPE_ACCOUNT : ShareItem::TYPE_CATEGORY;
		return $this->crossUserLinks?->isSharedWithUser($type, $oldId) ? $oldId : null;
	}
}
