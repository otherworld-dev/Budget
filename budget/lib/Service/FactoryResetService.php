<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\AttachmentMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\TransactionMapper;
use OCP\IDBConnection;

/**
 * Service for performing a complete factory reset - deleting all user data except audit logs.
 *
 * The table-level deletes are driven by the backup registry
 * (MigrationService::EXTRA_TABLES_PRE / _POST) through UserTableCleaner, the
 * same code backup restore uses, so every table registered for backup is also
 * wiped here. The previous hand-written list drifted: it never cleared tag
 * sets, interest rates, recurring pension contributions, category mutes,
 * debt scenarios, dismissed imports, import links, import templates, manual
 * rates or saved reports, and it deleted transactions before their tags,
 * orphaning those rows permanently.
 */
class FactoryResetService {
    private UserTableCleaner $tableCleaner;

    public function __construct(
        private AccountMapper $accountMapper,
        private TransactionMapper $transactionMapper,
        private BillMapper $billMapper,
        private CategoryMapper $categoryMapper,
        private ImportRuleMapper $importRuleMapper,
        private SettingMapper $settingMapper,
        private AttachmentMapper $attachmentMapper,
        private IDBConnection $db,
    ) {
        $this->tableCleaner = new UserTableCleaner($db);
    }

    /**
     * Execute factory reset - delete ALL user data except audit logs.
     *
     * @param string $userId The user to reset
     * @return array<string, int> Counts of deleted rows, keyed by backup
     *                            registry key for table-level data and by
     *                            entity name for the rest
     * @throws \Exception If deletion fails
     */
    public function executeFactoryReset(string $userId): array {
        // Use database transaction for atomicity - all deletions succeed or all rollback
        $this->db->beginTransaction();

        try {
            // 1. Every registry table. Join-scoped ones (transaction tags,
            //    splits, tag sets, dismissed imports) find their rows through
            //    transactions, accounts and categories, so they go first.
            $counts = $this->tableCleaner->clearRegisteredTables($userId, true);

            // 2. The bespoke entities, children before parents: transactions
            //    are found through their account, so they go before accounts.
            $counts['transactions'] = $this->safeDelete($this->transactionMapper, $userId);
            $counts['bills'] = $this->safeDelete($this->billMapper, $userId);
            $counts['importRules'] = $this->safeDelete($this->importRuleMapper, $userId);
            $counts['accounts'] = $this->safeDelete($this->accountMapper, $userId);
            $counts['categories'] = $this->safeDelete($this->categoryMapper, $userId);
            $counts['settings'] = $this->safeDelete($this->settingMapper, $userId);

            // 3. Attachment rows only — the receipt files stay in the user's Files
            $counts['attachments'] = $this->safeDelete($this->attachmentMapper, $userId);

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
            // Tables added by newer migrations may not exist yet
            if (UserTableCleaner::isMissingTable($e)) {
                return 0;
            }
            throw $e;
        }
    }
}
