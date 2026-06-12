<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ReconciliationSession>
 */
class ReconciliationSessionMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_recon_sessions', ReconciliationSession::class);
    }

    public function find(int $id, string $userId): ReconciliationSession {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * The single in-progress session for an account, if any.
     */
    public function findInProgress(int $accountId, string $userId): ?ReconciliationSession {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ReconciliationSession::STATUS_IN_PROGRESS)))
            ->orderBy('id', 'DESC')
            ->setMaxResults(1);

        $entities = $this->findEntities($qb);
        return $entities[0] ?? null;
    }

    /**
     * The most recently completed session — the anchor for the next one.
     */
    public function findLastCompleted(int $accountId, string $userId): ?ReconciliationSession {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ReconciliationSession::STATUS_COMPLETED)))
            ->orderBy('statement_date', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults(1);

        $entities = $this->findEntities($qb);
        return $entities[0] ?? null;
    }

    /**
     * Completed sessions for an account, newest first.
     *
     * @return ReconciliationSession[]
     */
    public function findCompletedByAccount(int $accountId, string $userId, int $limit = 20, int $offset = 0): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('status', $qb->createNamedParameter(ReconciliationSession::STATUS_COMPLETED)))
            ->orderBy('statement_date', 'DESC')
            ->addOrderBy('id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        return $this->findEntities($qb);
    }

    public function deleteByAccount(int $accountId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        $qb->executeStatement();
    }

    public function deleteAll(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $qb->executeStatement();
    }
}
