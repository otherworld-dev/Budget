<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareItem;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Carries the links between a user's data and other users' data through a
 * backup restore, or cuts them cleanly.
 *
 * A restore deletes everything the user owns and inserts it again, so every
 * account, category, bill and transaction comes back under a new id. Links
 * inside the user's own data are remapped as the archive is read. Links that
 * cross to another user's data are outside the archive's id maps and were
 * left pointing at ids that no longer existed: the items the user shared kept
 * the old ids, so the people they shared with silently lost them; those
 * people's bills kept a dead account id; and the user's own bills on an
 * account shared with them lost their account, while the pending rows they
 * had booked there were restored with no bill, or left behind with a dead one.
 *
 * capture() runs before the user's data is cleared and records what crosses
 * over. A link is carried through the restore only when the row at the
 * user's end is verifiably the same one as before: the archive holds it under
 * the id it had here, with the same name and creation time (an entity) or the
 * same date, amount and type (a transaction). That holds for a backup of the
 * user's own data restored on the same server, and never for one from another
 * server, where the same id meant something else. Whatever can't be carried
 * is cut: a share item is removed, a reference is set to nothing, a bill that
 * loses an account has auto-pay switched off, and a pending row of a bill
 * that is gone is deleted.
 */
class CrossUserLinks {
	/** Share item type => [table, id map key in MigrationService::importData()] */
	public const SHAREABLE = [
		ShareItem::TYPE_ACCOUNT => ['budget_accounts', 'accounts'],
		ShareItem::TYPE_CATEGORY => ['budget_categories', 'categories'],
		ShareItem::TYPE_BILL => ['budget_bills', 'bills'],
		ShareItem::TYPE_RECURRING_INCOME => ['budget_recurring_income', 'recurring_income'],
		ShareItem::TYPE_SAVINGS_GOAL => ['budget_savings_goals', 'savings_goals'],
		ShareItem::TYPE_IMPORT_RULE => ['budget_import_rules', 'import_rules'],
		ShareItem::TYPE_PROJECT => ['budget_projects', 'projects'],
	];

	/**
	 * Columns of other users' rows that can point at the user's account or
	 * category: [table, column, whether the table has a user_id column].
	 */
	public const REFERENCES = [
		ShareItem::TYPE_ACCOUNT => [
			['budget_bills', 'account_id', true],
			['budget_bills', 'destination_account_id', true],
			['budget_recurring_income', 'account_id', true],
			['budget_savings_goals', 'account_id', true],
			['budget_pen_contribs', 'source_account_id', true],
			['budget_pen_recur', 'source_account_id', true],
		],
		ShareItem::TYPE_CATEGORY => [
			['budget_bills', 'category_id', true],
			['budget_recurring_income', 'category_id', true],
			['budget_import_rules', 'category_id', true],
			['budget_transactions', 'category_id', false],
			['budget_tx_splits', 'category_id', false],
		],
	];

	/** Columns kept from the user's own rows, beyond id, name and created_at */
	private const KEPT_COLUMNS = [
		ShareItem::TYPE_BILL => ['account_id', 'destination_account_id', 'category_id', 'split_template', 'paid_undo_state'],
		ShareItem::TYPE_RECURRING_INCOME => ['account_id', 'category_id', 'received_undo_state'],
	];

	/** Undo snapshots: table => [column, keys holding transaction ids] */
	private const SNAPSHOTS = [
		'budget_bills' => ['paid_undo_state', ['createdTransactionIds', 'scheduledTransactionIds', 'linkedTransactionId']],
		'budget_recurring_income' => ['received_undo_state', ['transactionIds']],
	];

	private const CHUNK = 500;

	private string $userId = '';

	/** @var array<string, array<int, string|null>> type => id => fingerprint, the user's entities before the restore */
	private array $owned = [];

	/** @var array<string, array<int, array<string, mixed>>> type => id => row, for KEPT_COLUMNS types */
	private array $ownRows = [];

	/** @var array<string, array<int, string>> type => id => strongest permission, of what is shared with the user */
	private array $sharedWithUser = [];

	/** @var list<array{id: int, type: string, entityId: int}> items of the shares the user owns */
	private array $shareItems = [];

	/**
	 * The user's transactions that another user's data points at, or that
	 * point at another user's data: the account they were in, and the bill
	 * or transfer leg of someone else's they carried.
	 *
	 * @var array<int, array{fp: string, accountId: int, billId: int|null, linkedId: int|null}>
	 */
	private array $trackedRows = [];

	/** @var list<array{table: string, id: int, snapshot: array}> other users' undo snapshots naming the user's transactions */
	private array $othersSnapshots = [];

	/** @var string[] users the user shares with, or who share with the user */
	private array $partners = [];

	/** @var array<string, array<int, int>> type (or 'transaction') => old id => new id, carried by apply() */
	private array $carried = [];

