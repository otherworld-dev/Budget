<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<Auth>
 */
class AuthMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_auth', Auth::class);
    }

    /**
     * Find auth record by user ID
     *
     * @param string $userId
     * @return Auth
     * @throws DoesNotExistException
     */
    public function findByUserId(string $userId): Auth {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }

    /**
     * Find auth record by session token
     *
     * @param string $sessionToken
     * @return Auth
     * @throws DoesNotExistException
     */
    public function findBySessionToken(string $sessionToken): Auth {
        $qb = $this->db->getQueryBuilder();

        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('session_token', $qb->createNamedParameter($sessionToken, IQueryBuilder::PARAM_STR)));

        return $this->findEntity($qb);
    }

    /**
     * Delete auth record by user ID
     *
     * @param string $userId
     * @return int Number of deleted rows
     */
    public function deleteByUserId(string $userId): int {
        $qb = $this->db->getQueryBuilder();

        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId, IQueryBuilder::PARAM_STR)));

        return $qb->executeStatement();
    }
}
