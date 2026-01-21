<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<PensionContribution>
 */
class PensionContributionMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_pen_contribs', PensionContribution::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): PensionContribution {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * Get all contributions for a pension, ordered by date descending.
     *
     * @return PensionContribution[]
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
     * Get contributions for a pension within a date range.
     *
     * @return PensionContribution[]
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
     * Get total contributions for a pension.
     */
    public function getTotalByPension(int $pensionId, string $userId): float {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->sum('amount'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $sum = $result->fetchOne();
        $result->closeCursor();

        return (float)($sum ?? 0);
    }

    /**
     * Delete all contributions for a pension.
     */
    public function deleteByPension(int $pensionId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('pension_id', $qb->createNamedParameter($pensionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $qb->executeStatement();
    }
}