	public function __construct(
		private IDBConnection $db,
		private TransactionService $transactionService,
	) {
	}

	/**
	 * Record what crosses from the user's data to other users'. Must run
	 * before the restore clears the user's data.
	 */
	public function capture(string $userId): void {
		$this->userId = $userId;
		$this->owned = [];
		$this->ownRows = [];
		$this->carried = [];
		$this->trackedRows = [];
		$this->othersSnapshots = [];

		foreach (self::SHAREABLE as $type => [$table]) {
			$kept = self::KEPT_COLUMNS[$type] ?? [];
			foreach ($this->readOwned($table, $userId, $kept) as $row) {
				$id = (int)$row['id'];
				$this->owned[$type][$id] = self::fingerprint($row['name'] ?? null, $row['created_at'] ?? null);
				if ($kept !== []) {
					$this->ownRows[$type][$id] = $row;
				}
			}
		}

		$this->sharedWithUser = $this->readSharedWithUser($userId);
		$this->shareItems = $this->readOutgoingShareItems($userId);
		$this->partners = $this->readPartners($userId);

		foreach ($this->readRowsWithForeignLinks($userId) as $row) {
			$this->trackedRows[(int)$row['id']] = [
				'fp' => self::rowFingerprint($row['date'], $row['amount'], $row['type']),
				'accountId' => (int)$row['account_id'],
				'billId' => $row['bill_owner'] !== null && $row['bill_owner'] !== $userId ? (int)$row['bill_id'] : null,
				'linkedId' => $row['linked_owner'] !== null && $row['linked_owner'] !== $userId ? (int)$row['linked_transaction_id'] : null,
			];
		}

		// Other users' Mark Unpaid / Unreceived snapshots that name the
		// user's rows: those rows are about to get new ids
		$snapshots = [];
		$named = [];
		foreach (self::SNAPSHOTS as $table => [$column, $keys]) {
			foreach ($this->readSnapshots($table, $column, $this->partners) as $row) {
				$snapshot = json_decode((string)$row[$column], true);
				if (!is_array($snapshot)) {
					continue;
				}
				$ids = self::snapshotIds($snapshot, $keys);
				if ($ids !== []) {
					$snapshots[] = ['table' => $table, 'id' => (int)$row['id'], 'snapshot' => $snapshot, 'ids' => $ids];
					foreach ($ids as $id) {
						$named[$id] = true;
					}
				}
			}
		}
		if ($named !== []) {
			$ownNamed = [];
			foreach ($this->readOwnTransactions($userId, array_keys($named)) as $row) {
				$id = (int)$row['id'];
				$ownNamed[$id] = true;
				$this->trackedRows[$id] ??= [
					'fp' => self::rowFingerprint($row['date'], $row['amount'], $row['type']),
					'accountId' => (int)$row['account_id'],
					'billId' => null,
					'linkedId' => null,
				];
			}
			foreach ($snapshots as $entry) {
				if (array_intersect_key(array_flip($entry['ids']), $ownNamed) !== []) {
					$this->othersSnapshots[] = ['table' => $entry['table'], 'id' => $entry['id'], 'snapshot' => $entry['snapshot']];
				}
			}
		}
	}

	// ==========================================
	// Questions the import asks while it reads the archive
	// ==========================================

	/**
	 * Whether the archived entity of $type (a share item type) with $oldId is
	 * the one the user had here before the restore.
	 */
	public function isSameEntity(string $type, int $oldId, mixed $name, mixed $createdAt): bool {
		$before = $this->owned[$type][$oldId] ?? null;
		return $before !== null && $before === self::fingerprint($name, $createdAt);
	}

	/**
	 * Whether a restored bill or income may keep its reference to another
	 * user's account or category: the item is the one the user had here, it
	 * pointed at the same one before, and the user can still use it (write to
	 * it, for an account).
	 */
	public function keepsReference(string $type, int $oldId, mixed $name, mixed $createdAt, string $column, int $value): bool {
		if (!$this->isSameEntity($type, $oldId, $name, $createdAt)) {
			return false;
		}
		$before = $this->ownRows[$type][$oldId][$column] ?? null;
		return $before !== null && (int)$before === $value
			&& $this->usable(str_contains($column, 'account') ? ShareItem::TYPE_ACCOUNT : ShareItem::TYPE_CATEGORY, $value);
	}

	/**
	 * Whether a restored import rule may keep its reference to another
	 * user's account or category (an action or a condition): the rule is the
	 * one the user had here, and the user can still use what it names (write
	 * to it, for an account). The rule's references live inside JSON, so
	 * unlike keepsReference() there is no column to compare with what it
	 * pointed at before.
	 */
	public function ruleKeepsReference(int $oldRuleId, mixed $name, mixed $createdAt, string $type, int $value): bool {
		return $this->isSameEntity(ShareItem::TYPE_IMPORT_RULE, $oldRuleId, $name, $createdAt)
			&& $this->usable($type, $value);
	}

