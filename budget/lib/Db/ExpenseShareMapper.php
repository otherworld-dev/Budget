<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ExpenseShare>
 */
class ExpenseShareMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_expense_shares', ExpenseShare::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): ExpenseShare {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * @return ExpenseShare[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find all shares for a transaction.
     *
     * @return ExpenseShare[]
     */
    public function findByTransaction(int $transactionId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntities($qb);
    }

    /**
     * Find all shares for a contact.
     *
     * @return ExpenseShare[]
     */
    public function findByContact(int $contactId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('contact_id', $qb->createNamedParameter($contactId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find all unsettled shares.
     *
     * @return ExpenseShare[]
     */
    public function findUnsettled(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_settled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Find unsettled shares for a specific contact.
     *
     * @return ExpenseShare[]
     */
    public function findUnsettledByContact(int $contactId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('contact_id', $qb->createNamedParameter($contactId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_settled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->orderBy('created_at', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Delete all shares for a transaction.
     */
    public function deleteByTransaction(int $transactionId, string $userId): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('transaction_id', $qb->createNamedParameter($transactionId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $qb->executeStatement();
    }

    /**
     * Get balance summary per contact, grouped by currency.
     *
     * @return array<int, array<string, float>> Contact ID => [currency => balance]
     */
    public function getBalancesByContact(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('contact_id', 'currency')
            ->selectAlias($qb->func()->sum('amount'), 'balance')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('is_settled', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
            ->groupBy('contact_id', 'currency');

        $result = $qb->executeQuery();
        $balances = [];
        while ($row = $result->fetch()) {
            $contactId = (int) $row['contact_id'];
            $currency = $row['currency'] ?? 'USD';
            if (!isset($balances[$contactId])) {
                $balances[$contactId] = [];
            }
            $balances[$contactId][$currency] = (float) $row['balance'];
        }
        $result->closeCursor();

        return $balances;
    }

    /**
     * Get shared transaction IDs with their settlement status.
     *
     * @return array<int, string> transaction_id => 'shared' or 'settled'
     */
    public function getSharedTransactionStatuses(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('transaction_id')
            ->addSelect('is_settled')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $result = $qb->executeQuery();
        $statuses = [];
        while ($row = $result->fetch()) {
            $txId = (int) $row['transaction_id'];
            $isSettled = (bool) $row['is_settled'];
            // If any share on this transaction is unsettled, status is 'shared'
            if (!isset($statuses[$txId])) {
                $statuses[$txId] = $isSettled ? 'settled' : 'shared';
            } elseif (!$isSettled) {
                $statuses[$txId] = 'shared';
            }
        }
        $result->closeCursor();

        return $statuses;
    }

    /**
     * Delete all expense shares for a user
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
