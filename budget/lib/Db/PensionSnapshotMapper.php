<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<PensionSnapshot>
 */
class PensionSnapshotMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_pen_snaps', PensionSnapshot::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): PensionSnapshot {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * Get all snapshots for a pension, ordered by date descending.
     *
     * @return PensionSnapshot[]
     */
    public function findByPension(int $pensionId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('date', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Get snapshots for a pension within a date range.
     *
     * @return PensionSnapshot[]
     */
    public function findByPensionInRange(int $pensionId, string $userId, string $startDate, string $endDate): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->gte('date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('date', $qb->createNamedParameter($endDate)))
            ->orderBy('date', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get the most recent snapshot for a pension.
     *
     * @throws DoesNotExistException
     */
    public function findLatest(int $pensionId, string $userId): PensionSnapshot {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('date', 'DESC')
            ->setMaxResults(1);

        return $this->findEntity($qb);
    }

    /**
     * Delete all snapshots for a pension.
     */
    public function deleteByPension(int $pensionId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $qb->executeStatement();
    }

    /**
     * Delete all pension snapshots for a user
     *
     * @param string $userId
     * @return int Number of deleted rows
     */
    public function deleteAll(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        return $qb->executeStatement();
    }
}
