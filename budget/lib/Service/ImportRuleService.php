<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\CategoryMapper;
use OCP\AppFramework\Db\DoesNotExistException;

class ImportRuleService {
    private ImportRuleMapper $mapper;
    private CategoryMapper $categoryMapper;

    public function __construct(
        ImportRuleMapper $mapper,
        CategoryMapper $categoryMapper
    ) {
        $this->mapper = $mapper;
        $this->categoryMapper = $categoryMapper;
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
        int $priority = 0
    ): ImportRule {
        // Validate category if provided
        if ($categoryId !== null) {
            $this->categoryMapper->find($categoryId, $userId);
        }

        // Validate match type
        $validMatchTypes = ['contains', 'starts_with', 'ends_with', 'equals', 'regex'];
        if (!in_array($matchType, $validMatchTypes)) {
            throw new \InvalidArgumentException('Invalid match type');
        }

        // Validate field
        $validFields = ['description', 'vendor', 'amount', 'reference'];
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
        $rule->setCreatedAt(date('Y-m-d H:i:s'));
        
        return $this->mapper->insert($rule);
    }

    public function update(int $id, string $userId, array $updates): ImportRule {
        $rule = $this->find($id, $userId);
        
        // Validate category if being updated
        if (isset($updates['categoryId']) && $updates['categoryId'] !== null) {
            $this->categoryMapper->find($updates['categoryId'], $userId);
        }

        // Validate match type if being updated
        if (isset($updates['matchType'])) {
            $validMatchTypes = ['contains', 'starts_with', 'ends_with', 'equals', 'regex'];
            if (!in_array($updates['matchType'], $validMatchTypes)) {
                throw new \InvalidArgumentException('Invalid match type');
            }
        }

        // Validate field if being updated
        if (isset($updates['field'])) {
            $validFields = ['description', 'vendor', 'amount', 'reference'];
            if (!in_array($updates['field'], $validFields)) {
                throw new \InvalidArgumentException('Invalid field');
            }
        }
        
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
}