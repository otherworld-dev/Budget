<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<BankAccountMapping>
 */
class BankAccountMappingMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_bank_account_mappings', BankAccountMapping::class);
    }

    public function find(int $id): BankAccountMapping {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        return $this->findEntity($qb);
    }

    /**
     * @return BankAccountMapping[]
     */
    public function findByConnection(int $connectionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)))
            ->orderBy('external_account_name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * @return BankAccountMapping[]
     */
    public function findEnabledByConnection(int $connectionId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('enabled', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)))
            ->andWhere($qb->expr()->isNotNull('budget_account_id'));

        return $this->findEntities($qb);
    }

    public function findByExternalId(int $connectionId, string $externalAccountId): ?BankAccountMapping {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('external_account_id', $qb->createNamedParameter($externalAccountId)));

        $entities = $this->findEntities($qb);
        return $entities[0] ?? null;
    }

    public function deleteByConnection(int $connectionId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('connection_id', $qb->createNamedParameter($connectionId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }
}
