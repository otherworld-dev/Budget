<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\ShareItem;
use OCA\Budget\Service\CrossUserLinks;
use OCA\Budget\Service\TransactionService;
use OCP\IDBConnection;

/**
 * CrossUserLinks over a handful of PHP arrays instead of the database, so
 * its decisions can be unit tested. Only the reads and writes are replaced;
 * the integration suite proves the SQL behind them
 * (tests/Integration/Service/BackupRestoreLinksTest.php).
 *
 * $tables holds table => id => row, with the same column names as the
 * database.
 */
class InMemoryCrossUserLinks extends CrossUserLinks {
	/** @var array<string, array<int, array<string, mixed>>> */
	public array $tables = [];

	public function __construct(IDBConnection $db, TransactionService $transactionService) {
		parent::__construct($db, $transactionService);
	}

	/** @return array<int, array<string, mixed>> */
	private function table(string $name): array {
		return $this->tables[$name] ?? [];
	}

	private function accountOwner(mixed $accountId): ?string {
		return $accountId === null ? null : ($this->table('budget_accounts')[(int)$accountId]['user_id'] ?? null);
	}

	protected function readOwned(string $table, string $userId, array $extraColumns): array {
		$rows = [];
		foreach ($this->table($table) as $id => $row) {
			if (($row['user_id'] ?? null) === $userId) {
				$picked = ['id' => $id, 'name' => $row['name'] ?? null, 'created_at' => $row['created_at'] ?? null];
				foreach ($extraColumns as $column) {
					$picked[$column] = $row[$column] ?? null;
				}
				$rows[] = $picked;
			}
		}
		return $rows;
	}

	protected function readSharedWithUser(string $userId): array {
		$shared = [];
		foreach ($this->table('budget_share_items') as $item) {
			$share = $this->table('budget_shares')[$item['share_id']] ?? null;
			if ($share === null || $share['shared_with_user_id'] !== $userId || $share['status'] !== 'accepted') {
				continue;
			}
			$current = $shared[$item['entity_type']][$item['entity_id']] ?? null;
			if ($current === null || $current === ShareItem::PERMISSION_READ) {
				$shared[$item['entity_type']][$item['entity_id']] = $item['permission'];
			}
		}
		return $shared;
	}

	protected function readOutgoingShareItems(string $userId): array {
		$items = [];
		foreach ($this->table('budget_share_items') as $id => $item) {
			if (($this->table('budget_shares')[$item['share_id']]['owner_user_id'] ?? null) === $userId) {
				$items[] = ['id' => $id, 'type' => $item['entity_type'], 'entityId' => $item['entity_id']];
			}
		}
		return $items;
	}

	protected function readPartners(string $userId): array {
		$partners = [];
		foreach ($this->table('budget_shares') as $share) {
			if ($share['owner_user_id'] === $userId) {
				$partners[$share['shared_with_user_id']] = true;
			} elseif ($share['shared_with_user_id'] === $userId) {
				$partners[$share['owner_user_id']] = true;
			}
		}
		return array_keys($partners);
	}

	protected function readRowsWithForeignLinks(string $userId): array {
		$rows = [];
		foreach ($this->table('budget_transactions') as $id => $row) {
			if ($this->accountOwner($row['account_id']) !== $userId) {
				continue;
			}
			$billOwner = isset($row['bill_id']) ? ($this->table('budget_bills')[$row['bill_id']]['user_id'] ?? null) : null;
			$linked = isset($row['linked_transaction_id']) ? ($this->table('budget_transactions')[$row['linked_transaction_id']] ?? null) : null;
			$linkedOwner = $linked === null ? null : $this->accountOwner($linked['account_id']);
			if (($billOwner !== null && $billOwner !== $userId) || ($linkedOwner !== null && $linkedOwner !== $userId)) {
				$rows[] = [
					'id' => $id, 'account_id' => $row['account_id'], 'bill_id' => $row['bill_id'] ?? null, 'linked_transaction_id' => $row['linked_transaction_id'] ?? null,
					'date' => $row['date'], 'amount' => $row['amount'], 'type' => $row['type'],
					'bill_owner' => $billOwner, 'linked_owner' => $linkedOwner,
				];
			}
		}
		return $rows;
	}