	/**
	 * Whether another user's account or category is shared with the user,
	 * with any permission. Saved report filters name such accounts.
	 */
	public function isSharedWithUser(string $type, int $id): bool {
		return !isset($this->owned[$type][$id]) && isset($this->sharedWithUser[$type][$id]);
	}

	/**
	 * Whether a restored bill's split template may keep a part's category
	 * that belongs to another user, by the same rules as keepsReference().
	 */
	public function keepsSplitCategory(int $oldBillId, mixed $name, mixed $createdAt, int $categoryId): bool {
		if (!$this->isSameEntity(ShareItem::TYPE_BILL, $oldBillId, $name, $createdAt)) {
			return false;
		}
		$template = json_decode((string)($this->ownRows[ShareItem::TYPE_BILL][$oldBillId]['split_template'] ?? ''), true);
		$before = [];
		foreach (is_array($template) ? $template : [] as $part) {
			if (is_array($part) && isset($part['categoryId']) && is_numeric($part['categoryId'])) {
				$before[] = (int)$part['categoryId'];
			}
		}
		return in_array($categoryId, $before, true) && $this->usable(ShareItem::TYPE_CATEGORY, $categoryId);
	}

	/**
	 * Whether a restored bill's or income's undo snapshot may keep a
	 * transaction id the archive doesn't hold: the item is the one the user
	 * had here, its snapshot named that row before the restore, and the row
	 * is still there, which means it sits in another user's account.
	 */
	public function keepsSnapshotTransaction(string $type, int $oldId, mixed $name, mixed $createdAt, int $transactionId): bool {
		if (!$this->isSameEntity($type, $oldId, $name, $createdAt)) {
			return false;
		}
		$column = $type === ShareItem::TYPE_BILL ? 'paid_undo_state' : 'received_undo_state';
		$keys = self::SNAPSHOTS[$type === ShareItem::TYPE_BILL ? 'budget_bills' : 'budget_recurring_income'][1];
		$snapshot = json_decode((string)($this->ownRows[$type][$oldId][$column] ?? ''), true);
		return is_array($snapshot)
			&& in_array($transactionId, self::snapshotIds($snapshot, $keys), true)
			&& $this->existingTransactionIds([$transactionId]) === [$transactionId];
	}

	/**
	 * Whether an archived transaction of the user's may keep its link to
	 * another user's bill ('billId') or transfer leg ('linkedId'): it is the
	 * row the user had here, in the same account (judged by the archived
	 * account's name and creation time), and it carried that same link
	 * before.
	 */
	public function keepsTransactionLink(int $oldId, mixed $date, mixed $amount, mixed $type, int $accountId, mixed $accountName, mixed $accountCreatedAt, string $link, int $value): bool {
		$before = $this->trackedRows[$oldId] ?? null;
		return $before !== null
			&& $before[$link] === $value
			&& $before['fp'] === self::rowFingerprint($date, $amount, $type)
			&& $before['accountId'] === $accountId
			&& $this->isSameEntity(ShareItem::TYPE_ACCOUNT, $accountId, $accountName, $accountCreatedAt);
	}

	// ==========================================
	// After the import
	// ==========================================

	/**
	 * Re-point every link that crossed over at the restored rows, or cut it.
	 *
	 * @param array<string, array<int, int>> $idMaps old id => new id per entity type, from the import
	 * @return array{sharesDropped: int, othersDetached: int}
	 */
	public function apply(array $idMaps): array {
		$this->carryEntities($idMaps);
		$this->carryTransactions($idMaps['transactions'] ?? []);

		$sharesDropped = $this->reKeyShareItems();
		$detached = $this->rePointReferences();
		$detached += $this->rePointSplitTemplates();
		$detached += $this->rePointBillRows();
		$this->rePointTransferLegs();
		$this->rePointSnapshots();

		return ['sharesDropped' => $sharesDropped, 'othersDetached' => $detached];
	}

	/** Old => new for each of the user's entities that came back as itself */
	private function carryEntities(array $idMaps): void {
		foreach (self::SHAREABLE as $type => [$table, $mapKey]) {
			$pairs = [];
			foreach ($this->owned[$type] ?? [] as $oldId => $fingerprint) {
				if ($fingerprint !== null && isset($idMaps[$mapKey][$oldId])) {
					$pairs[$oldId] = (int)$idMaps[$mapKey][$oldId];
				}
			}
			if ($pairs === []) {
				continue;
			}
			$after = [];
			foreach ($this->readEntities($table, array_values($pairs)) as $row) {
				$after[(int)$row['id']] = self::fingerprint($row['name'] ?? null, $row['created_at'] ?? null);
			}
			foreach ($pairs as $oldId => $newId) {
				if (($after[$newId] ?? null) === $this->owned[$type][$oldId]) {
					$this->carried[$type][$oldId] = $newId;
				}
			}
		}
	}

