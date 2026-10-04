<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Finds budget data that belongs to Nextcloud users who no longer exist.
 *
 * Since 3.0 a user's data is removed when the user is deleted
 * (UserDeletedListener). Users deleted before that, or whose removal failed,
 * kept all of it: their accounts, rows and bills, the shares other users gave
 * them, and contacts linked to their uid. The background jobs skip such users
 * (JobUsers), but nothing removes their data on its own, because a user
 * backend that is unreachable for a while (an LDAP server) can make a real
 * user look deleted. `occ budget:purge-deleted-users` lists what is found here
 * and removes it on an admin's say-so.
 *
 * The columns looked at are exactly the ones FactoryResetService::purgeDeletedUser()
 * clears: every user-scoped table of the backup registry and of
 * UserTableCleaner::FACTORY_RESET_ONLY, the entities it deletes through
 * their mappers, and the access the uid was given. The audit log is kept on
 * purpose, so it is not looked at.
 */
class DeletedUserData {
	/** Tables FactoryResetService deletes through their own mappers */
	private const BESPOKE_TABLES = [
		'budget_accounts',
		'budget_bills',
		'budget_import_rules',
		'budget_categories',
		'budget_settings',
		'budget_attachments',
	];

	/** Other users' rows that give the uid access: [table, column] */
	private const ACCESS_COLUMNS = [
		['budget_shares', 'shared_with_user_id'],
		['budget_contacts', 'nextcloud_user_id'],
	];

	public function __construct(
		private IDBConnection $db,
		private IUserManager $userManager,
	) {
	}

	/**
	 * Every [table, column] holding the uid a row belongs to or gives
	 * access to.
	 *
	 * @return list<array{0: string, 1: string}>
	 */
	public static function userColumns(): array {
		$columns = [];
		foreach ([UserTableCleaner::clearOrder(), UserTableCleaner::FACTORY_RESET_ONLY] as $specs) {
			foreach ($specs as $spec) {
				if (($spec['scope'] ?? 'user') === 'user') {
					$columns[] = [$spec['table'], $spec['userColumn'] ?? 'user_id'];
				}
			}
		}
		foreach (self::BESPOKE_TABLES as $table) {
			$columns[] = [$table, 'user_id'];
		}
		foreach (self::ACCESS_COLUMNS as $column) {
			$columns[] = $column;
		}
		return array_values(array_unique($columns, SORT_REGULAR));
	}

	/**
	 * The users with budget data that Nextcloud says don't exist ('gone',
	 * uid => rows per "table" or "table.column", sorted by uid), and those a
	 * user backend could not answer for ('unknown', left alone).
	 *
	 * @return array{gone: array<string, array<string, int>>, unknown: string[]}
	 */
	public function find(): array {
		/** @var array<string, array<string, int>> $byUser */
		$byUser = [];
		foreach (self::userColumns() as [$table, $column]) {
			$label = $column === 'user_id' ? $table : $table . '.' . $column;
			foreach ($this->countByUser($table, $column) as $userId => $rows) {
				$byUser[$userId][$label] = $rows;
			}
		}
		// Transactions have no user column; shown for the size of the job
		foreach ($this->countTransactionsByOwner() as $userId => $rows) {
			if (isset($byUser[$userId])) {
				$byUser[$userId]['budget_transactions'] = $rows;
			}
		}

		$gone = [];
		$unknown = [];
		foreach ($byUser as $userId => $counts) {
			$userId = (string)$userId;
			try {
				if ($this->userManager->userExists($userId)) {
					continue;
				}
			} catch (\Throwable $e) {
				$unknown[] = $userId;
				continue;
			}
			ksort($counts);
			$gone[$userId] = $counts;
		}
		ksort($gone, SORT_STRING);
		sort($unknown, SORT_STRING);

		return ['gone' => $gone, 'unknown' => $unknown];
	}

	/**
	 * @return array<string, int> uid => rows; blank uids are never a user
	 */
	private function countByUser(string $table, string $column): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($column)
				->selectAlias($qb->func()->count('*'), 'n')
				->from($table)
				->where($qb->expr()->isNotNull($column))
				->groupBy($column);
			$result = $qb->executeQuery();
		} catch (\Exception $e) {
			if (UserTableCleaner::isMissingTable($e)) {
				return [];
			}
			throw $e;
		}
		$counts = [];
		while ($row = $result->fetch()) {
			$userId = (string)$row[$column];
			if (trim($userId) !== '') {
				$counts[$userId] = (int)$row['n'];
			}
		}
		$result->closeCursor();
		return $counts;
	}

	/**
	 * @return array<string, int> account owner => transactions
	 */
	private function countTransactionsByOwner(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.user_id')
			->selectAlias($qb->func()->count('t.id'), 'n')
			->from('budget_transactions', 't')
			->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
			->groupBy('a.user_id');
		$result = $qb->executeQuery();
		$counts = [];
		while ($row = $result->fetch()) {
			$counts[(string)$row['user_id']] = (int)$row['n'];
		}
		$result->closeCursor();
		return $counts;
	}
}
