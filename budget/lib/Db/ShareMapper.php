<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

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
	 * Find a share by ID
	 */
	public function findById(int $id): Share {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		return $this->findEntity($qb);
	}

	/**
	 * Find a specific share between two users
	 */
	public function findByOwnerAndRecipient(string $ownerUserId, string $sharedWithUserId): Share {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($sharedWithUserId, IQueryBuilder::PARAM_STR)));
		return $this->findEntity($qb);
	}

	/**
	 * Get all shares created by an owner (outgoing shares)
	 *
	 * @return Share[]
	 */
	public function findByOwner(string $ownerUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('owner_user_id', $qb->createNamedParameter($ownerUserId, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Get all shares received by a user (incoming shares)
	 *
	 * @return Share[]
	 */
	public function findByRecipient(string $sharedWithUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($sharedWithUserId, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Get accepted incoming shares for a user.
	 * Returns the owner user IDs whose budgets this user can view.
	 *
	 * @return string[]
	 */
	public function findAcceptedOwnerIds(string $sharedWithUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('owner_user_id')
			->from($this->getTableName())
			->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($sharedWithUserId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Share::STATUS_ACCEPTED, IQueryBuilder::PARAM_STR)));

		$result = $qb->executeQuery();
		$ownerIds = [];
		while ($row = $result->fetch()) {
			$ownerIds[] = $row['owner_user_id'];
		}
		$result->closeCursor();
		return $ownerIds;
	}

	/**
	 * Get pending incoming shares for a user
	 *
	 * @return Share[]
	 */
	public function findPendingForRecipient(string $sharedWithUserId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($sharedWithUserId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(Share::STATUS_PENDING, IQueryBuilder::PARAM_STR)))
			->orderBy('created_at', 'DESC');
		return $this->findEntities($qb);
	}

	/**
	 * Delete all shares for a user (both as owner and recipient).
	 * Also deletes associated share items to avoid orphaned rows.
	 * Used by factory reset.
	 */
	public function deleteAllForUser(string $userId): void {
		// First collect share IDs to cascade delete share items
		$qb = $this->db->getQueryBuilder();
		$qb->select('id')
			->from($this->getTableName())
			->where($qb->expr()->orX(
				$qb->expr()->eq('owner_user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
				$qb->expr()->eq('shared_with_user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			));
		$result = $qb->executeQuery();
		$shareIds = [];
		while ($row = $result->fetch()) {
			$shareIds[] = (int)$row['id'];
		}
		$result->closeCursor();

		// Delete share items for those shares
		if (!empty($shareIds)) {
			$delQb = $this->db->getQueryBuilder();
			$delQb->delete('budget_share_items')
				->where($delQb->expr()->in('share_id', $delQb->createNamedParameter($shareIds, IQueryBuilder::PARAM_INT_ARRAY)));
			$delQb->executeStatement();
		}

		// Delete the shares themselves
		$qb2 = $this->db->getQueryBuilder();
		$qb2->delete($this->getTableName())
			->where($qb2->expr()->orX(
				$qb2->expr()->eq('owner_user_id', $qb2->createNamedParameter($userId, IQueryBuilder::PARAM_STR)),
				$qb2->expr()->eq('shared_with_user_id', $qb2->createNamedParameter($userId, IQueryBuilder::PARAM_STR))
			));
		$qb2->executeStatement();
	}
}
