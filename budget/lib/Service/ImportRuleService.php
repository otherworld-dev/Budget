<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Import\CriteriaEvaluator;
use OCA\Budget\Service\Import\RuleActionApplicator;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IQueryBuilder;
use OCP\IDBConnection;

class ImportRuleService {
    private ImportRuleMapper $mapper;
    private CategoryMapper $categoryMapper;
    private TransactionMapper $transactionMapper;
    private IDBConnection $db;
    private CriteriaEvaluator $criteriaEvaluator;
    private RuleActionApplicator $actionApplicator;

    public function __construct(
        ImportRuleMapper $mapper,
        CategoryMapper $categoryMapper,
        TransactionMapper $transactionMapper,
        IDBConnection $db,
        CriteriaEvaluator $criteriaEvaluator,
        RuleActionApplicator $actionApplicator
    ) {
        $this->mapper = $mapper;
        $this->categoryMapper = $categoryMapper;
        $this->transactionMapper = $transactionMapper;
        $this->db = $db;
        $this->criteriaEvaluator = $criteriaEvaluator;
        $this->actionApplicator = $actionApplicator;
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): ImportRule {
        return $this->mapper->find($id, $userId);
    }

    public function findAll(string $userId): array {
        return $this->mapper->findAll($userId);
    }

    public function create(
        string $userId,
        string $name,
        ?string $pattern = null,
        ?string $field = null,
        ?string $matchType = null,
        ?array $criteria = null,
        int $schemaVersion = 1,
        ?int $categoryId = null,
        ?string $vendorName = null,
        int $priority = 0,
        ?array $actions = null,
        bool $applyOnImport = true,
        bool $stopProcessing = true
    ): ImportRule {
        // Validate based on schema version
        if ($schemaVersion === 2) {
            // v2 format: criteria required
            if ($criteria === null) {
                throw new \InvalidArgumentException('Criteria required for v2 rules');
            }

            // Validate criteria structure
            $validation = $this->criteriaEvaluator->validate($criteria);
            if (!$validation['valid']) {
                throw new \InvalidArgumentException('Invalid criteria: ' . implode(', ', $validation['errors']));
            }

            // Validate actions if provided
            if ($actions !== null) {
                $actionValidation = $this->actionApplicator->validateActions($actions, $userId);
                if (!$actionValidation['valid']) {
                    throw new \InvalidArgumentException('Invalid actions: ' . implode(', ', $actionValidation['errors']));
                }
            }
        } else {
            // v1 format: pattern, field, matchType required
            if (!$pattern || !$field || !$matchType) {
                throw new \InvalidArgumentException('Pattern, field, and matchType required for v1 rules');
            }

            // Validate category if provided (either in categoryId or actions)
            $effectiveCategoryId = $categoryId;
            if ($actions !== null && isset($actions['categoryId'])) {
                $effectiveCategoryId = $actions['categoryId'];
            }
            if ($effectiveCategoryId !== null) {
                $this->categoryMapper->find($effectiveCategoryId, $userId);
            }

            // Validate match type
            $validMatchTypes = ['contains', 'starts_with', 'ends_with', 'equals', 'regex', 'exact'];
            if (!in_array($matchType, $validMatchTypes)) {
                throw new \InvalidArgumentException('Invalid match type');
            }

            // Validate field
            $validFields = ['description', 'vendor', 'amount', 'reference', 'notes'];
            if (!in_array($field, $validFields)) {
                throw new \InvalidArgumentException('Invalid field');
            }
        }

        $rule = new ImportRule();
        $rule->setUserId($userId);
        $rule->setName($name);
        $rule->setPattern($pattern ?? '');
        $rule->setField($field ?? 'description');
        $rule->setMatchType($matchType ?? 'contains');
        $rule->setCategoryId($categoryId);
        $rule->setVendorName($vendorName);
        $rule->setPriority($priority);
        $rule->setActive(true);
        $rule->setApplyOnImport($applyOnImport);
        $rule->setSchemaVersion($schemaVersion);
        $rule->setStopProcessing($stopProcessing);
        $rule->setCreatedAt(date('Y-m-d H:i:s'));

        // Set criteria JSON for v2 rules
        if ($criteria !== null) {
            $rule->setCriteriaFromArray($criteria);
        }

        // Set actions JSON if provided
        if ($actions !== null) {
            $rule->setActionsFromArray($actions);
        }

        return $this->mapper->insert($rule);
    }

