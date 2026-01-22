<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ContactMapper;
use OCA\Budget\Db\ExpenseShareMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\NetWorthSnapshotMapper;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionContributionMapper;
use OCA\Budget\Db\PensionSnapshotMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Db\SavingsGoalMapper;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\SettlementMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCP\IDBConnection;

/**
 * Service for performing a complete factory reset - deleting all user data except audit logs.
 */
class FactoryResetService {
    public function __construct(
        private AccountMapper $accountMapper,
        private TransactionMapper $transactionMapper,
        private TransactionSplitMapper $transactionSplitMapper,
        private BillMapper $billMapper,
        private CategoryMapper $categoryMapper,
        private RecurringIncomeMapper $recurringIncomeMapper,
        private ImportRuleMapper $importRuleMapper,
        private SettingMapper $settingMapper,
        private ContactMapper $contactMapper,
        private ExpenseShareMapper $expenseShareMapper,
        private SettlementMapper $settlementMapper,
        private SavingsGoalMapper $savingsGoalMapper,
        private PensionAccountMapper $pensionAccountMapper,
        private PensionContributionMapper $pensionContributionMapper,
        private PensionSnapshotMapper $pensionSnapshotMapper,
        private NetWorthSnapshotMapper $netWorthSnapshotMapper,
        private IDBConnection $db
    ) {
    }

    /**
     * Execute factory reset - delete ALL user data except audit logs.
     *
     * @param string $userId The user to reset
     * @return array<string, int> Counts of deleted items per entity type
     * @throws \Exception If deletion fails
     */
    public function executeFactoryReset(string $userId): array {
        // Use database transaction for atomicity - all deletions succeed or all rollback
        $this->db->beginTransaction();

        try {
            $counts = [];

            // CRITICAL: Delete in correct order to avoid foreign key issues
            // Note: We use safeDelete to skip tables that don't exist yet

            // Level 1: Child records first (depend on transactions/contacts)
            $counts['expenseShares'] = $this->safeDelete($this->expenseShareMapper, $userId);
            $counts['transactionSplits'] = $this->safeDelete($this->transactionSplitMapper, $userId);
            $counts['settlements'] = $this->safeDelete($this->settlementMapper, $userId);

            // Level 2: Records dependent on accounts/categories
            $counts['transactions'] = $this->safeDelete($this->transactionMapper, $userId);
            $counts['bills'] = $this->safeDelete($this->billMapper, $userId);
            $counts['recurringIncome'] = $this->safeDelete($this->recurringIncomeMapper, $userId);

            // Level 3: Child pension data (depend on pension accounts)
            $counts['pensionContributions'] = $this->safeDelete($this->pensionContributionMapper, $userId);
            $counts['pensionSnapshots'] = $this->safeDelete($this->pensionSnapshotMapper, $userId);

            // Level 4: Independent entities
            $counts['accounts'] = $this->safeDelete($this->accountMapper, $userId);
            $counts['pensionAccounts'] = $this->safeDelete($this->pensionAccountMapper, $userId);
            $counts['contacts'] = $this->safeDelete($this->contactMapper, $userId);
            $counts['savingsGoals'] = $this->safeDelete($this->savingsGoalMapper, $userId);

            // Level 5: Categories (self-referential, but deleteAll handles it)
            $counts['categories'] = $this->safeDelete($this->categoryMapper, $userId);

            // Level 6: Configuration/metadata
            $counts['importRules'] = $this->safeDelete($this->importRuleMapper, $userId);
            $counts['settings'] = $this->safeDelete($this->settingMapper, $userId);
            $counts['netWorthSnapshots'] = $this->safeDelete($this->netWorthSnapshotMapper, $userId);

            // IMPORTANT: AuditLog is NOT deleted - preserved for compliance

            // Commit the transaction - all deletions were successful
            $this->db->commit();

            return $counts;
        } catch (\Exception $e) {
            // Rollback on any error - ensures no partial deletion
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Safely delete all records for a user, ignoring table-not-found errors.
     *
     * @param object $mapper The mapper instance with a deleteAll method
     * @param string $userId The user ID
     * @return int Number of deleted rows (0 if table doesn't exist)
     */
    private function safeDelete($mapper, string $userId): int {
        try {
            return $mapper->deleteAll($userId);
        } catch (\Exception $e) {
            // If the table doesn't exist, return 0 (feature not used yet)
            // This handles cases where tables are added in newer migrations
            if (str_contains($e->getMessage(), 'no such table') ||
                str_contains($e->getMessage(), 'Table') && str_contains($e->getMessage(), 'doesn\'t exist')) {
                return 0;
            }
            // Re-throw other exceptions
            throw $e;
        }
    }
}
