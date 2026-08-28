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
use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Enum\AccountType;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCP\IDBConnection;

/**
 * Service for exporting and importing all user data for migration between instances.
 */
class MigrationService {
    private const EXPORT_VERSION = '1.2.0';
    private const APP_ID = 'budget';

    /**
     * Table-level round-trip specs (#351): everything beyond the five bespoke
     * entity types (categories, accounts, transactions, bills, import rules)
     * and settings. Each entry becomes <key>.json in the archive. Keys:
     *   table   physical table name (without the oc_ prefix)
     *   scope   'user' (has a user_id column), or ['joins' => [[table,
     *           localColumn], …]] — a chain ending at a table with user_id
     *   idMap   record this table's old => new ids under this key
     *   fk      [column => ['map' => idMapKey, 'onMissing' => 'null'|'drop']]
     *   jsonFk  [column => ['map' => idMapKey,
     *           'shape' => 'idList'|'idKeyedObject'|'idValuedObject']]
     *
     * PRE entries import after categories/accounts, before transactions and
     * bills (bills remap their tagIds through the tags map). POST entries
     * import after bills. Order within each phase is dependency order.
     *
     * Deliberately NOT exported: audit log and idempotency keys (instance
     * state), bank-sync connections/mappings (provider agreements and
     * credentials are instance-specific), shares (reference other Nextcloud
     * users), attachments (file ids do not survive), fetched exchange-rate
     * cache (manual rates ARE exported), legacy tables nothing reads.
     */
    private const EXTRA_TABLES_PRE = [
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

    private const EXTRA_TABLES_POST = [
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
            'fk' => [
                'account_id' => ['map' => 'accounts', 'onMissing' => 'null'],
                'category_id' => ['map' => 'categories', 'onMissing' => 'null'],
            ],
        ],
        'savings_goals' => [
            'table' => 'budget_savings_goals',
            'scope' => 'user',
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
        ],
        'dismiss_imp' => [
            'table' => 'budget_dismiss_imp',
            'scope' => ['joins' => [['budget_accounts', 'account_id']]],
            'fk' => ['account_id' => ['map' => 'accounts', 'onMissing' => 'drop']],
        ],
    ];

    public function __construct(
        private AccountMapper $accountMapper,
        private TransactionMapper $transactionMapper,
        private CategoryMapper $categoryMapper,
        private BillMapper $billMapper,
        private ImportRuleMapper $importRuleMapper,
        private SettingMapper $settingMapper,
        private IDBConnection $db
    ) {
    }

    /**
     * Export all user data as a ZIP archive.
     *
     * @return array{content: string, filename: string, contentType: string}
     */
    public function exportAll(string $userId): array {
        $exportData = $this->gatherExportData($userId);
        $zipContent = $this->createZipArchive($exportData);

        return [
            'content' => $zipContent,
            'filename' => 'budget_export_' . date('Y-m-d_His') . '.zip',
            'contentType' => 'application/zip'
        ];
    }

