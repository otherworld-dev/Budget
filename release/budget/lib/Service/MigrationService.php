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
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCP\IDBConnection;

/**
 * Service for exporting and importing all user data for migration between instances.
 */
class MigrationService {
    private const EXPORT_VERSION = '1.0.0';
    private const APP_ID = 'budget';

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

            $this->db->commit();

            return [
                'success' => true,
                'message' => 'Import completed successfully',
                'counts' => [
                    'categories' => count($importData['categories'] ?? []),
                    'accounts' => count($importData['accounts'] ?? []),
                    'transactions' => count($importData['transactions'] ?? []),
                    'bills' => count($importData['bills'] ?? []),
                    'importRules' => count($importData['import_rules'] ?? []),
                    'settings' => count($importData['settings'] ?? [])
                ]
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
            'counts' => [
                'categories' => count($importData['categories'] ?? []),
                'accounts' => count($importData['accounts'] ?? []),
                'transactions' => count($importData['transactions'] ?? []),
                'bills' => count($importData['bills'] ?? []),
                'importRules' => count($importData['import_rules'] ?? []),
                'settings' => count($importData['settings'] ?? [])
            ],
            'warnings' => $warnings
        ];
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

        $manifest = [
            'version' => self::EXPORT_VERSION,
            'appId' => self::APP_ID,
            'exportedAt' => date('c'),
            'counts' => [
                'categories' => count($categoriesData),
                'accounts' => count($accountsData),
                'transactions' => count($transactionsData),
                'bills' => count($billsData),
                'importRules' => count($importRulesData),
                'settings' => count($settingsData)
            ]
        ];

        return [
            'manifest' => $manifest,
            'categories' => $categoriesData,
            'accounts' => $accountsData,
            'transactions' => $transactionsData,
            'bills' => $billsData,
            'import_rules' => $importRulesData,
            'settings' => $settingsData
        ];
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

        // Add each data file
        $zip->addFromString('manifest.json', json_encode($data['manifest'], JSON_PRETTY_PRINT));
        $zip->addFromString('categories.json', json_encode($data['categories'], JSON_PRETTY_PRINT));
        $zip->addFromString('accounts.json', json_encode($data['accounts'], JSON_PRETTY_PRINT));
        $zip->addFromString('transactions.json', json_encode($data['transactions'], JSON_PRETTY_PRINT));
        $zip->addFromString('bills.json', json_encode($data['bills'], JSON_PRETTY_PRINT));
        $zip->addFromString('import_rules.json', json_encode($data['import_rules'], JSON_PRETTY_PRINT));
        $zip->addFromString('settings.json', json_encode($data['settings'], JSON_PRETTY_PRINT));

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

        // 3. Import transactions with ID remapping
        $this->importTransactions($userId, $data['transactions'] ?? [], $idMaps);

        // 4. Import bills with ID remapping
        $this->importBills($userId, $data['bills'] ?? [], $idMaps);

        // 5. Import import rules with ID remapping
        $this->importImportRules($userId, $data['import_rules'] ?? [], $idMaps);

        // 6. Import settings
        $this->importSettings($userId, $data['settings'] ?? []);

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
            $account->setBalance($accData['balance'] ?? 0.0);
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
    private function importTransactions(string $userId, array $transactions, array $idMaps): void {
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
            $transaction->setCreatedAt($txnData['createdAt'] ?? date('Y-m-d H:i:s'));
            $transaction->setUpdatedAt($txnData['updatedAt'] ?? date('Y-m-d H:i:s'));

            // Remap category ID
            $oldCategoryId = $txnData['categoryId'] ?? null;
            if ($oldCategoryId !== null && isset($idMaps['categories'][$oldCategoryId])) {
                $transaction->setCategoryId($idMaps['categories'][$oldCategoryId]);
            }

            $this->transactionMapper->insert($transaction);
        }
    }

    /**
     * Import bills with ID remapping.
     */
    private function importBills(string $userId, array $bills, array $idMaps): void {
        foreach ($bills as $billData) {
            $bill = new Bill();
            $bill->setUserId($userId);
            $bill->setName($billData['name']);
            $bill->setAmount($billData['amount'] ?? 0.0);
            $bill->setFrequency($billData['frequency'] ?? 'monthly');
            $bill->setDueDay($billData['dueDay'] ?? null);
            $bill->setDueMonth($billData['dueMonth'] ?? null);
            $bill->setAutoDetectPattern($billData['autoDetectPattern'] ?? null);
            $bill->setIsActive($billData['isActive'] ?? true);
            $bill->setLastPaidDate($billData['lastPaidDate'] ?? null);
            $bill->setNextDueDate($billData['nextDueDate'] ?? null);
            $bill->setNotes($billData['notes'] ?? null);
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

            $this->billMapper->insert($bill);
        }
    }

    /**
     * Import import rules with ID remapping.
     */
    private function importImportRules(string $userId, array $rules, array $idMaps): void {
        foreach ($rules as $ruleData) {
            $rule = new ImportRule();
            $rule->setUserId($userId);
            $rule->setName($ruleData['name']);
            $rule->setPattern($ruleData['pattern']);
            $rule->setField($ruleData['field'] ?? 'description');
            $rule->setMatchType($ruleData['matchType'] ?? 'contains');
            $rule->setVendorName($ruleData['vendorName'] ?? null);
            $rule->setPriority($ruleData['priority'] ?? 0);
            $rule->setActive($ruleData['active'] ?? true);
            $rule->setCreatedAt($ruleData['createdAt'] ?? date('Y-m-d H:i:s'));

            // Remap category ID
            $oldCategoryId = $ruleData['categoryId'] ?? null;
            if ($oldCategoryId !== null && isset($idMaps['categories'][$oldCategoryId])) {
                $rule->setCategoryId($idMaps['categories'][$oldCategoryId]);
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