	/**
	 * Old => new for each tracked transaction that came back as itself, in
	 * its account that came back as itself
	 */
	private function carryTransactions(array $transactionMap): void {
		$pairs = [];
		foreach (array_keys($this->trackedRows) as $oldId) {
			if (isset($transactionMap[$oldId])) {
				$pairs[$oldId] = (int)$transactionMap[$oldId];
			}
		}
		if ($pairs === []) {
			return;
		}
		$after = [];
		foreach ($this->readTransactions(array_values($pairs)) as $row) {
			$after[(int)$row['id']] = [self::rowFingerprint($row['date'], $row['amount'], $row['type']), (int)$row['account_id']];
		}
		foreach ($pairs as $oldId => $newId) {
			$before = $this->trackedRows[$oldId];
			$account = $this->carried[ShareItem::TYPE_ACCOUNT][$before['accountId']] ?? null;
			if ($account !== null && ($after[$newId] ?? null) === [$before['fp'], $account]) {
				$this->carried['transaction'][$oldId] = $newId;
			}
		}
	}

	/**
	 * The user's share items follow their entities to the new ids. An item
	 * whose entity didn't come back is removed: left on the old id, the
	 * share went on reading "accepted" while showing the other person
	 * nothing, and would hand them whatever later held that id.
	 */
	private function reKeyShareItems(): int {
		$dropped = 0;
		foreach ($this->shareItems as $item) {
			$newId = $this->carried[$item['type']][$item['entityId']] ?? null;
			if ($newId !== null) {
				$this->updateRow('budget_share_items', $item['id'], ['entity_id' => $newId]);
			} else {
				$this->deleteRow('budget_share_items', $item['id']);
				$dropped++;
			}
		}
		return $dropped;
	}

	/**
	 * Other users' bills, income, goals, pension payments, rules and
	 * transactions that used the user's accounts and categories. A bill that
	 * loses an account stops auto-paying: it would only mark itself paid
	 * without recording anything.
	 */
	private function rePointReferences(): int {
		$detached = 0;
		foreach (self::REFERENCES as $type => $columns) {
			$oldIds = array_keys($this->owned[$type] ?? []);
			if ($oldIds === []) {
				continue;
			}
			foreach ($columns as [$table, $column, $hasUser]) {
				foreach ($this->readReferencing($table, $column, $oldIds, $hasUser ? $this->userId : null) as $id => $oldId) {
					$newId = $this->carried[$type][$oldId] ?? null;
					$values = [$column => $newId];
					if ($newId === null) {
						$detached++;
						$values += self::alsoOnDetach($table, $type);
					}
					$this->updateRow($table, $id, $values);
				}
			}
		}
		return $detached;
	}

	/** Categories inside other users' split templates */
	private function rePointSplitTemplates(): int {
		$oldIds = $this->owned[ShareItem::TYPE_CATEGORY] ?? [];
		if ($oldIds === [] || $this->partners === []) {
			return 0;
		}
		$detached = 0;
		foreach ($this->readSplitTemplates($this->partners) as $row) {
			$template = json_decode((string)$row['split_template'], true);
			if (!is_array($template)) {
				continue;
			}
			$changed = false;
			foreach ($template as $i => $part) {
				$oldId = is_array($part) && isset($part['categoryId']) && is_numeric($part['categoryId']) ? (int)$part['categoryId'] : null;
				if ($oldId === null || !array_key_exists($oldId, $oldIds)) {
					continue;
				}
				$newId = $this->carried[ShareItem::TYPE_CATEGORY][$oldId] ?? null;
				$template[$i]['categoryId'] = $newId;
				$detached += $newId === null ? 1 : 0;
				$changed = true;
			}
			if ($changed) {
				$this->updateRow('budget_bills', (int)$row['id'], ['split_template' => json_encode(array_values($template))]);
			}
		}
		return $detached;
	}