    /**
     * Import data from a ZIP archive.
     * This performs a full replacement of existing data.
     *
     * @param string $zipContent The raw ZIP file content
     * @return array{success: bool, message: string, counts: array}
     */
    public function importAll(string $userId, string $zipContent): array {
        $importData = $this->parseZipArchive($zipContent);

        $this->validateImportData($importData);

        // Use transaction to ensure atomicity
        $this->db->beginTransaction();

        try {
            // Delete all existing data for user
            $this->clearUserData($userId);

            // Import in dependency order with ID remapping
            $idMaps = $this->importData($userId, $importData);

            // Restore the ledger invariant for imported accounts:
            // opening_balance := exported balance − net(imported transactions).
            // This preserves the displayed balance exactly (even for exports
            // from drifted instances) while keeping future recalculation sound.
            foreach (($idMaps['accounts'] ?? []) as $newAccountId) {
                $account = $this->accountMapper->findById($newAccountId);
                $net = $this->transactionMapper->getNetChangeAll($newAccountId);
                $account->setOpeningBalance(round(((float)$account->getBalance()) - $net, 2));
                $this->accountMapper->update($account);
            }

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Import completed successfully',
                'counts' => $this->countData($importData),
            ];
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Preview import without executing.
     *
     * @return array{valid: bool, manifest: array, counts: array, warnings: array}
     */
    public function previewImport(string $zipContent): array {
        $importData = $this->parseZipArchive($zipContent);
        $warnings = [];

        // Check version compatibility
        $version = $importData['manifest']['version'] ?? 'unknown';
        if (version_compare($version, self::EXPORT_VERSION, '>')) {
            $warnings[] = "Export version ($version) is newer than supported (" . self::EXPORT_VERSION . ")";
        }

        return [
            'valid' => true,
            'manifest' => $importData['manifest'] ?? [],
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
     * Gather all exportable data for a user.
     */
    private function gatherExportData(string $userId): array {
        // Get categories
        $categories = $this->categoryMapper->findAll($userId);
        $categoriesData = array_map(fn(Category $c) => $c->jsonSerialize(), $categories);

        // Get accounts with full (decrypted) data
        $accounts = $this->accountMapper->findAll($userId);
        $accountsData = array_map(fn(Account $a) => $a->toArrayFull(), $accounts);

        // Get transactions
        $transactions = $this->transactionMapper->findAll($userId);
        $transactionsData = array_map(fn(Transaction $t) => $t->jsonSerialize(), $transactions);

        // Get bills
        $bills = $this->billMapper->findAll($userId);
        $billsData = array_map(fn(Bill $b) => $b->jsonSerialize(), $bills);

        // Get import rules
        $importRules = $this->importRuleMapper->findAll($userId);
        $importRulesData = array_map(fn(ImportRule $r) => $r->jsonSerialize(), $importRules);

        // Get settings
        $settings = $this->settingMapper->findAll($userId);
        $settingsData = [];
        foreach ($settings as $setting) {
            $settingsData[$setting->getKey()] = $setting->getValue();
        }

        $data = [
            'categories' => $categoriesData,
            'accounts' => $accountsData,
            'transactions' => $transactionsData,
            'bills' => $billsData,
            'import_rules' => $importRulesData,
            'settings' => $settingsData
        ];

        // Everything else round-trips at the table level (#351)
        foreach (self::EXTRA_TABLES_PRE + self::EXTRA_TABLES_POST as $key => $spec) {
            $data[$key] = $this->exportTable($userId, $spec);
        }

        $counts = [];
        foreach ($data as $key => $rows) {
            $counts[$key] = count($rows);
        }

        $manifest = [
            'version' => self::EXPORT_VERSION,
            'appId' => self::APP_ID,
            'exportedAt' => date('c'),
            'counts' => $counts,
            // Receipt attachments reference files in the user's Files space by
            // instance-specific fileId — the files themselves are not part of
            // this archive, and attachment links are not restored on import.
            'attachmentsNote' => 'Receipt files are not included; file references do not survive export/import.',
            'excluded' => 'Not exported: audit log and idempotency keys (instance state), bank-sync connections (provider agreements and credentials are instance-specific and must be re-established), shares (reference users on the old server), receipt attachments (see attachmentsNote), fetched exchange-rate cache (manual rates are included).',
        ];

        return ['manifest' => $manifest] + $data;
    }

    /**
     * Create a ZIP archive from export data.
     */
    private function createZipArchive(array $data): string {
        $tempFile = tempnam(sys_get_temp_dir(), 'budget_export_');

        $zip = new \ZipArchive();
        if ($zip->open($tempFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Failed to create ZIP archive');
        }

        // One JSON file per data set, manifest included
        foreach ($data as $key => $payload) {
            $zip->addFromString($key . '.json', json_encode($payload, JSON_PRETTY_PRINT));
        }

        $zip->close();

        $content = file_get_contents($tempFile);
        unlink($tempFile);

        return $content;
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

        foreach ($files as $key => $filename) {
            $content = $zip->getFromName($filename);
            if ($content !== false) {
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
     * Clear all existing data for a user.
     */
    private function clearUserData(string $userId): void {
        // Delete in reverse dependency order

        // Table-level extras first — the join-scoped ones (transaction tags,
        // splits, dismissed imports, tag sets) resolve their owner through
        // parents that are deleted further down (#351)
        foreach (self::EXTRA_TABLES_POST + self::EXTRA_TABLES_PRE as $spec) {
            $this->clearTable($userId, $spec);
        }

        // Transactions reference accounts and categories
        $transactions = $this->transactionMapper->findAll($userId);
        foreach ($transactions as $txn) {
            $this->transactionMapper->delete($txn);
        }

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
        usort($categories, fn($a, $b) => ($b->getParentId() ?? 0) <=> ($a->getParentId() ?? 0));
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
        $idMaps['accounts'] = $this->importAccounts($userId, $data['accounts'] ?? []);

        // 2b. Tag sets and tags — before transactions/bills so tag references
        // can be remapped (#351)
        foreach (self::EXTRA_TABLES_PRE as $key => $spec) {
            $this->importTable($userId, $key, $spec, $data[$key] ?? [], $idMaps);
        }

        // 3. Import transactions with ID remapping
        $txResult = $this->importTransactions($userId, $data['transactions'] ?? [], $idMaps);
        $idMaps['transactions'] = $txResult['map'];
        // Restore transfer pair links between the freshly imported rows (#351)
        $this->fixupTransactionColumn('linked_transaction_id', $txResult['links'], $txResult['map']);

        // 4. Import bills with ID remapping
        $idMaps['bills'] = $this->importBills($userId, $data['bills'] ?? [], $idMaps);

        // 5. Import import rules with ID remapping
        $this->importImportRules($userId, $data['import_rules'] ?? [], $idMaps);

        // 6. Import settings
        $this->importSettings($userId, $data['settings'] ?? []);

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
            $category->setCreatedAt($catData['createdAt'] ?? date('Y-m-d H:i:s'));

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
     * Import accounts.
     *
     * @return array<int, int> Map of old ID => new ID
     */
    private function importAccounts(string $userId, array $accounts): array {
        $idMap = [];

        foreach ($accounts as $accData) {
            $oldId = $accData['id'];

            $account = new Account();
            $account->setUserId($userId);
            $account->setName($accData['name']);
            $account->setType($accData['type']);
            // Sign the balance through the single authority. Exports carrying an
            // explicit in-credit declaration are honoured; legacy exports
            // (pre-1.1.0 positive liability balances, and any export predating
            // #353) fall back to "owed", which is what they meant.
            $type = (string) ($accData['type'] ?? '');
            $declared = array_key_exists('liabilityInCredit', $accData) ? $accData['liabilityInCredit'] : null;
            $inCredit = $declared !== null ? (bool) $declared : false;
            $account->setBalance(AccountType::signFor($type, (float) ($accData['balance'] ?? 0), $inCredit));
            $account->setLiabilityInCredit(
                AccountType::tryFrom($type)?->isLiability() ? $declared : null
            );

            $account->setCurrency($accData['currency'] ?? 'USD');
            $account->setInstitution($accData['institution'] ?? null);
            $account->setAccountNumber($accData['accountNumber'] ?? null);
            $account->setRoutingNumber($accData['routingNumber'] ?? null);
            $account->setSortCode($accData['sortCode'] ?? null);
            $account->setIban($accData['iban'] ?? null);
            $account->setSwiftBic($accData['swiftBic'] ?? null);
            $account->setAccountHolderName($accData['accountHolderName'] ?? null);
            $account->setOpeningDate($accData['openingDate'] ?? null);
            $account->setInterestRate($accData['interestRate'] ?? null);
            $account->setCreditLimit($accData['creditLimit'] ?? null);
            $account->setOverdraftLimit($accData['overdraftLimit'] ?? null);
            $account->setMinimumPayment($accData['minimumPayment'] ?? null);
            $account->setStatementDay(isset($accData['statementDay']) ? (int) $accData['statementDay'] : null);
            $account->setCreatedAt($accData['createdAt'] ?? date('Y-m-d H:i:s'));
            $account->setUpdatedAt($accData['updatedAt'] ?? date('Y-m-d H:i:s'));

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
     *   map: old id => new id; links: NEW id => OLD linkedTransactionId;
     *   billRefs: NEW id => OLD billId. Links and bill references are
     *   restored in a fixup pass once both sides have new ids — dropping
     *   them unlinked every transfer pair on migration (#351), which then
     *   counted as income/expense in reports.
     */
    private function importTransactions(string $userId, array $transactions, array $idMaps): array {
        $map = [];
        $links = [];
        $billRefs = [];
        $reconRefs = [];
        $pensionRefs = [];
        foreach ($transactions as $txnData) {
            // Skip if account doesn't exist in map (shouldn't happen with valid export)
            $oldAccountId = $txnData['accountId'];
            if (!isset($idMaps['accounts'][$oldAccountId])) {
                continue;
            }

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
            $transaction->setReconciled($txnData['reconciled'] ?? false);
            // Restore status — dropping it turned scheduled transactions into
            // cleared ones, silently corrupting balances after a migration (#274)
            $transaction->setStatus($txnData['status'] ?? null);
            $transaction->setExcludedFromForecast(!empty($txnData['excludedFromForecast']));
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

            $inserted = $this->transactionMapper->insert($transaction);
            if (isset($txnData['id'])) {
                $map[(int) $txnData['id']] = $inserted->getId();
            }
            if (!empty($txnData['linkedTransactionId'])) {
                $links[$inserted->getId()] = (int) $txnData['linkedTransactionId'];
            }
            if (!empty($txnData['billId'])) {
                $billRefs[$inserted->getId()] = (int) $txnData['billId'];
            }
            if (!empty($txnData['reconSessionId'])) {
                $reconRefs[$inserted->getId()] = (int) $txnData['reconSessionId'];
            }
            if (!empty($txnData['pensionContribId'])) {
                $pensionRefs[$inserted->getId()] = (int) $txnData['pensionContribId'];
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
                $splitParentIds[] = (int) $row['transaction_id'];
            }
            $result->closeCursor();
        }

        if ($splitParentIds === []) {
            return;
        }

        foreach (array_chunk($splitParentIds, 500) as $chunk) {
            $update = $this->db->getQueryBuilder();
            $update->update('budget_transactions')
                ->set('is_split', $update->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL))
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
        foreach ($spec['fk'] ?? [] as $column => $fkSpec) {
            $value = $row[$column] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $mapped = $idMaps[$fkSpec['map']][(int) $value] ?? null;
            if ($mapped !== null) {
                $row[$column] = $mapped;
            } elseif (($fkSpec['onMissing'] ?? 'null') === 'drop') {
                return null;
            } else {
                $row[$column] = null;
            }
        }

        foreach ($spec['jsonFk'] ?? [] as $column => $fkSpec) {
            $raw = $row[$column] ?? null;
            if ($raw === null || $raw === '') {
                continue;
            }
            $decoded = json_decode((string) $raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $map = $idMaps[$fkSpec['map']] ?? [];
            $shape = $fkSpec['shape'] ?? 'idList';
            $remapped = [];
            if ($shape === 'idKeyedObject') {
                foreach ($decoded as $oldId => $v) {
                    if (isset($map[(int) $oldId])) {
                        $remapped[$map[(int) $oldId]] = $v;
                    }
                }
            } elseif ($shape === 'idValuedObject') {
                foreach ($decoded as $k => $oldId) {
                    if (isset($map[(int) $oldId])) {
                        $remapped[$k] = $map[(int) $oldId];
                    }
                }
            } else {
                foreach ($decoded as $oldId) {
                    if (isset($map[(int) $oldId])) {
                        $remapped[] = $map[(int) $oldId];
                    }
                }
            }
            $row[$column] = json_encode($remapped);
        }

        return $row;
    }

    /**
     * Export one registry table's rows for the user (raw columns, snake_case).
     * user_id is dropped (reassigned on import); id is kept for remapping.
     */
    private function exportTable(string $userId, array $spec): array {
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

        $result = $qb->executeQuery();
        $rows = [];
        while ($row = $result->fetch()) {
            unset($row['user_id']);
            $rows[] = $row;
        }
        $result->closeCursor();
        return $rows;
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
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $row = $this->remapRow($row, $spec, $idMaps);
            if ($row === null) {
                continue;
            }
            $oldId = isset($row['id']) ? (int) $row['id'] : null;
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
                $qb->setValue($column, $qb->createNamedParameter($value));
            }
            $qb->executeStatement();
            $count++;

            if (isset($spec['idMap']) && $oldId !== null) {
                $idMaps[$spec['idMap']][$oldId] = (int) $this->db->lastInsertId('*PREFIX*' . $spec['table']);
            }
        }
        return $count;
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
     * Delete one registry table's rows for the user (import is
     * wipe-then-restore). Join-scoped tables are cleared through their
     * parent chain and must be cleared before the parents are.
     */
    private function clearTable(string $userId, array $spec): void {
        if (($spec['scope'] ?? 'user') === 'user') {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($spec['table'])
                ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
            $qb->executeStatement();
            return;
        }

        $joins = $spec['scope']['joins'];
        // Innermost select: ids of the deepest parent owned by the user
        [$deepTable] = $joins[count($joins) - 1];
        $sql = 'SELECT id FROM *PREFIX*' . $deepTable . ' WHERE user_id = ?';
        // Wrap outward through the chain
        for ($i = count($joins) - 2; $i >= 0; $i--) {
            [$table] = $joins[$i];
            [, $childColumn] = $joins[$i + 1];
            $sql = 'SELECT id FROM *PREFIX*' . $table . ' WHERE ' . $childColumn . ' IN (' . $sql . ')';
        }
        [, $localColumn] = $joins[0];
        $this->db->executeStatement(
            'DELETE FROM *PREFIX*' . $spec['table'] . ' WHERE ' . $localColumn . ' IN (' . $sql . ')',
            [$userId]
        );
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
                if (isset($idMaps['tags'][(int) $oldTagId])) {
                    $newTagIds[] = $idMaps['tags'][(int) $oldTagId];
                }
            }
            $bill->setTagIdsArray($newTagIds);
            $bill->setStartDate($billData['startDate'] ?? null);
            $bill->setEndDate($billData['endDate'] ?? null);
            $bill->setRemainingPayments($billData['remainingPayments'] ?? null);
            $bill->setSplitTemplateArray(is_array($billData['splitTemplate'] ?? null) ? $billData['splitTemplate'] : null);
            $bill->setExcludedFromForecast(filter_var($billData['excludedFromForecast'] ?? false, FILTER_VALIDATE_BOOLEAN));
            $bill->setCreateTransaction(filter_var($billData['createTransaction'] ?? true, FILTER_VALIDATE_BOOLEAN));
            $bill->setCreatedAt($billData['createdAt'] ?? date('Y-m-d H:i:s'));

            // Remap category ID
            $oldCategoryId = $billData['categoryId'] ?? null;
            if ($oldCategoryId !== null && isset($idMaps['categories'][$oldCategoryId])) {
                $bill->setCategoryId($idMaps['categories'][$oldCategoryId]);
            }

            // Remap account ID
            $oldAccountId = $billData['accountId'] ?? null;
            if ($oldAccountId !== null && isset($idMaps['accounts'][$oldAccountId])) {
                $bill->setAccountId($idMaps['accounts'][$oldAccountId]);
            }

            // Remap destination account ID (transfer bills)
            $oldDestId = $billData['destinationAccountId'] ?? null;
            if ($oldDestId !== null && isset($idMaps['accounts'][$oldDestId])) {
                $bill->setDestinationAccountId($idMaps['accounts'][$oldDestId]);
            }

            $inserted = $this->billMapper->insert($bill);
            if (isset($billData['id'])) {
                $map[(int) $billData['id']] = $inserted->getId();
            }
        }

        return $map;
    }

    /**
     * Import import rules with ID remapping.
     */
    private function importImportRules(string $userId, array $rules, array $idMaps): void {
        foreach ($rules as $ruleData) {
            $rule = new ImportRule();
            $rule->setUserId($userId);
            $rule->setName($ruleData['name']);
            $rule->setPattern($ruleData['pattern'] ?? '');
            $rule->setField($ruleData['field'] ?? 'description');
            $rule->setMatchType($ruleData['matchType'] ?? 'contains');
            $rule->setVendorName($ruleData['vendorName'] ?? null);
            $rule->setPriority($ruleData['priority'] ?? 0);
            $rule->setActive($ruleData['active'] ?? true);
            $rule->setCreatedAt($ruleData['createdAt'] ?? date('Y-m-d H:i:s'));
            $rule->setUpdatedAt($ruleData['updatedAt'] ?? null);
            $rule->setSchemaVersion($ruleData['schemaVersion'] ?? 1);
            $rule->setApplyOnImport($ruleData['applyOnImport'] ?? true);
            $rule->setStopProcessing($ruleData['stopProcessing'] ?? true);

            // Remap legacy category ID
            $oldCategoryId = $ruleData['categoryId'] ?? null;
            if ($oldCategoryId !== null && isset($idMaps['categories'][$oldCategoryId])) {
                $rule->setCategoryId($idMaps['categories'][$oldCategoryId]);
            }

            // Import actions with ID remapping
            if (isset($ruleData['actions']) && is_array($ruleData['actions'])) {
                $actions = $ruleData['actions'];

                // Legacy v1 flat format: {categoryId: 5, vendor: "X"}
                if (isset($actions['categoryId']) && isset($idMaps['categories'][$actions['categoryId']])) {
                    $actions['categoryId'] = $idMaps['categories'][$actions['categoryId']];
                }

                // v2 nested format: {version: 2, actions: [{type, value}, ...]}
                if (isset($actions['actions']) && is_array($actions['actions'])) {
                    foreach ($actions['actions'] as &$action) {
                        $type = $action['type'] ?? null;
                        $value = $action['value'] ?? null;
                        if ($value === null) {
                            continue;
                        }
                        if ($type === 'set_category' && isset($idMaps['categories'][$value])) {
                            $action['value'] = $idMaps['categories'][$value];
                        } elseif ($type === 'set_account' && isset($idMaps['accounts'][$value])) {
                            $action['value'] = $idMaps['accounts'][$value];
                        }
                    }
                    unset($action);
                }

                $rule->setActionsFromArray($actions);
            }

            // Import criteria (matching conditions, no ID remapping needed)
            if (isset($ruleData['criteria']) && is_array($ruleData['criteria'])) {
                $rule->setCriteriaFromArray($ruleData['criteria']);
            }

            $this->importRuleMapper->insert($rule);
        }
    }

    /**
     * Import settings.
     */
    private function importSettings(string $userId, array $settings): void {
        $now = date('Y-m-d H:i:s');

        foreach ($settings as $key => $value) {
            $setting = new Setting();
            $setting->setUserId($userId);
            $setting->setKey($key);
            $setting->setValue((string) $value);
            $setting->setCreatedAt($now);
            $setting->setUpdatedAt($now);
            $this->settingMapper->insert($setting);
        }
    }
}