    public function update(int $id, string $userId, array $updates): ImportRule {
        $rule = $this->find($id, $userId);

        // Determine if upgrading from v1 to v2
        $currentVersion = $rule->getSchemaVersion() ?? 1;
        $newVersion = $updates['schemaVersion'] ?? $currentVersion;

        // Validate based on schema version
        if ($newVersion === 2) {
            // Validate criteria if being updated
            if (isset($updates['criteria'])) {
                $validation = $this->criteriaEvaluator->validate($updates['criteria']);
                if (!$validation['valid']) {
                    throw new \InvalidArgumentException('Invalid criteria: ' . implode(', ', $validation['errors']));
                }
            }

            // Validate actions if being updated
            if (isset($updates['actions'])) {
                $actionValidation = $this->actionApplicator->validateActions($updates['actions'], $userId);
                if (!$actionValidation['valid']) {
                    throw new \InvalidArgumentException('Invalid actions: ' . implode(', ', $actionValidation['errors']));
                }
            }
        } else {
            // v1 validation (existing logic)
            // Validate category if being updated (either in categoryId or actions)
            if (isset($updates['categoryId']) && $updates['categoryId'] !== null) {
                $this->categoryMapper->find($updates['categoryId'], $userId);
            }
            if (isset($updates['actions']) && isset($updates['actions']['categoryId'])) {
                $this->categoryMapper->find($updates['actions']['categoryId'], $userId);
            }

            // Validate match type if being updated
            if (isset($updates['matchType'])) {
                $validMatchTypes = ['contains', 'starts_with', 'ends_with', 'equals', 'regex', 'exact'];
                if (!in_array($updates['matchType'], $validMatchTypes)) {
                    throw new \InvalidArgumentException('Invalid match type');
                }
            }

            // Validate field if being updated
            if (isset($updates['field'])) {
                $validFields = ['description', 'vendor', 'amount', 'reference', 'notes'];
                if (!in_array($updates['field'], $validFields)) {
                    throw new \InvalidArgumentException('Invalid field');
                }
            }
        }

        // Handle criteria array specially - convert to JSON
        if (isset($updates['criteria']) && is_array($updates['criteria'])) {
            $rule->setCriteriaFromArray($updates['criteria']);
            unset($updates['criteria']);
        }

        // Handle actions array specially - convert to JSON
        if (isset($updates['actions']) && is_array($updates['actions'])) {
            $rule->setActionsFromArray($updates['actions']);
            unset($updates['actions']);
        }

        // Set updated_at timestamp
        $rule->setUpdatedAt(date('Y-m-d H:i:s'));

        foreach ($updates as $key => $value) {
            $setter = 'set' . ucfirst($key);
            if (method_exists($rule, $setter)) {
                $rule->$setter($value);
            }
        }

        return $this->mapper->update($rule);
    }

    public function delete(int $id, string $userId): void {
        $rule = $this->find($id, $userId);
        $this->mapper->delete($rule);
    }

    public function testRules(string $userId, array $transactionData): array {
        $rules = $this->mapper->findActive($userId);
        $results = [];
        
        foreach ($rules as $rule) {
            $matches = $this->testRule($rule, $transactionData);
            if ($matches) {
                $results[] = [
                    'ruleId' => $rule->getId(),
                    'ruleName' => $rule->getName(),
                    'categoryId' => $rule->getCategoryId(),
                    'vendorName' => $rule->getVendorName(),
                    'priority' => $rule->getPriority()
                ];
            }
        }
        
        // Sort by priority (highest first)
        usort($results, function($a, $b) {
            return $b['priority'] - $a['priority'];
        });
        
        return $results;
    }