	/**
	 * Rows in other users' accounts booked by the user's bills. They follow
	 * the bill to its new id. If the bill didn't come back, its pending rows
	 * go, as they would if it had been deleted (nothing could clear or remove
	 * them otherwise), and the rest keep the money but lose the dead link.
	 */
	private function rePointBillRows(): int {
		$oldIds = array_keys($this->owned[ShareItem::TYPE_BILL] ?? []);
		if ($oldIds === []) {
			return 0;
		}
		$detached = 0;
		$gone = [];
		$deletedRows = [];
		foreach ($this->readBillRows($oldIds) as $row) {
			$oldBillId = (int)$row['bill_id'];
			$newBillId = $this->carried[ShareItem::TYPE_BILL][$oldBillId] ?? null;
			if ($newBillId !== null) {
				$this->updateRow('budget_transactions', (int)$row['id'], ['bill_id' => $newBillId]);
				continue;
			}
			$detached++;
			if ($row['status'] === 'scheduled') {
				$gone[$oldBillId] = true;
				$deletedRows[] = (int)$row['id'];
			} else {
				$this->updateRow('budget_transactions', (int)$row['id'], ['bill_id' => null]);
			}
		}
		foreach (array_keys($gone) as $oldBillId) {
			$this->transactionService->deleteScheduledBillTransactions($oldBillId);
		}
		// A deleted leg's partner must not point at it
		foreach ($this->readReferencing('budget_transactions', 'linked_transaction_id', $deletedRows, null) as $id => $_) {
			$this->updateRow('budget_transactions', $id, ['linked_transaction_id' => null]);
		}
		return $detached;
	}

	/** Transfer legs in other users' accounts paired with the user's rows */
	private function rePointTransferLegs(): void {
		$oldIds = array_keys(array_filter($this->trackedRows, static fn (array $row) => $row['linkedId'] !== null));
		foreach ($this->readReferencing('budget_transactions', 'linked_transaction_id', $oldIds, null) as $id => $oldId) {
			$this->updateRow('budget_transactions', $id, ['linked_transaction_id' => $this->carried['transaction'][$oldId] ?? null]);
		}
	}

	/**
	 * Other users' Mark Unpaid / Unreceived snapshots that named the user's
	 * rows. One that names a row that didn't come back is dropped: reverting
	 * it would put the item back to unpaid and leave that payment standing.
	 */
	private function rePointSnapshots(): void {
		foreach ($this->othersSnapshots as $entry) {
			[$column, $keys] = self::SNAPSHOTS[$entry['table']];
			$snapshot = $entry['snapshot'];
			$intact = true;
			foreach ($keys as $key) {
				if (!array_key_exists($key, $snapshot)) {
					continue;
				}
				if (is_array($snapshot[$key])) {
					foreach ($snapshot[$key] as $i => $id) {
						$snapshot[$key][$i] = $this->carriedSnapshotId($id, $intact);
					}
				} elseif ($snapshot[$key] !== null) {
					$snapshot[$key] = $this->carriedSnapshotId($snapshot[$key], $intact);
				}
			}
			$this->updateRow($entry['table'], $entry['id'], [$column => $intact ? json_encode($snapshot) : null]);
		}
	}

	/** A snapshot id moved to its restored row; $intact turns false when one of the user's rows didn't come back */
	private function carriedSnapshotId(mixed $id, bool &$intact): mixed {
		if (!is_numeric($id) || !isset($this->trackedRows[(int)$id])) {
			return $id;
		}
		$newId = $this->carried['transaction'][(int)$id] ?? null;
		if ($newId === null) {
			$intact = false;
			return $id;
		}
		return $newId;
	}

	// ==========================================
	// A share that ended or narrowed
	// ==========================================

