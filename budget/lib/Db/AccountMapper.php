<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Account>
 */
class AccountMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_accounts', Account::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Account {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * @return Account[]
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
     * Calculate total balance for user across all accounts
     */
    public function getTotalBalance(string $userId, ?string $currency = null): float {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->sum('balance'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        
        if ($currency !== null) {
            $qb->andWhere($qb->expr()->eq('currency', $qb->createNamedParameter($currency)));
        }
        
        $result = $qb->executeQuery();
        $sum = $result->fetchOne();
        $result->closeCursor();
        
        return (float) ($sum ?? 0);
    }

    public function updateBalance(int $accountId, float $newBalance, string $userId): Account {
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('balance', $qb->createNamedParameter($newBalance))
            ->set('updated_at', $qb->createNamedParameter(date('Y-m-d H:i:s')))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        return $this->find($accountId, $userId);
    }

    /**
     * Override update to ensure all fields are persisted correctly
     * This works around an issue where Entity setters don't always mark fields as updated
     */
    public function update(\OCP\AppFramework\Db\Entity $entity): \OCP\AppFramework\Db\Entity {
        if (!($entity instanceof Account)) {
            return parent::update($entity);
        }

        /** @var Account $entity */
        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('name', $qb->createNamedParameter($entity->getName()))
            ->set('type', $qb->createNamedParameter($entity->getType()))
            ->set('balance', $qb->createNamedParameter($entity->getBalance()))
            ->set('currency', $qb->createNamedParameter($entity->getCurrency()))
            ->set('institution', $qb->createNamedParameter($entity->getInstitution()))
            ->set('account_number', $qb->createNamedParameter($entity->getAccountNumber()))
            ->set('routing_number', $qb->createNamedParameter($entity->getRoutingNumber()))
            ->set('sort_code', $qb->createNamedParameter($entity->getSortCode()))
            ->set('iban', $qb->createNamedParameter($entity->getIban()))
            ->set('swift_bic', $qb->createNamedParameter($entity->getSwiftBic()))
            ->set('account_holder_name', $qb->createNamedParameter($entity->getAccountHolderName()))
            ->set('opening_date', $qb->createNamedParameter($entity->getOpeningDate()))
            ->set('interest_rate', $qb->createNamedParameter($entity->getInterestRate()))
            ->set('credit_limit', $qb->createNamedParameter($entity->getCreditLimit()))
            ->set('overdraft_limit', $qb->createNamedParameter($entity->getOverdraftLimit()))
            ->set('updated_at', $qb->createNamedParameter($entity->getUpdatedAt()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        // Reload the entity from database to ensure we return the persisted state
        return $this->find($entity->getId(), $entity->getUserId());
    }
}