	protected function readSnapshots(string $table, string $column, array $userIds): array {
		$rows = [];
		foreach ($this->table($table) as $id => $row) {
			if (in_array($row['user_id'], $userIds, true) && ($row[$column] ?? null) !== null) {
				$rows[] = ['id' => $id, $column => $row[$column]];
			}
		}
		return $rows;
	}

	protected function readOwnTransactions(string $userId, array $ids): array {
		$rows = [];
		foreach ($ids as $id) {
			$row = $this->table('budget_transactions')[$id] ?? null;
			if ($row !== null && $this->accountOwner($row['account_id']) === $userId) {
				$rows[] = ['id' => $id, 'account_id' => $row['account_id'], 'date' => $row['date'], 'amount' => $row['amount'], 'type' => $row['type']];
			}
		}
		return $rows;
	}

	protected function readEntities(string $table, array $ids): array {
		$rows = [];
		foreach ($ids as $id) {
			$row = $this->table($table)[$id] ?? null;
			if ($row !== null) {
				$rows[] = ['id' => $id, 'name' => $row['name'] ?? null, 'created_at' => $row['created_at'] ?? null];
			}
		}
		return $rows;
	}

	protected function readTransactions(array $ids): array {
		$rows = [];
		foreach ($ids as $id) {
			$row = $this->table('budget_transactions')[$id] ?? null;
			if ($row !== null) {
				$rows[] = ['id' => $id, 'account_id' => $row['account_id'], 'date' => $row['date'], 'amount' => $row['amount'], 'type' => $row['type']];
			}
		}
		return $rows;
	}

	protected function readReferencing(string $table, string $column, array $ids, ?string $excludeUserId): array {
		$found = [];
		foreach ($this->table($table) as $id => $row) {
			$value = $row[$column] ?? null;
			if ($value === null || !in_array((int)$value, $ids, true)) {
				continue;
			}
			if ($excludeUserId !== null && ($row['user_id'] ?? null) === $excludeUserId) {
				continue;
			}
			$found[$id] = (int)$value;
		}
		return $found;
	}

	protected function readBillRows(array $billIds): array {
		$rows = [];
		foreach ($this->table('budget_transactions') as $id => $row) {
			if (isset($row['bill_id']) && in_array((int)$row['bill_id'], $billIds, true)) {
				$rows[] = ['id' => $id, 'bill_id' => $row['bill_id'], 'status' => $row['status'] ?? null];
			}
		}
		return $rows;
	}

	protected function readSplitTemplates(array $userIds): array {
		$rows = [];
		foreach ($this->table('budget_bills') as $id => $row) {
			if (in_array($row['user_id'], $userIds, true) && ($row['split_template'] ?? null) !== null) {
				$rows[] = ['id' => $id, 'split_template' => $row['split_template']];
			}
		}
		return $rows;
	}

	protected function updateRow(string $table, int $id, array $values): void {
		foreach ($values as $column => $value) {
			$this->tables[$table][$id][$column] = $value;
		}
	}

	protected function deleteRow(string $table, int $id): void {
		unset($this->tables[$table][$id]);
	}

	/** What TransactionService::deleteScheduledBillTransactions() does to these tables */
	public function deleteScheduledRowsOf(int $billId): void {
		foreach ($this->table('budget_transactions') as $id => $row) {
			if (($row['bill_id'] ?? null) === $billId && ($row['status'] ?? null) === 'scheduled') {
				unset($this->tables['budget_transactions'][$id]);
			}
		}
	}

	/**
	 * Stand-in for the restore clearing the user's data: their entities and
	 * the transactions in their accounts go.
	 */
	public function clearUser(string $userId): void {
		foreach (array_keys($this->table('budget_transactions')) as $id) {
			if ($this->accountOwner($this->tables['budget_transactions'][$id]['account_id']) === $userId) {
				unset($this->tables['budget_transactions'][$id]);
			}
		}
		foreach (['budget_accounts', 'budget_categories', 'budget_bills', 'budget_recurring_income', 'budget_savings_goals', 'budget_import_rules', 'budget_projects'] as $table) {
			foreach ($this->table($table) as $id => $row) {
				if (($row['user_id'] ?? null) === $userId) {
					unset($this->tables[$table][$id]);
				}
			}
		}
	}
}
