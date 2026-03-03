<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;

/**
 * Applies import rules to automatically categorize and tag transactions during import.
 * Uses v2 schema: CriteriaEvaluator for matching and JSON actions for application.
 */
class ImportRuleApplicator {
    private ImportRuleMapper $importRuleMapper;
    private CriteriaEvaluator $criteriaEvaluator;

    public function __construct(ImportRuleMapper $importRuleMapper, CriteriaEvaluator $criteriaEvaluator) {
        $this->importRuleMapper = $importRuleMapper;
        $this->criteriaEvaluator = $criteriaEvaluator;
    }

    /**
     * Apply matching rules to a single transaction.
     *
     * @param string $userId The user ID
     * @param array $transaction Transaction data
     * @return array Transaction data with rules applied
     */
    public function applyRules(string $userId, array $transaction): array {
        $rules = $this->importRuleMapper->findActive($userId);

        foreach ($rules as $rule) {
            // Skip rules not meant for import
            if ($rule->getApplyOnImport() === false) {
                continue;
            }

            // Match using CriteriaEvaluator
            $criteria = $rule->getCriteria();
            $schemaVersion = $rule->getSchemaVersion() ?? 2;

            if (!$this->criteriaEvaluator->evaluate($criteria, $transaction, $schemaVersion)) {
                continue;
            }

            // Apply v2 actions
            $transaction = $this->applyActions($rule, $transaction);

            // Track which rule was applied
            $transaction['appliedRule'] = [
                'id' => $rule->getId(),
                'name' => $rule->getName(),
            ];

            // Respect stopProcessing flag
            if ($rule->getStopProcessing() ?? true) {
                break;
            }
        }

        return $transaction;
    }

    /**
     * Apply rules to multiple transactions.
     *
     * @param string $userId The user ID
     * @param array $transactions List of transactions
     * @return array List of transactions with rules applied
     */
    public function applyRulesToMany(string $userId, array $transactions): array {
        return array_map(
            fn($transaction) => $this->applyRules($userId, $transaction),
            $transactions
        );
    }

    /**
     * Preview rule applications without modifying transactions.
     *
     * @param string $userId The user ID
     * @param array $transactions List of transactions
     * @return array List of rule matches with transaction indices
     */
    public function previewRuleApplications(string $userId, array $transactions): array {
        $rules = $this->importRuleMapper->findActive($userId);
        $previews = [];

        foreach ($transactions as $index => $transaction) {
            foreach ($rules as $rule) {
                if ($rule->getApplyOnImport() === false) {
                    continue;
                }

                $criteria = $rule->getCriteria();
                $schemaVersion = $rule->getSchemaVersion() ?? 2;

                if ($this->criteriaEvaluator->evaluate($criteria, $transaction, $schemaVersion)) {
                    $previews[] = [
                        'transactionIndex' => $index,
                        'transaction' => $transaction,
                        'rule' => [
                            'id' => $rule->getId(),
                            'name' => $rule->getName(),
                        ],
                    ];
                    break; // First matching rule per transaction
                }
            }
        }

        return $previews;
    }

    /**
     * Get statistics about rule matches for a set of transactions.
     *
     * @param string $userId The user ID
     * @param array $transactions List of transactions
     * @return array Statistics about rule matches
     */
    public function getMatchStatistics(string $userId, array $transactions): array {
        $rules = $this->importRuleMapper->findActive($userId);
        $matched = 0;
        $unmatched = 0;
        $ruleUsage = [];

        foreach ($transactions as $transaction) {
            $found = false;
            foreach ($rules as $rule) {
                if ($rule->getApplyOnImport() === false) {
                    continue;
                }

                $criteria = $rule->getCriteria();
                $schemaVersion = $rule->getSchemaVersion() ?? 2;

                if ($this->criteriaEvaluator->evaluate($criteria, $transaction, $schemaVersion)) {
                    $matched++;
                    $ruleId = $rule->getId();
                    $ruleUsage[$ruleId] = ($ruleUsage[$ruleId] ?? 0) + 1;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $unmatched++;
            }
        }

        return [
            'total' => count($transactions),
            'matched' => $matched,
            'unmatched' => $unmatched,
            'matchRate' => count($transactions) > 0 ? $matched / count($transactions) : 0,
            'ruleUsage' => $ruleUsage,
        ];
    }

    /**
     * Extract and apply v2 actions from a rule to a transaction array.
     */
    private function applyActions(ImportRule $rule, array $transaction): array {
        $actions = $rule->getParsedActions();
        $actionList = [];

        if (isset($actions['version']) && $actions['version'] === 2) {
            $actionList = $actions['actions'] ?? [];
        } elseif (isset($actions['actions'])) {
            $actionList = $actions['actions'];
        }

        // Sort by priority (higher first)
        usort($actionList, fn($a, $b) => ($b['priority'] ?? 50) - ($a['priority'] ?? 50));

        foreach ($actionList as $action) {
            $type = $action['type'] ?? null;
            $value = $action['value'] ?? null;
            $behavior = $action['behavior'] ?? 'always';

            if ($type === null) {
                continue;
            }

            switch ($type) {
                case 'set_category':
                    if ($this->shouldApply($behavior, $transaction['categoryId'] ?? null)) {
                        $transaction['categoryId'] = (int)$value;
                    }
                    break;

                case 'set_vendor':
                    if ($this->shouldApply($behavior, $transaction['vendor'] ?? null)) {
                        $transaction['vendor'] = $value;
                    }
                    break;

                case 'set_notes':
                    $existing = $transaction['notes'] ?? null;
                    if ($behavior === 'append' && $existing) {
                        $separator = $action['separator'] ?? ' ';
                        $transaction['notes'] = $existing . $separator . $value;
                    } elseif ($this->shouldApply($behavior, $existing)) {
                        $transaction['notes'] = $value;
                    }
                    break;

                case 'set_type':
                    if (in_array($value, ['income', 'expense'], true)
                        && $this->shouldApply($behavior, $transaction['type'] ?? null)) {
                        $transaction['type'] = $value;
                    }
                    break;

                case 'set_reference':
                    if ($this->shouldApply($behavior, $transaction['reference'] ?? null)) {
                        $transaction['reference'] = $value;
                    }
                    break;

                // add_tags: skip during import (requires persisted transaction ID)
                // set_account: skip during import (account is set by the import target)
            }
        }

        return $transaction;
    }

    /**
     * Check if an action should be applied based on its behavior.
     */
    private function shouldApply(string $behavior, $currentValue): bool {
        if ($behavior === 'if_empty') {
            return $currentValue === null || $currentValue === '';
        }
        return true;
    }
}
