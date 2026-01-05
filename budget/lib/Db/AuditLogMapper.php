<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<AuditLog>
 */
class AuditLogMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_audit_log', AuditLog::class);
    }

    /**
     * Find audit logs for a user with optional filtering.
     *
     * @param string $userId
     * @param string|null $action Filter by action type
     * @param string|null $entityType Filter by entity type
     * @param int|null $entityId Filter by entity ID
     * @param int $limit
     * @param int $offset
     * @return AuditLog[]
     */
    public function findByUser(
        string $userId,
        ?string $action = null,
        ?string $entityType = null,
        ?int $entityId = null,
        int $limit = 100,
        int $offset = 0
    ): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        if ($action !== null) {
            $qb->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)));
        }

        if ($entityType !== null) {
            $qb->andWhere($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)));
        }

        if ($entityId !== null) {
            $qb->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)));
        }

        $qb->orderBy('created_at', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    /**
     * Find audit logs for a specific entity.
     *
     * @param string $entityType
     * @param int $entityId
     * @param int $limit
     * @return AuditLog[]
     */
    public function findByEntity(string $entityType, int $entityId, int $limit = 50): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('entity_type', $qb->createNamedParameter($entityType)))
            ->andWhere($qb->expr()->eq('entity_id', $qb->createNamedParameter($entityId, IQueryBuilder::PARAM_INT)))
            ->orderBy('created_at', 'DESC')
            ->setMaxResults($limit);

        return $this->findEntities($qb);
    }

    /**
     * Count logs by action type for a user within a time period.
     * Useful for detecting suspicious activity.
     *
     * @param string $userId
     * @param string $action
     * @param \DateTime $since
     * @return int
     */
    public function countRecentActions(string $userId, string $action, \DateTime $since): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'count'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('action', $qb->createNamedParameter($action)))
            ->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($since->format('Y-m-d H:i:s'))));

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count;
    }

    /**
     * Delete old audit logs (for data retention policy).
     *
     * @param int $olderThanDays Delete logs older than this many days
     * @return int Number of deleted records
     */
    public function deleteOldLogs(int $olderThanDays): int {
        $cutoff = new \DateTime();
        $cutoff->modify("-{$olderThanDays} days");

        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff->format('Y-m-d H:i:s'))));

        return $qb->executeStatement();
    }
}
