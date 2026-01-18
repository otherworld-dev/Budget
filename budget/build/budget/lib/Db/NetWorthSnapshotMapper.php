<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<NetWorthSnapshot>
 */
class NetWorthSnapshotMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_nw_snaps', NetWorthSnapshot::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): NetWorthSnapshot {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * Get all snapshots for a user, ordered by date descending.
     *
     * @return NetWorthSnapshot[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('date', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Get snapshots for a user within a date range, ordered by date ascending (for charts).
     *
     * @return NetWorthSnapshot[]
     */
    public function findByDateRange(string $userId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($endDate)))
            ->orderBy('date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find snapshot for a specific date.
     */
    public function findByDate(string $userId, string $date): ?NetWorthSnapshot {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('date', $qb->createNamedParameter($date)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Get the most recent snapshot for a user.
     */
    public function findLatest(string $userId): ?NetWorthSnapshot {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('date', 'DESC')
            ->setMaxResults(1);

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Delete all snapshots for a user.
     */
    public function deleteAll(string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $qb->executeStatement();
    }
}