	/**
	 * Cut what $recipientId's own data still points at in $ownerId's after a
	 * share between them ended or narrowed: the cleanup a reset or a deleted
	 * user gets from apply(), limited to the accounts and categories the
	 * recipient can no longer see. Left alone, a revoked recipient's rows,
	 * split parts and bills kept the owner's category (her lists showed its
	 * live name, and re-saving such a row was refused), and her bills kept
	 * pointing at the owner's account.
	 *
	 * Rows in the recipient's accounts, split parts on them, and her bills,
	 * income and rules filed under a lost category go to No category, as do
	 * her bills' split template parts. Her bills, income, goals and recurring
	 * pension payments on a lost account let go of it, and a bill that does
	 * stops auto-paying and loses its pending rows. A pension payment already
	 * made keeps the account it came from: that is history. So do her splits
	 * of the owner's transactions with contacts, her own record of what they
	 * owe her: they stay, and stop showing the transaction they were made
	 * from (ExpenseShareMapper::findSharedWithNextcloudUser()).
	 *
	 * An account she can still see but no longer write to is not lost: a bill
	 * on it is refused when it next pays, and works again if write comes back.
	 *
	 * @return array{detached: int}
	 */
	public function cutLostAccess(string $recipientId, string $ownerId): array {
		$result = ['detached' => 0];
		if ($recipientId === $ownerId) {
			return $result;
		}

		$shared = $this->readSharedWithUser($recipientId);
		$lost = [];
		foreach ([ShareItem::TYPE_ACCOUNT => 'budget_accounts', ShareItem::TYPE_CATEGORY => 'budget_categories'] as $type => $table) {
			$lost[$type] = [];
			foreach ($this->readOwned($table, $ownerId, []) as $row) {
				if (!isset($shared[$type][(int)$row['id']])) {
					$lost[$type][] = (int)$row['id'];
				}
			}
		}
		if ($lost[ShareItem::TYPE_ACCOUNT] === [] && $lost[ShareItem::TYPE_CATEGORY] === []) {
			return $result;
		}

		$this->db->beginTransaction();
		try {
			foreach (self::REFERENCES as $type => $columns) {
				if ($lost[$type] === []) {
					continue;
				}
				foreach ($columns as [$table, $column, $hasUser]) {
					if ($table === 'budget_pen_contribs') {
						continue;
					}
					foreach ($this->readUsersReferencing($table, $column, $lost[$type], $recipientId, $hasUser) as $id => $_) {
						if ($table === 'budget_bills' && $type === ShareItem::TYPE_ACCOUNT) {
							// Its pending rows would only ever be refused
							$this->transactionService->deleteScheduledBillTransactions($id);
						}
						$this->updateRow($table, $id, [$column => null] + self::alsoOnDetach($table, $type));
						$result['detached']++;
					}
				}
			}
			$result['detached'] += $this->cutSplitTemplateCategories($recipientId, $lost[ShareItem::TYPE_CATEGORY]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $result;
	}

	/**
	 * The parts of $userId's bill split templates filed under one of
	 * $categoryIds lose the category; the parts themselves stay.
	 *
	 * @param int[] $categoryIds
	 */
	private function cutSplitTemplateCategories(string $userId, array $categoryIds): int {
		if ($categoryIds === []) {
			return 0;
		}
		$cut = 0;
		foreach ($this->readSplitTemplates([$userId]) as $row) {
			$template = json_decode((string)$row['split_template'], true);
			if (!is_array($template)) {
				continue;
			}
			$changed = false;
			foreach ($template as $i => $part) {
				if (is_array($part) && isset($part['categoryId']) && is_numeric($part['categoryId'])
					&& in_array((int)$part['categoryId'], $categoryIds, true)) {
					$template[$i]['categoryId'] = null;
					$changed = true;
					$cut++;
				}
			}
			if ($changed) {
				$this->updateRow('budget_bills', (int)$row['id'], ['split_template' => json_encode(array_values($template))]);
			}
		}
		return $cut;
	}

	// ==========================================
	// Helpers
	// ==========================================

	/**
	 * What else changes when a row lets go of another user's account: a bill
	 * stops auto-paying (it would only mark itself paid without recording
	 * anything), and a recurring pension payment stops auto-posting (it
	 * would post with no bank leg), as when the account is deleted
	 * (PensionRecurringContributionMapper::detachSourceAccount()).
	 *
	 * @return array<string, bool>
	 */
	private static function alsoOnDetach(string $table, string $type): array {
		if ($type !== ShareItem::TYPE_ACCOUNT) {
			return [];
		}
		return match ($table) {
			'budget_bills' => ['auto_pay_enabled' => false],
			'budget_pen_recur' => ['auto_post_enabled' => false],
			default => [],
		};
	}

	/** Whether the user can use another user's account (write) or category (any permission) */
	private function usable(string $type, int $id): bool {
		if (isset($this->owned[$type][$id])) {
			// The user's own, from before the restore: not someone else's
			return false;
		}
		$permission = $this->sharedWithUser[$type][$id] ?? null;
		if ($type === ShareItem::TYPE_ACCOUNT) {
			return $permission === ShareItem::PERMISSION_WRITE || $permission === ShareItem::PERMISSION_FULL;
		}
		return $permission !== null;
	}

	/**
	 * An entity's identity across the restore. Null when it has no name or
	 * creation time to tell it by, so it never matches.
	 */
	public static function fingerprint(mixed $name, mixed $createdAt): ?string {
		$name = trim((string)$name);
		$created = substr(str_replace('T', ' ', trim((string)$createdAt)), 0, 19);
		if ($name === '' || $created === '') {
			return null;
		}
		return $name . "\x1f" . $created;
	}

	/** A transaction's identity across the restore */
	public static function rowFingerprint(mixed $date, mixed $amount, mixed $type): string {
		return substr(trim((string)$date), 0, 10) . '|' . number_format((float)$amount, 4, '.', '') . '|' . (string)$type;
	}

	/**
	 * @param string[] $keys
	 * @return int[]
	 */
	private static function snapshotIds(array $snapshot, array $keys): array {
		$ids = [];
		foreach ($keys as $key) {
			$value = $snapshot[$key] ?? null;
			foreach (is_array($value) ? $value : [$value] as $id) {
				if (is_numeric($id)) {
					$ids[] = (int)$id;
				}
			}
		}
		return $ids;
	}

	// ==========================================
	// Reads and writes
	// ==========================================

	/**
	 * @param string[] $extraColumns
	 * @return list<array<string, mixed>>
	 */
	protected function readOwned(string $table, string $userId, array $extraColumns): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'name', 'created_at', ...$extraColumns)
			->from($table)
			->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
		return $this->fetchAll($qb);
	}