    private function testRule(ImportRule $rule, array $data): bool {
        $schemaVersion = $rule->getSchemaVersion() ?? 1;

        if ($schemaVersion === 2) {
            // v2 format: use CriteriaEvaluator
            $criteria = $rule->getCriteria();
            return $this->criteriaEvaluator->evaluate($criteria, $data, $schemaVersion);
        } else {
            // v1 format: legacy evaluation
            $criteria = [
                'field' => $rule->getField(),
                'pattern' => $rule->getPattern(),
                'matchType' => $rule->getMatchType()
            ];
            return $this->criteriaEvaluator->evaluate($criteria, $data, $schemaVersion);
        }
    }

    public function createDefaultRules(string $userId): array {
        $defaultRules = [
            [
                'name' => 'Grocery Stores',
                'pattern' => 'grocery|supermarket|safeway|kroger|trader joe|whole foods',
                'field' => 'description',
                'matchType' => 'regex',
                'categoryName' => 'Groceries',
                'priority' => 10
            ],
            [
                'name' => 'Gas Stations',
                'pattern' => 'gas|fuel|shell|chevron|exxon|bp|mobil',
                'field' => 'description', 
                'matchType' => 'regex',
                'categoryName' => 'Gas',
                'priority' => 10
            ],
            [
                'name' => 'Restaurants',
                'pattern' => 'restaurant|cafe|coffee|starbucks|mcdonald|burger',
                'field' => 'description',
                'matchType' => 'regex', 
                'categoryName' => 'Dining Out',
                'priority' => 8
            ],
            [
                'name' => 'Online Shopping',
                'pattern' => 'amazon|ebay|paypal|stripe',
                'field' => 'description',
                'matchType' => 'regex',
                'categoryName' => 'Shopping',
                'priority' => 5
            ],
            [
                'name' => 'Utilities',
                'pattern' => 'electric|water|gas|utility|power|energy',
                'field' => 'description',
                'matchType' => 'regex',
                'categoryName' => 'Utilities',
                'priority' => 9
            ],
            [
                'name' => 'ATM Withdrawals',
                'pattern' => 'ATM|withdrawal|cash',
                'field' => 'description',
                'matchType' => 'regex',
                'categoryName' => 'Cash',
                'priority' => 7
            ]
        ];

        $created = [];
        foreach ($defaultRules as $ruleData) {
            try {
                // Find category by name (this is simplified - in practice you'd need better category matching)
                $categoryId = null; // Would need to implement category lookup
                
                $rule = $this->create(
                    $userId,
                    $ruleData['name'],
                    $ruleData['pattern'],
                    $ruleData['field'],
                    $ruleData['matchType'],
                    $categoryId,
                    null,
                    $ruleData['priority']
                );
                
                $created[] = $rule;
            } catch (\Exception $e) {
                // Skip if category not found or other error
                continue;
            }
        }

        return $created;
    }

