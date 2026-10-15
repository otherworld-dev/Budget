<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ShareAutoConfig>
 */
class ShareAutoConfigMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'budget_share_auto', ShareAutoConfig::class);
	}

	/**
	 * All auto-share rules for a share (one per enabled entity type).
	 *
	 * @return ShareAutoConfig[]
	 */
	public function findByShareId(int $shareId): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)));
		return $this->findEntities($qb);
	}

	/**
	 * Active auto-share targets for an owner + entity type: every accepted share
	 * this owner has that has opted into auto-sharing this type. Returns rows of
	 * ['shareId' => int, 'permission' => string].
	 *
	 * @return array<int, array{shareId: int, permission: string}>
	 */
	public function findActiveForOwnerAndType(string $ownerUserId, string $entityType): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('a.share_id', 'a.permission')
			->from($this->getTableName(), 'a')
			->innerJoin('a', 'budget_shares', 's', $qb->expr()->eq('a.share_id', 's.id'))
			->where($qb->expr()->eq('s.owner_user_id', $qb->createNamedParameter($ownerUserId, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('s.status', $qb->createNamedParameter(Share::STATUS_ACCEPTED, IQueryBuilder::PARAM_STR)))
			->andWhere($qb->expr()->eq('a.entity_type', $qb->createNamedParameter($entityType, IQueryBuilder::PARAM_STR)));
		$result = $qb->executeQuery();
		$rows = $result->fetchAll();
		$result->closeCursor();
		return array_map(static fn ($r) => [
			'shareId' => (int)$r['share_id'],
			'permission' => (string)$r['permission'],
		], $rows);
	}

	/**
	 * Enable auto-share for a (share, type) at the given permission, or update the
	 * permission if it already exists. Idempotent.
	 */
	public function setConfig(int $shareId, string $entityType, string $permission): void {
		$existing = $this->findByShareIdAndType($shareId, $entityType);
		$now = date('Y-m-d H:i:s');
		if ($existing !== null) {
			$existing->setPermission($permission);
			$existing->setUpdatedAt($now);
			$this->update($existing);
			return;
		}
		$cfg = new ShareAutoConfig();
		$cfg->setShareId($shareId);
		$cfg->setEntityType($entityType);
		$cfg->setPermission($permission);
		$cfg->setCreatedAt($now);
		$cfg->setUpdatedAt($now);
		$this->insert($cfg);
	}

	public function findByShareIdAndType(int $shareId, string $entityType): ?ShareAutoConfig {
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')
			->from($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType, IQueryBuilder::PARAM_STR)));
		$rows = $this->findEntities($qb);
		return $rows[0] ?? null;
	}

	/** Disable auto-share for a (share, type). Idempotent. */
	public function removeConfig(int $shareId, string $entityType): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType, IQueryBuilder::PARAM_STR)));
		$qb->executeStatement();
	}

	/** Remove all auto-share rules for a share (cascade when the share is revoked). */
	public function deleteByShareId(int $shareId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)));
		$qb->executeStatement();
	}
}
