<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCA\Budget\Service\EncryptionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Account>
 */
class AccountMapper extends QBMapper {
    private EncryptionService $encryptionService;

    public function __construct(IDBConnection $db, EncryptionService $encryptionService) {
        parent::__construct($db, 'budget_accounts', Account::class);
        $this->encryptionService = $encryptionService;
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

        $account = $this->findEntity($qb);
        return $this->decryptAccount($account);
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

        $accounts = $this->findEntities($qb);
        return array_map(fn($account) => $this->decryptAccount($account), $accounts);
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
     * Override insert to encrypt sensitive fields before storing.
     */
    public function insert(Entity $entity): Entity {
        if ($entity instanceof Account) {
            $this->encryptAccountFields($entity);
        }
        $inserted = parent::insert($entity);
        if ($inserted instanceof Account) {
            return $this->decryptAccount($inserted);
        }
        return $inserted;
    }

    /**
     * Override update to ensure all fields are persisted correctly with encryption.
     * This works around an issue where Entity setters don't always mark fields as updated.
     */
    public function update(Entity $entity): Entity {
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
            ->set('account_number', $qb->createNamedParameter($this->encryptionService->encrypt($entity->getAccountNumber())))
            ->set('routing_number', $qb->createNamedParameter($this->encryptionService->encrypt($entity->getRoutingNumber())))
            ->set('sort_code', $qb->createNamedParameter($this->encryptionService->encrypt($entity->getSortCode())))
            ->set('iban', $qb->createNamedParameter($this->encryptionService->encrypt($entity->getIban())))
            ->set('swift_bic', $qb->createNamedParameter($this->encryptionService->encrypt($entity->getSwiftBic())))
            ->set('account_holder_name', $qb->createNamedParameter($entity->getAccountHolderName()))
            ->set('opening_date', $qb->createNamedParameter($entity->getOpeningDate()))
            ->set('interest_rate', $qb->createNamedParameter($entity->getInterestRate()))
            ->set('credit_limit', $qb->createNamedParameter($entity->getCreditLimit()))
            ->set('overdraft_limit', $qb->createNamedParameter($entity->getOverdraftLimit()))
            ->set('updated_at', $qb->createNamedParameter($entity->getUpdatedAt()))
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($entity->getId(), IQueryBuilder::PARAM_INT)));

        $qb->executeStatement();

        // Reload the entity from database to ensure we return the persisted state (decrypted)
        return $this->find($entity->getId(), $entity->getUserId());
    }

    /**
     * Decrypt sensitive fields on an Account entity.
     */
    private function decryptAccount(Account $account): Account {
        $account->setAccountNumber($this->encryptionService->decrypt($account->getAccountNumber()));
        $account->setRoutingNumber($this->encryptionService->decrypt($account->getRoutingNumber()));
        $account->setSortCode($this->encryptionService->decrypt($account->getSortCode()));
        $account->setIban($this->encryptionService->decrypt($account->getIban()));
        $account->setSwiftBic($this->encryptionService->decrypt($account->getSwiftBic()));
        return $account;
    }

    /**
     * Encrypt sensitive fields on an Account entity before insert.
     */
    private function encryptAccountFields(Account $account): void {
        $account->setAccountNumber($this->encryptionService->encrypt($account->getAccountNumber()));
        $account->setRoutingNumber($this->encryptionService->encrypt($account->getRoutingNumber()));
        $account->setSortCode($this->encryptionService->encrypt($account->getSortCode()));
        $account->setIban($this->encryptionService->encrypt($account->getIban()));
        $account->setSwiftBic($this->encryptionService->encrypt($account->getSwiftBic()));
    }
}