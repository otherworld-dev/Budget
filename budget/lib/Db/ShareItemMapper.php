<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ShareItem>
 */
class ShareItemMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_share_items', ShareItem::class);
    }

    /**
     * Get all share items for a share
     *
     * @return ShareItem[]
     */
    public function findByShareId(int $shareId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)));
        return $this->findEntities($qb);
    }

    /**
     * Get share items for a share filtered by entity type
     *
     * @return ShareItem[]
     */
    public function findByShareIdAndType(int $shareId, string $entityType): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));
        return $this->findEntities($qb);
    }

    /**
     * Get just the entity IDs for a share and type
     *
     * @return int[]
     */
    public function findSharedEntityIds(int $shareId, string $entityType): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('entity_id')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[] = (int) $row['entity_id'];
        }
        $result->closeCursor();
        return $ids;
    }

    /**
     * Get entity IDs with their permissions for a share and type
     *
     * @return array<int, string> entityId => permission
     */
    public function findSharedEntityPermissions(int $shareId, string $entityType): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('entity_id', 'permission')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));

        $result = $qb->executeQuery();
        $permissions = [];
        while ($row = $result->fetch()) {
            $permissions[(int) $row['entity_id']] = $row['permission'];
        }
        $result->closeCursor();
        return $permissions;
    }

    /**
     * Check if a specific entity is shared with a given permission (or any)
     */
    public function isEntityShared(int $shareId, string $entityType, int $entityId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
            ->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();
        return $count > 0;
    }

    /**
     * Get the permission for a specific shared entity
     */
    public function getEntityPermission(int $shareId, string $entityType, int $entityId): ?string {
        $qb = $this->db->getQueryBuilder();
        $qb->select('permission')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
            ->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $permission = $result->fetchOne();
        $result->closeCursor();
        return $permission !== false ? $permission : null;
    }

    /**
     * Replace all items for a share and entity type (delete + insert).
     * Used by the settings panel save action.
     *
     * @param int[] $entityIds
     */
    public function replaceForShareAndType(int $shareId, string $entityType, array $entityIds, string $permission): void {
        $this->db->beginTransaction();
        try {
            // Delete existing
            $this->deleteByShareIdAndType($shareId, $entityType);

            // Insert new
            $now = date('Y-m-d H:i:s');
            foreach ($entityIds as $entityId) {
                $item = new ShareItem();
                $item->setShareId($shareId);
                $item->setEntityType($entityType);
                $item->setEntityId((int) $entityId);
                $item->setPermission($permission);
                $item->setCreatedAt($now);
                $item->setUpdatedAt($now);
                $this->insert($item);
            }

            $this->db->commit();
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Delete all items for a share (cascade on revoke/leave)
     */
    public function deleteByShareId(int $shareId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Delete all items for a share and entity type
     */
    public function deleteByShareIdAndType(int $shareId, string $entityType): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('share_id', $qb->createNamedParameter($shareId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));
        $qb->executeStatement();
    }

    /**
     * Delete share items for a specific entity (cascade on entity deletion)
     */
    public function deleteByEntity(string $entityType, int $entityId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
            ->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
