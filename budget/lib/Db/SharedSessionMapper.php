<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<SharedSession>
 */
class SharedSessionMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_shared_sessions', SharedSession::class);
	}

	/**
	 * Find a shared session by session token
	 *
	 * @param string $sessionToken
	 * @throws DoesNotExistException
	 */
	public function findBySessionToken(string $sessionToken): SharedSession {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('session_token', $qb->createNamedParameter($sessionToken)));

		return $this->findEntity($qb);
	}

	/**
	 * Find a shared session for a specific current_user + owner_user pair
	 *
	 * @param string $currentUserId
	 * @param string $ownerUserId
	 * @throws DoesNotExistException
	 */
	public function findByCurrentUserAndOwner(string $currentUserId, string $ownerUserId): SharedSession {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('current_user_id', $qb->createNamedParameter($currentUserId)))
			->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId)));

		return $this->findEntity($qb);
	}

	/**
	 * Get all active shared sessions for a current user
	 *
	 * @param string $currentUserId
	 * @return SharedSession[]
	 */
	public function findByCurrentUser(string $currentUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('current_user_id', $qb->createNamedParameter($currentUserId)))
			->orderBy('created_at', 'DESC');

		return $this->findEntities($qb);
	}

	/**
	 * Delete a specific shared session by current_user + owner_user pair
	 *
	 * @param string $currentUserId
	 * @param string $ownerUserId
	 * @return int Number of deleted rows
	 */
	public function deleteByCurrentUserAndOwner(string $currentUserId, string $ownerUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('current_user_id', $qb->createNamedParameter($currentUserId)))
			->andWhere($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId)));

		return $qb->executeStatement();
	}

	/**
	 * Delete all expired shared sessions
	 *
	 * @return int Number of deleted rows
	 */
	public function deleteExpired(): int {
		$now = date('Y-m-d H:i:s');
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->lt('expires_at', $qb->createNamedParameter($now)));

		return $qb->executeStatement();
	}

	/**
	 * Delete all shared sessions for a specific current user
	 *
	 * @param string $currentUserId
	 * @return int Number of deleted rows
	 */
	public function deleteByCurrentUser(string $currentUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('current_user_id', $qb->createNamedParameter($currentUserId)));

		return $qb->executeStatement();
	}

	/**
	 * Delete all shared sessions accessing an owner's budget
	 *
	 * @param string $ownerUserId
	 * @return int Number of deleted rows
	 */
	public function deleteByOwner(string $ownerUserId): int {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId)));

		return $qb->executeStatement();
	}
}
