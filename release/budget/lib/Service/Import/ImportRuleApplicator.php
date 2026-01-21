<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Import;

use OCA\Budget\Db\ImportRuleMapper;

/**
 * Applies import rules to automatically categorize and tag transactions.
 */
class ImportRuleApplicator {
    private ImportRuleMapper $importRuleMapper;

    public function __construct(ImportRuleMapper $importRuleMapper) {
        $this->importRuleMapper = $importRuleMapper;
    }

    /**
     * Apply rules to a single transaction.
     *
     * @param string $userId The user ID
     * @param array $transaction Transaction data
     * @return array Transaction data with rules applied
     */
    public function applyRules(string $userId, array $transaction): array {
        $rule = $this->importRuleMapper->findMatchingRule($userId, $transaction);

        if ($rule === null) {
            return $transaction;
        }

        // Apply category from rule
        if ($rule->getCategoryId() !== null) {
            $transaction['categoryId'] = $rule->getCategoryId();
        }

        // Apply vendor name from rule (if specified)
        if ($rule->getVendorName() !== null && $rule->getVendorName() !== '') {
            $transaction['vendor'] = $rule->getVendorName();
        }

        // Add rule info for tracking
        $transaction['appliedRule'] = [
            'id' => $rule->getId(),
            'name' => $rule->getName(),
        ];

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
     * Find matching rule for a transaction.
     *
     * @param string $userId The user ID
     * @param array $transaction Transaction data
     * @return object|null The matching rule or null
     */
    public function findMatchingRule(string $userId, array $transaction): ?object {
        return $this->importRuleMapper->findMatchingRule($userId, $transaction);
    }

    /**
     * Preview rule applications without modifying transactions.
     *
     * @param string $userId The user ID
     * @param array $transactions List of transactions
     * @return array List of rule matches with transaction indices
     */
    public function previewRuleApplications(string $userId, array $transactions): array {
        $previews = [];

        foreach ($transactions as $index => $transaction) {
            $rule = $this->importRuleMapper->findMatchingRule($userId, $transaction);

            if ($rule !== null) {
                $previews[] = [
                    'transactionIndex' => $index,
                    'transaction' => $transaction,
                    'rule' => [
                        'id' => $rule->getId(),
                        'name' => $rule->getName(),
                        'categoryId' => $rule->getCategoryId(),
                        'vendorName' => $rule->getVendorName(),
                    ],
                ];
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
        $matched = 0;
        $unmatched = 0;
        $ruleUsage = [];

        foreach ($transactions as $transaction) {
            $rule = $this->importRuleMapper->findMatchingRule($userId, $transaction);

            if ($rule !== null) {
                $matched++;
                $ruleId = $rule->getId();
                $ruleUsage[$ruleId] = ($ruleUsage[$ruleId] ?? 0) + 1;
            } else {
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
}
