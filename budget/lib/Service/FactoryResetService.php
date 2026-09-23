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
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;

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
 *
 * "Delete everything of mine" also covers what a backup never holds and a
 * restore therefore keeps (UserTableCleaner::FACTORY_RESET_ONLY): shares the
 * user granted, bank connections and their mappings, idempotency keys. Shares
 * other users granted TO this user are theirs and stay.
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
		private ?INotificationManager $notificationManager = null,
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
		// Recipients of the shares this user granted, read before the rows go
		// so their pending invitations can be dismissed afterwards
		$grantedShares = $this->findGrantedShares($userId);

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

			// 4. What no backup holds and a restore keeps: shares this user
			//    granted, bank connections and mappings, idempotency keys
			$counts += $this->tableCleaner->clearFactoryResetOnlyTables($userId, true);

			// IMPORTANT: AuditLog is NOT deleted - preserved for compliance

			// Commit the transaction - all deletions were successful
			$this->db->commit();
		} catch (\Exception $e) {
			// Rollback on any error - ensures no partial deletion
			$this->db->rollBack();
			throw $e;
		}

		$this->dismissShareInvitations($grantedShares);

		return $counts;
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

	/**
	 * @return array<int, string> share id => recipient user id
	 */
	private function findGrantedShares(string $userId): array {
		if ($this->notificationManager === null) {
			return [];
		}
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'shared_with_user_id')
				->from('budget_shares')
				->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));
			$result = $qb->executeQuery();
			$shares = [];
			while ($row = $result->fetch()) {
				$shares[(int)$row['id']] = (string)$row['shared_with_user_id'];
			}
			$result->closeCursor();
			return $shares;
		} catch (\Exception $e) {
			// Only a nicety for the recipients: never a reason not to reset
			return [];
		}
	}

	/**
	 * Mark the revoked shares' invitations processed, as ShareService::revoke()
	 * does, so a recipient is not left holding an invitation to nothing.
	 * Runs after the commit and is best-effort: the data is gone either way.
	 *
	 * @param array<int, string> $shares share id => recipient user id
	 */
	private function dismissShareInvitations(array $shares): void {
		if ($this->notificationManager === null) {
			return;
		}
		foreach ($shares as $shareId => $recipient) {
			try {
				$notification = $this->notificationManager->createNotification();
				$notification->setApp('budget')
					->setUser($recipient)
					->setObject('share', (string)$shareId);
				$this->notificationManager->markProcessed($notification);
			} catch (\Throwable $e) {
				// Nothing to undo: the share itself is already gone
			}
		}
	}
}
