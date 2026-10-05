<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\AttachmentMapper;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\ContactMapper;
use OCA\Budget\Db\ImportRuleMapper;
use OCA\Budget\Db\SettingMapper;
use OCA\Budget\Db\ShareMapper;
use OCA\Budget\Db\TransactionMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

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
 *
 * Other users' data that pointed into what was deleted is cut loose the way
 * a restore cuts what it can't carry (CrossUserLinks): their transactions,
 * splits, bills, income and rules under the user's shared categories go to
 * No category, their bills on the user's shared accounts stop auto-paying,
 * and their transfer legs paired with the user's rows are unlinked.
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
		private ?TransactionService $transactionService = null,
		private ?ShareMapper $shareMapper = null,
		private ?ContactMapper $contactMapper = null,
		private ?CrossUserLinks $crossUserLinks = null,
		private ?LoggerInterface $logger = null,
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
		return $this->reset($userId, false);
	}

	/**
	 * Remove everything a deleted Nextcloud user had in the app.
	 *
	 * A factory reset, plus the shares other users granted TO them, which a
	 * reset keeps: left in place, a re-created account with the same uid
	 * inherited write access to other people's accounts. Other users'
	 * contacts linked to the uid are unlinked for the same reason, since
	 * shared expenses reach their recipient through that link.
	 */
	public function purgeDeletedUser(string $userId): void {
		$this->reset($userId, true);
	}

	/**
	 * @param bool $userDeleted also revoke the access the uid was given
	 * @return array<string, int>
	 */
	private function reset(string $userId, bool $userDeleted): array {
		try {
			// Recipients of the shares this user granted, read before the rows go
			// so their pending invitations can be dismissed afterwards
			$grantedShares = $this->findGrantedShares($userId);

			// What other users' data points at in this user's, read while the
			// shares that put it there still exist
			$this->crossUserLinks?->capture($userId);

			// A bill can pre-book into another user's account shared with this
			// one. The transactions delete below only reaches the user's own
			// accounts, so those pending rows outlived the bill, and the
			// scheduled job later booked them into the other user's balance.
			// Read here; deleted inside the transaction below.
			$billIds = $this->findBillIds($userId);
		} catch (\Throwable $e) {
			// Nothing was deleted yet, but a deleted uid's access goes all the
			// same, as when the purge fails inside its transaction
			if ($userDeleted) {
				$this->revokeAccessGivenTo($userId);
			}
			throw $e;
		}

		// One transaction for everything, the deleted user's access included:
		// a reset or purge that fails part way leaves the data as it was and
		// can simply be run again. Deleting the shares first, outside it, left
		// the data of a failed purge behind and took away the shared links a
		// second run needed to find what to cut. The access other users gave
		// a deleted uid is still cut when the purge fails (see the catch).
		$this->db->beginTransaction();

		try {
			foreach ($billIds as $billId) {
				$this->transactionService?->deleteScheduledBillTransactions($billId);
			}

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

			// 5. Other users' rows that pointed at what just went: nothing
			//    of this user's comes back, so every link is cut
			$this->crossUserLinks?->apply([]);

			// 6. A deleted user's access to other users' data: the shares
			//    granted to them and the contacts linked to their uid. Left
			//    in place, a re-created account with the same uid inherited
			//    it. Last, so it goes exactly when the data goes.
			if ($userDeleted) {
				$this->shareMapper?->deleteAllForUser($userId);
				$this->contactMapper?->unlinkNextcloudUser($userId);
			}

			// IMPORTANT: AuditLog is NOT deleted - preserved for compliance

			// Commit the transaction - all deletions were successful
			$this->db->commit();
		} catch (\Throwable $e) {
			// Rollback on any error - ensures no partial deletion
			$this->db->rollBack();
			if ($userDeleted) {
				$this->revokeAccessGivenTo($userId);
			}
			throw $e;
		}

		$this->dismissShareInvitations($grantedShares);

		return $counts;
	}

	/**
	 * A deleted user's purge failed and was rolled back: their data stays
	 * for a second run, but the access other users gave them can't wait for
	 * it, or a re-created account with the same uid inherits it. The shares
	 * granted TO the uid and the contacts linked to it go now, each on its
	 * own. The shares the uid granted are its data and stay with the rest.
	 */
	private function revokeAccessGivenTo(string $userId): void {
		try {
			$this->shareMapper?->deleteSharedWithUser($userId);
		} catch (\Throwable $e) {
			$this->logger?->error('Could not revoke the Budget shares given to deleted user {user}: {error}', [
				'app' => 'budget', 'user' => $userId, 'error' => $e->getMessage(), 'exception' => $e,
			]);
		}
		try {
			$this->contactMapper?->unlinkNextcloudUser($userId);
		} catch (\Throwable $e) {
			$this->logger?->error('Could not unlink the Budget contacts of deleted user {user}: {error}', [
				'app' => 'budget', 'user' => $userId, 'error' => $e->getMessage(), 'exception' => $e,
			]);
		}
	}

	/**
	 * The ids of the user's bills, whose pending rows the reset deletes.
	 * Read before the transaction: a missing table is tolerated here, and on
	 * PostgreSQL a failed statement inside the transaction would abort it.
	 *
	 * @return int[]
	 */
	private function findBillIds(string $userId): array {
		if ($this->transactionService === null) {
			return [];
		}
		try {
			$bills = $this->billMapper->findAll($userId);
		} catch (\Exception $e) {
			if (UserTableCleaner::isMissingTable($e)) {
				return [];
			}
			throw $e;
		}
		return array_map(static fn ($bill) => $bill->getId(), $bills);
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
