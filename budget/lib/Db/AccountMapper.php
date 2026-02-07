<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCA\Budget\Db\Trait\EncryptedFieldsTrait;
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
    use EncryptedFieldsTrait;

    public function __construct(IDBConnection $db, EncryptionService $encryptionService) {
        parent::__construct($db, 'budget_accounts', Account::class);
        $this->initializeEncryption($encryptionService, Account::class);
    }

    /**
     * Find an account by ID
     *
     * @param int $id Account ID
     * @param string $userId Current user ID (for permission check)
     * @param array|null $accessibleUserIds Optional list of user IDs that current user can access (for shared budgets)
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId, ?array $accessibleUserIds = null): Account {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        // If accessibleUserIds provided, check if account belongs to any accessible user
        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            // Default: only current user's accounts
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $account = $this->findEntity($qb);
        return $this->decryptEntity($account);
    }

    /**
     * Find all accounts for user(s)
     *
     * @param string $userId Current user ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch accounts for (for shared budgets)
     * @return Account[]
     */
    public function findAll(string $userId, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        // If accessibleUserIds provided, get accounts from all accessible users
        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            // Default: only current user's accounts
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->orderBy('user_id', 'ASC')
            ->addOrderBy('name', 'ASC');

        $accounts = $this->findEntities($qb);
        return $this->decryptEntities($accounts);
    }

    /**
     * Calculate total balance for user(s) across all accounts
     *
     * @param string $userId Current user ID
     * @param string|null $currency Optional currency filter
     * @param array|null $accessibleUserIds Optional list of user IDs to calculate balance for (for shared budgets)
     */
    public function getTotalBalance(string $userId, ?string $currency = null, ?array $accessibleUserIds = null): float {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->sum('balance'))
            ->from($this->getTableName());

        // If accessibleUserIds provided, get balance from all accessible users
        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            // Default: only current user's balance
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        if ($currency !== null) {
            $qb->andWhere($qb->expr()->eq('currency', $qb->createNamedParameter($currency)));
        }

        $result = $qb->executeQuery();
        $sum = $result->fetchOne();
        $result->closeCursor();

        return (float) ($sum ?? 0);
    }

    public function updateBalance(int $accountId, float|string $newBalance, string $userId): Account {
        // Normalize to string for database precision
        $balanceStr = is_string($newBalance) ? $newBalance : sprintf('%.2f', $newBalance);

        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('balance', $qb->createNamedParameter($balanceStr))
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
            $this->encryptEntity($entity);
        }
        $inserted = parent::insert($entity);
        if ($inserted instanceof Account) {
            return $this->decryptEntity($inserted);
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
        // Normalize balance to string for database precision
        $balance = $entity->getBalance();
        $balanceStr = is_float($balance) ? sprintf('%.2f', $balance) : (string) $balance;

        $qb = $this->db->getQueryBuilder();
        $qb->update($this->getTableName())
            ->set('name', $qb->createNamedParameter($entity->getName()))
            ->set('type', $qb->createNamedParameter($entity->getType()))
            ->set('balance', $qb->createNamedParameter($balanceStr))
            ->set('currency', $qb->createNamedParameter($entity->getCurrency()))
            ->set('institution', $qb->createNamedParameter($entity->getInstitution()))
            ->set('account_number', $qb->createNamedParameter($this->getEncryptedValue($entity, 'accountNumber')))
            ->set('routing_number', $qb->createNamedParameter($this->getEncryptedValue($entity, 'routingNumber')))
            ->set('sort_code', $qb->createNamedParameter($this->getEncryptedValue($entity, 'sortCode')))
            ->set('iban', $qb->createNamedParameter($this->getEncryptedValue($entity, 'iban')))
            ->set('swift_bic', $qb->createNamedParameter($this->getEncryptedValue($entity, 'swiftBic')))
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
     * Delete all accounts for a user
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