    /**
     * Find transactions matching the given filters
     *
     * @param string $userId
     * @param array $filters ['accountId' => ?int, 'startDate' => ?string, 'endDate' => ?string, 'uncategorizedOnly' => bool]
     * @return Transaction[]
     */
    public function findTransactionsForRules(string $userId, array $filters): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.*')
            ->from('budget_transactions', 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
            ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));

        // Filter by account
        if (!empty($filters['accountId'])) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($filters['accountId'], IQueryBuilder::PARAM_INT)));
        }

        // Filter by date range
        if (!empty($filters['startDate'])) {
            $qb->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($filters['startDate'])));
        }
        if (!empty($filters['endDate'])) {
            $qb->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($filters['endDate'])));
        }

        // Filter uncategorized only
        if (!empty($filters['uncategorizedOnly'])) {
            $qb->andWhere($qb->expr()->isNull('t.category_id'));
        }

        $qb->orderBy('t.date', 'DESC');

        $result = $qb->executeQuery();
        $transactions = [];
        while ($row = $result->fetch()) {
            $transaction = Transaction::fromRow($row);
            $transactions[] = $transaction;
        }
        $result->closeCursor();

        return $transactions;
    }

    /**
     * Preview rule application without modifying transactions
     *
     * @param string $userId
     * @param array $ruleIds Specific rule IDs to apply (empty = all active)
     * @param array $filters Transaction filters
     * @return array Preview results with matched transactions and changes
     */
    public function previewRuleApplication(string $userId, array $ruleIds, array $filters): array {
        $transactions = $this->findTransactionsForRules($userId, $filters);

        // Get rules to apply
        if (empty($ruleIds)) {
            $rules = $this->mapper->findActive($userId);
        } else {
            $rules = [];
            foreach ($ruleIds as $ruleId) {
                try {
                    $rule = $this->find($ruleId, $userId);
                    if ($rule->getActive()) {
                        $rules[] = $rule;
                    }
                } catch (DoesNotExistException $e) {
                    continue;
                }
            }
        }

        // Sort rules by priority (highest first)
        usort($rules, function($a, $b) {
            return $b->getPriority() - $a->getPriority();
        });

        $preview = [];
        $matchCount = 0;

        foreach ($transactions as $transaction) {
            $transactionData = [
                'description' => $transaction->getDescription(),
                'vendor' => $transaction->getVendor(),
                'amount' => $transaction->getAmount(),
                'reference' => $transaction->getReference(),
                'notes' => $transaction->getNotes(),
            ];

            foreach ($rules as $rule) {
                if ($this->testRule($rule, $transactionData)) {
                    $actions = $rule->getParsedActions();
                    $changes = [];

                    // Determine what would change
                    if (isset($actions['categoryId']) && $actions['categoryId'] !== $transaction->getCategoryId()) {
                        $changes['categoryId'] = [
                            'from' => $transaction->getCategoryId(),
                            'to' => $actions['categoryId']
                        ];
                    }
                    if (isset($actions['vendor']) && $actions['vendor'] !== $transaction->getVendor()) {
                        $changes['vendor'] = [
                            'from' => $transaction->getVendor(),
                            'to' => $actions['vendor']
                        ];
                    }
                    if (isset($actions['notes']) && $actions['notes'] !== $transaction->getNotes()) {
                        $changes['notes'] = [
                            'from' => $transaction->getNotes(),
                            'to' => $actions['notes']
                        ];
                    }

                    if (!empty($changes)) {
                        $preview[] = [
                            'transactionId' => $transaction->getId(),
                            'transactionDescription' => $transaction->getDescription(),
                            'transactionDate' => $transaction->getDate(),
                            'transactionAmount' => $transaction->getAmount(),
                            'ruleId' => $rule->getId(),
                            'ruleName' => $rule->getName(),
                            'changes' => $changes
                        ];
                        $matchCount++;
                    }
                    break; // First matching rule wins
                }
            }
        }

        return [
            'totalTransactions' => count($transactions),
            'matchCount' => $matchCount,
            'preview' => $preview
        ];
    }

    /**
     * Apply rules to existing transactions
     * Supports multiple matching rules with conflict resolution
     *
     * @param string $userId
     * @param array $ruleIds Specific rule IDs to apply (empty = all active)
     * @param array $filters Transaction filters
     * @return array Results with success/failure counts
     */
    public function applyRulesToTransactions(string $userId, array $ruleIds, array $filters): array {
        $transactions = $this->findTransactionsForRules($userId, $filters);

        // Get rules to apply
        if (empty($ruleIds)) {
            $rules = $this->mapper->findActive($userId);
        } else {
            $rules = [];
            foreach ($ruleIds as $ruleId) {
                try {
                    $rule = $this->find($ruleId, $userId);
                    if ($rule->getActive()) {
                        $rules[] = $rule;
                    }
                } catch (DoesNotExistException $e) {
                    continue;
                }
            }
        }

        // Sort rules by priority (highest first)
        usort($rules, function($a, $b) {
            return $b->getPriority() - $a->getPriority();
        });

        $success = 0;
        $failed = 0;
        $skipped = 0;
        $applied = [];

        foreach ($transactions as $transaction) {
            $transactionData = $this->extractTransactionData($transaction);

            // Find all matching rules
            $matchingRules = [];
            foreach ($rules as $rule) {
                if ($this->testRule($rule, $transactionData)) {
                    $matchingRules[] = $rule;

                    // Check stop_processing flag
                    if ($rule->getStopProcessing() ?? true) {
                        break; // Don't evaluate more rules
                    }
                }
            }

            if (empty($matchingRules)) {
                $skipped++;
                continue;
            }

            try {
                // Apply all matching rules
                $changes = $this->actionApplicator->applyRules($transaction, $matchingRules, $userId);

                if (!empty($changes)) {
                    $transaction->setUpdatedAt(date('Y-m-d H:i:s'));
                    $updatedTransaction = $this->transactionMapper->update($transaction);

                    // Apply deferred tag actions after transaction is persisted
                    $appliedActions = $changes['_appliedActions'] ?? [];
                    $this->actionApplicator->applyDeferredTagActions($updatedTransaction, $appliedActions, $userId, $changes);

                    $success++;
                    $applied[] = [
                        'transactionId' => $updatedTransaction->getId(),
                        'date' => $updatedTransaction->getDate(),
                        'description' => $updatedTransaction->getDescription(),
                        'amount' => $updatedTransaction->getAmount(),
                        'categoryId' => $updatedTransaction->getCategoryId(),
                        'rules' => array_map(fn($r) => ['id' => $r->getId(), 'name' => $r->getName()], $matchingRules),
                        'changes' => $changes
                    ];
                } else {
                    $skipped++;
                }
            } catch (\Exception $e) {
                $failed++;
            }
        }

        return [
            'totalTransactions' => count($transactions),
            'success' => $success,
            'failed' => $failed,
            'skipped' => $skipped,
            'applied' => $applied
        ];
    }

    /**
     * Extract transaction data as array for rule evaluation
     *
     * @param Transaction $transaction
     * @return array
     */
    private function extractTransactionData(Transaction $transaction): array {
        return [
            'description' => $transaction->getDescription(),
            'vendor' => $transaction->getVendor() ?? '',
            'amount' => $transaction->getAmount(),
            'reference' => $transaction->getReference() ?? '',
            'notes' => $transaction->getNotes() ?? '',
            'date' => $transaction->getDate(),
            'account_type' => '' // Could be enriched with account type if needed
        ];
    }

    /**
     * Get active rules for a user
     */
    public function findActive(string $userId): array {
        return $this->mapper->findActive($userId);
    }

    /**
     * Test unsaved rule criteria against existing transactions
     *
     * @param string $userId User ID
     * @param array $criteria Rule criteria (v2 format)
     * @param int $schemaVersion Schema version (1 or 2)
     * @param array $filters Transaction filters
     * @param int $limit Maximum number of matching transactions to return
     * @return array Array with matching transactions
     */
    public function testUnsavedRule(string $userId, array $criteria, int $schemaVersion, array $filters, int $limit = 50): array {
        // Validate criteria
        if ($schemaVersion === 2) {
            $validation = $this->criteriaEvaluator->validate($criteria);
            if (!$validation['valid']) {
                throw new \InvalidArgumentException('Invalid criteria: ' . implode(', ', $validation['errors']));
            }
        }

        // Find transactions matching filters
        $transactions = $this->findTransactionsForRules($userId, $filters);

        $matches = [];
        $count = 0;

        foreach ($transactions as $transaction) {
            if ($count >= $limit) {
                break;
            }

            $transactionData = $this->extractTransactionData($transaction);

            // Test criteria against transaction
            $isMatch = false;
            if ($schemaVersion === 2) {
                $isMatch = $this->criteriaEvaluator->evaluate($criteria, $transactionData, $schemaVersion);
            } else {
                // v1 format (if needed for backwards compatibility)
                $isMatch = $this->criteriaEvaluator->evaluate($criteria, $transactionData, $schemaVersion);
            }

            if ($isMatch) {
                $matches[] = [
                    'id' => $transaction->getId(),
                    'date' => $transaction->getDate(),
                    'description' => $transaction->getDescription(),
                    'vendor' => $transaction->getVendor(),
                    'amount' => $transaction->getAmount(),
                    'categoryId' => $transaction->getCategoryId(),
                    'accountId' => $transaction->getAccountId(),
                ];
                $count++;
            }
        }

        return [
            'totalMatches' => $count,
            'matches' => $matches,
            'limitReached' => $count >= $limit
        ];
    }

    /**
     * Migrate a legacy v1 rule to v2 format
     *
     * @param int $ruleId Rule ID to migrate
     * @param string $userId User ID
     * @return ImportRule Migrated rule
     * @throws DoesNotExistException
     */
    public function migrateLegacyRule(int $ruleId, string $userId): ImportRule {
        $rule = $this->find($ruleId, $userId);

        // Check if already properly migrated
        if ($rule->getSchemaVersion() === 2 && $rule->getCriteria() !== null && $rule->getCriteria() !== '') {
            // Also check if criteria has valid structure (root must be a group, not a condition)
            $parsedCriteria = $rule->getParsedCriteria();
            if ($parsedCriteria && isset($parsedCriteria['root']) && isset($parsedCriteria['root']['operator'])) {
                // Valid v2 structure - no need to re-migrate
                return $rule;
            }
            // Has criteria but invalid structure (old broken migration) - fall through to re-migrate
        }

        // Convert field/pattern/matchType to criteria tree
        // Wrap single condition in a group for CriteriaBuilder compatibility
        $criteria = [
            'version' => 2,
            'root' => [
                'operator' => 'AND',
                'conditions' => [
                    [
                        'type' => 'condition',
                        'field' => $rule->getField(),
                        'matchType' => $rule->getMatchType(),
                        'pattern' => $rule->getPattern(),
                        'negate' => false
                    ]
                ]
            ]
        ];

        // Convert legacy actions to v2 format
        $legacyActions = $rule->getParsedActions();
        $actions = [
            'version' => 2,
            'stopProcessing' => true, // Default for migrated rules
            'actions' => []
        ];

        if (isset($legacyActions['categoryId']) && $legacyActions['categoryId'] !== null) {
            $actions['actions'][] = [
                'type' => 'set_category',
                'value' => $legacyActions['categoryId'],
                'behavior' => 'always',
                'priority' => 100
            ];
        }

        if (isset($legacyActions['vendor']) && $legacyActions['vendor'] !== null && $legacyActions['vendor'] !== '') {
            $actions['actions'][] = [
                'type' => 'set_vendor',
                'value' => $legacyActions['vendor'],
                'behavior' => 'always',
                'priority' => 90
            ];
        }

        if (isset($legacyActions['notes']) && $legacyActions['notes'] !== null && $legacyActions['notes'] !== '') {
            $actions['actions'][] = [
                'type' => 'set_notes',
                'value' => $legacyActions['notes'],
                'behavior' => 'always',
                'priority' => 80
            ];
        }

        // Update the rule - explicitly set all fields to ensure they're saved
        $rule->setCriteriaFromArray($criteria);
        $rule->setActionsFromArray($actions);
        $rule->setSchemaVersion(2);
        $rule->setStopProcessing(true);
        $rule->setUpdatedAt(date('Y-m-d H:i:s'));

        return $this->mapper->update($rule);
    }

    /**
     * Batch migrate all legacy rules for a user
     *
     * @param string $userId User ID
     * @return array Array of migrated rule IDs
     */
    public function migrateAllLegacyRules(string $userId): array {
        $rules = $this->mapper->findAll($userId);
        $migrated = [];

        foreach ($rules as $rule) {
            // Only migrate v1 rules
            if (($rule->getSchemaVersion() ?? 1) === 1) {
                try {
                    $this->migrateLegacyRule($rule->getId(), $userId);
                    $migrated[] = $rule->getId();
                } catch (\Exception $e) {
                    // Log error but continue with other rules
                    continue;
                }
            }
        }

        return $migrated;
    }
}