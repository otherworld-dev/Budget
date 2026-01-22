<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Settlement>
 */
class SettlementMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_settlements', Settlement::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Settlement {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        return $this->findEntity($qb);
    }

    /**
     * @return Settlement[]
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
     * Find all settlements for a contact.
     *
     * @return Settlement[]
     */
    public function findByContact(int $contactId, string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('contact_id', $qb->createNamedParameter($contactId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('date', 'DESC');

        return $this->findEntities($qb);
    }

    /**
     * Get total settled amount per contact (positive = they paid you).
     *
     * @return array<int, float>
     */
    public function getTotalsByContact(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('contact_id')
            ->selectAlias($qb->func()->sum('amount'), 'total')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->groupBy('contact_id');

        $result = $qb->executeQuery();
        $totals = [];
        while ($row = $result->fetch()) {
            $totals[(int) $row['contact_id']] = (float) $row['total'];
        }
        $result->closeCursor();

        return $totals;
    }

    /**
     * Delete all settlements for a user
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