	/**
	 * @return array<string, array<int, string>> type => entity id => strongest permission
	 */
	protected function readSharedWithUser(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('si.entity_type', 'si.entity_id', 'si.permission')
			->from('budget_share_items', 'si')
			->innerJoin('si', 'budget_shares', 's', $qb->expr()->eq('s.id', 'si.share_id'))
			->where($qb->expr()->eq('s.shared_with_user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->eq('s.status', $qb->createNamedParameter(Share::STATUS_ACCEPTED)));
		$shared = [];
		foreach ($this->fetchAll($qb) as $row) {
			$type = (string)$row['entity_type'];
			$id = (int)$row['entity_id'];
			$current = $shared[$type][$id] ?? null;
			if ($current === null || $current === ShareItem::PERMISSION_READ) {
				$shared[$type][$id] = (string)$row['permission'];
			}
		}
		return $shared;
	}

	/**
	 * @return list<array{id: int, type: string, entityId: int}>
	 */
	protected function readOutgoingShareItems(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('si.id', 'si.entity_type', 'si.entity_id')
			->from('budget_share_items', 'si')
			->innerJoin('si', 'budget_shares', 's', $qb->expr()->eq('s.id', 'si.share_id'))
			->where($qb->expr()->eq('s.owner_user_id', $qb->createNamedParameter($userId)));
		return array_map(static fn (array $row) => [
			'id' => (int)$row['id'],
			'type' => (string)$row['entity_type'],
			'entityId' => (int)$row['entity_id'],
		], $this->fetchAll($qb));
	}

	/**
	 * @return string[]
	 */
	protected function readPartners(string $userId): array {
		$partners = [];
		foreach ([['owner_user_id', 'shared_with_user_id'], ['shared_with_user_id', 'owner_user_id']] as [$mine, $theirs]) {
			$qb = $this->db->getQueryBuilder();
			$qb->select($theirs)
				->from('budget_shares')
				->where($qb->expr()->eq($mine, $qb->createNamedParameter($userId)));
			foreach ($this->fetchAll($qb) as $row) {
				$partners[(string)$row[$theirs]] = true;
			}
		}
		unset($partners[$userId]);
		return array_keys($partners);
	}

	/**
	 * The user's transactions that carry another user's bill, or are a
	 * transfer leg paired with a row in another user's account.
	 *
	 * @return list<array<string, mixed>>
	 */
	protected function readRowsWithForeignLinks(string $userId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('t.id', 't.account_id', 't.bill_id', 't.linked_transaction_id', 't.date', 't.amount', 't.type')
			->selectAlias('b.user_id', 'bill_owner')
			->selectAlias('la.user_id', 'linked_owner')
			->from('budget_transactions', 't')
			->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('a.id', 't.account_id'))
			->leftJoin('t', 'budget_bills', 'b', $qb->expr()->eq('b.id', 't.bill_id'))
			->leftJoin('t', 'budget_transactions', 'l', $qb->expr()->eq('l.id', 't.linked_transaction_id'))
			->leftJoin('l', 'budget_accounts', 'la', $qb->expr()->eq('la.id', 'l.account_id'))
			->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
			->andWhere($qb->expr()->orX(
				$qb->expr()->andX(
					$qb->expr()->isNotNull('b.id'),
					$qb->expr()->neq('b.user_id', $qb->createNamedParameter($userId))
				),
				$qb->expr()->andX(
					$qb->expr()->isNotNull('la.id'),
					$qb->expr()->neq('la.user_id', $qb->createNamedParameter($userId))
				)
			));
		return $this->fetchAll($qb);
	}

	/**
	 * @param string[] $userIds
	 * @return list<array<string, mixed>>
	 */
	protected function readSnapshots(string $table, string $column, array $userIds): array {
		if ($userIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', $column)
			->from($table)
			->where($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull($column));
		return $this->fetchAll($qb);
	}

	/**
	 * Which of these transactions are in the user's accounts.
	 *
	 * @param int[] $ids
	 * @return list<array<string, mixed>>
	 */
	protected function readOwnTransactions(string $userId, array $ids): array {
		$rows = [];
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('t.id', 't.account_id', 't.date', 't.amount', 't.type')
				->from('budget_transactions', 't')
				->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('a.id', 't.account_id'))
				->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)))
				->andWhere($qb->expr()->in('t.id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			array_push($rows, ...$this->fetchAll($qb));
		}
		return $rows;
	}

	/**
	 * @param int[] $ids
	 * @return list<array<string, mixed>>
	 */
	protected function readEntities(string $table, array $ids): array {
		$rows = [];
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'name', 'created_at')
				->from($table)
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			array_push($rows, ...$this->fetchAll($qb));
		}
		return $rows;
	}

	/**
	 * @param int[] $ids
	 * @return list<array<string, mixed>>
	 */
	protected function readTransactions(array $ids): array {
		$rows = [];
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'account_id', 'date', 'amount', 'type')
				->from('budget_transactions')
				->where($qb->expr()->in('id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			array_push($rows, ...$this->fetchAll($qb));
		}
		return $rows;
	}

	/**
	 * Rows whose $column holds one of $ids, outside the user's own rows.
	 *
	 * @param int[] $ids
	 * @param string|null $excludeUserId leave out this user's rows (tables with user_id)
	 * @return array<int, int> row id => the id it holds
	 */
	protected function readReferencing(string $table, string $column, array $ids, ?string $excludeUserId): array {
		$found = [];
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', $column)
				->from($table)
				->where($qb->expr()->in($column, $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			if ($excludeUserId !== null) {
				$qb->andWhere($qb->expr()->neq('user_id', $qb->createNamedParameter($excludeUserId)));
			}
			foreach ($this->fetchAll($qb) as $row) {
				$found[(int)$row['id']] = (int)$row[$column];
			}
		}
		return $found;
	}

	/**
	 * Transactions carrying one of these bill ids.
	 *
	 * @param int[] $billIds
	 * @return list<array<string, mixed>>
	 */
	protected function readBillRows(array $billIds): array {
		$rows = [];
		foreach (array_chunk($billIds, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'bill_id', 'status')
				->from('budget_transactions')
				->where($qb->expr()->in('bill_id', $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			array_push($rows, ...$this->fetchAll($qb));
		}
		return $rows;
	}

	/**
	 * @param string[] $userIds
	 * @return list<array<string, mixed>>
	 */
	protected function readSplitTemplates(array $userIds): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'split_template')
			->from('budget_bills')
			->where($qb->expr()->in('user_id', $qb->createNamedParameter($userIds, IQueryBuilder::PARAM_STR_ARRAY)))
			->andWhere($qb->expr()->isNotNull('split_template'));
		return $this->fetchAll($qb);
	}

	/**
	 * @param int[] $ids
	 * @return int[] those that exist
	 */
	protected function existingTransactionIds(array $ids): array {
		return array_map(static fn (array $row) => (int)$row['id'], $this->readTransactions($ids));
	}

	/**
	 * $userId's rows whose $column holds one of $ids: by user_id when the
	 * table has one; a transaction by the owner of its account; a split part
	 * by the owner of its transaction's account.
	 *
	 * @param int[] $ids
	 * @return array<int, int> row id => the id it holds
	 */
	protected function readUsersReferencing(string $table, string $column, array $ids, string $userId, bool $hasUser): array {
		if (!$hasUser && $table !== 'budget_transactions' && $table !== 'budget_tx_splits') {
			return [];
		}
		$found = [];
		foreach (array_chunk($ids, self::CHUNK) as $chunk) {
			$qb = $this->db->getQueryBuilder();
			if ($hasUser) {
				$qb->select('r.id', 'r.' . $column)
					->from($table, 'r')
					->where($qb->expr()->eq('r.user_id', $qb->createNamedParameter($userId)));
			} elseif ($table === 'budget_transactions') {
				$qb->select('r.id', 'r.' . $column)
					->from($table, 'r')
					->innerJoin('r', 'budget_accounts', 'a', $qb->expr()->eq('a.id', 'r.account_id'))
					->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));
			} else {
				$qb->select('r.id', 'r.' . $column)
					->from($table, 'r')
					->innerJoin('r', 'budget_transactions', 't', $qb->expr()->eq('t.id', 'r.transaction_id'))
					->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('a.id', 't.account_id'))
					->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));
			}
			$qb->andWhere($qb->expr()->in('r.' . $column, $qb->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
			foreach ($this->fetchAll($qb) as $row) {
				$found[(int)$row['id']] = (int)$row[$column];
			}
		}
		return $found;
	}

	/**
	 * @param array<string, mixed> $values column => value (null, bool, int or string)
	 */
	protected function updateRow(string $table, int $id, array $values): void {
		$qb = $this->db->getQueryBuilder();
		$qb->update($table);
		foreach ($values as $column => $value) {
			$type = match (true) {
				$value === null => IQueryBuilder::PARAM_NULL,
				is_bool($value) => IQueryBuilder::PARAM_BOOL,
				is_int($value) => IQueryBuilder::PARAM_INT,
				default => IQueryBuilder::PARAM_STR,
			};
			$qb->set($column, $qb->createNamedParameter($value, $type));
		}
		$qb->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	protected function deleteRow(string $table, int $id): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($table)
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function fetchAll(IQueryBuilder $qb): array {
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return $rows;
	}
}
