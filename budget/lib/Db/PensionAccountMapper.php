<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<PensionAccount>
 */
class PensionAccountMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_pensions', PensionAccount::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): PensionAccount {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * @return PensionAccount[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @return PensionAccount[]
     */
    public function findByType(string $userId, string $type): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($type)))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get all defined contribution pensions (workplace, personal, sipp).
     *
     * @return PensionAccount[]
     */
    public function findDefinedContribution(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('type', $qb->createNamedParameter(
                PensionAccount::DC_TYPES,
                IQueryBuilder::PARAM_STR_ARRAY
            )))
            ->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Get total current balance across all DC pensions.
     */
    public function getTotalBalance(string $userId): float {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->sum('current_balance'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->in('type', $qb->createNamedParameter(
                PensionAccount::DC_TYPES,
                IQueryBuilder::PARAM_STR_ARRAY
            )));

        $result = $qb->executeQuery();
        $sum = $result->fetchOne();
        $result->closeCursor();

        return (float)($sum ?? 0);
    }

    /**
     * Get total transfer value from DB pensions.
     */
    public function getTotalTransferValue(string $userId): float {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->sum('transfer_value'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('type', $qb->createNamedParameter(PensionAccount::TYPE_DEFINED_BENEFIT)));

        $result = $qb->executeQuery();
        $sum = $result->fetchOne();
        $result->closeCursor();

        return (float)($sum ?? 0);
    }
}
