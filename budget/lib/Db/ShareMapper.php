<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Share>
 */
class ShareMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_shares', Share::class);
	}

	/**
	 * Find all shares created by an owner (users they've shared with)
	 *
	 * @param string $ownerUserId
	 * @return Share[]
	 */
	public function findSharesByOwner(string $ownerUserId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find all shares accessible by a user (budgets shared with them)
	 *
	 * @param string $sharedWithUserId
	 * @return Share[]
	 */
	public function findSharesAccessibleBy(string $sharedWithUserId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($sharedWithUserId, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Find a specific share between owner and shared user
	 *
	 * @param string $ownerUserId
	 * @param string $sharedWithUserId
	 * @return Share
	 * @throws DoesNotExistException
	 */
	public function findShare(string $ownerUserId, string $sharedWithUserId): Share {
		$qb = $this->db->getQueryBuilder();

		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($sharedWithUserId, IQueryBuilder::PARAM_STR)));

		return $this->findEntity($qb);
	}

	/**
	 * Check if a user has access to another user's budget
	 *
	 * @param string $currentUserId User requesting access
	 * @param string $targetUserId Owner of the budget
	 * @return bool
	 */
	public function hasAccess(string $currentUserId, string $targetUserId): bool {
		// User always has access to their own budget
		if ($currentUserId === $targetUserId) {
			return true;
		}

		$qb = $this->db->getQueryBuilder();

		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($targetUserId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($currentUserId, IQueryBuilder::PARAM_STR)))
			->setMaxResults(1);

		$result = $qb->executeQuery();
		$hasAccess = $result->fetchOne() !== false;
		$result->closeCursor();

		return $hasAccess;
	}

	/**
	 * Get all user IDs that the current user can access (own + shared)
	 * This is used to build IN queries for multi-user data access
	 *
	 * @param string $currentUserId
	 * @return string[] Array of user IDs (includes currentUserId + all shared owners)
	 */
	public function getAccessibleUserIds(string $currentUserId): array {
		$qb = $this->db->getQueryBuilder();

		$qb->select('owner_user_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($currentUserId, IQueryBuilder::PARAM_STR)));

		$result = $qb->executeQuery();
		$ownerIds = $result->fetchAll(\PDO::FETCH_COLUMN);
		$result->closeCursor();

		// Always include the current user's own ID
		$accessibleIds = array_merge([$currentUserId], $ownerIds);

		return array_unique($accessibleIds);
	}

	/**
	 * Revoke a share (delete the share record)
	 *
	 * @param string $ownerUserId
	 * @param string $sharedWithUserId
	 * @return int Number of deleted rows
	 */
	public function revokeShare(string $ownerUserId, string $sharedWithUserId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($sharedWithUserId, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}

	/**
	 * Delete all shares owned by a user (when deleting user data)
	 *
	 * @param string $ownerUserId
	 * @return int Number of deleted rows
	 */
	public function deleteAllByOwner(string $ownerUserId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}

	/**
	 * Delete all shares for a shared user (when they should no longer have access)
	 *
	 * @param string $sharedWithUserId
	 * @return int Number of deleted rows
	 */
	public function deleteAllForSharedUser(string $sharedWithUserId): int {
		$qb = $this->db->getQueryBuilder();

		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($sharedWithUserId, IQueryBuilder::PARAM_STR)));

		return $qb->executeStatement();
	}
}
