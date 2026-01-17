<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\Transaction;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;

class ImportRuleService {
    private ImportRuleMapper $mapper;
    private CategoryMapper $categoryMapper;
    private TransactionMapper $transactionMapper;
    private IDBConnection $db;

    public function __construct(
        ImportRuleMapper $mapper,
        CategoryMapper $categoryMapper,
        TransactionMapper $transactionMapper,
        IDBConnection $db
    ) {
        $this->mapper = $mapper;
        $this->categoryMapper = $categoryMapper;
        $this->transactionMapper = $transactionMapper;
        $this->db = $db;
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
        string $pattern,
        string $field,
        string $matchType,
        ?int $categoryId = null,
        ?string $vendorName = null,
        int $priority = 0,
        ?array $actions = null,
        bool $applyOnImport = true
    ): ImportRule {
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

        $rule = new ImportRule();
        $rule->setUserId($userId);
        $rule->setName($name);
        $rule->setPattern($pattern);
        $rule->setField($field);
        $rule->setMatchType($matchType);
        $rule->setCategoryId($categoryId);
        $rule->setVendorName($vendorName);
        $rule->setPriority($priority);
        $rule->setActive(true);
        $rule->setApplyOnImport($applyOnImport);
        $rule->setCreatedAt(date('Y-m-d H:i:s'));

        // Set actions JSON if provided
        if ($actions !== null) {
            $rule->setActionsFromArray($actions);
        }

        return $this->mapper->insert($rule);
    }

    public function update(int $id, string $userId, array $updates): ImportRule {
        $rule = $this->find($id, $userId);

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
        $field = $rule->getField();
        $pattern = $rule->getPattern();
        $matchType = $rule->getMatchType();
        
        if (!isset($data[$field])) {
            return false;
        }
        
        $value = (string) $data[$field];
        
        switch ($matchType) {
            case 'contains':
                return stripos($value, $pattern) !== false;
            
            case 'starts_with':
                return stripos($value, $pattern) === 0;
            
            case 'ends_with':
                return substr(strtolower($value), -strlen($pattern)) === strtolower($pattern);
            
            case 'equals':
                return strtolower($value) === strtolower($pattern);
            
            case 'regex':
                return preg_match('/' . $pattern . '/i', $value) === 1;
            
            default:
                return false;
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
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($filters['accountId'], \OCP\DB\IQueryBuilder::PARAM_INT)));
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
                    $hasChanges = false;

                    try {
                        // Apply actions
                        if (isset($actions['categoryId']) && $actions['categoryId'] !== $transaction->getCategoryId()) {
                            $transaction->setCategoryId($actions['categoryId']);
                            $hasChanges = true;
                        }
                        if (isset($actions['vendor']) && $actions['vendor'] !== $transaction->getVendor()) {
                            $transaction->setVendor($actions['vendor']);
                            $hasChanges = true;
                        }
                        if (isset($actions['notes'])) {
                            // Append to existing notes if not empty
                            $existingNotes = $transaction->getNotes() ?? '';
                            if ($existingNotes !== $actions['notes']) {
                                $transaction->setNotes($actions['notes']);
                                $hasChanges = true;
                            }
                        }

                        if ($hasChanges) {
                            $transaction->setUpdatedAt(date('Y-m-d H:i:s'));
                            $this->transactionMapper->update($transaction);
                            $success++;
                            $applied[] = [
                                'transactionId' => $transaction->getId(),
                                'ruleId' => $rule->getId(),
                                'ruleName' => $rule->getName()
                            ];
                        } else {
                            $skipped++;
                        }
                    } catch (\Exception $e) {
                        $failed++;
                    }

                    break; // First matching rule wins
                }
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
     * Get active rules for a user
     */
    public function findActive(string $userId): array {
        return $this->mapper->findActive($userId);
    }
}