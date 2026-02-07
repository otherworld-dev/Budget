<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<SavingsGoal>
 */
class SavingsGoalMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_savings_goals', SavingsGoal::class);
    }

    /**
     * @param int $id Goal ID
     * @param string $userId Current user ID (for permission check)
     * @param array|null $accessibleUserIds Optional list of user IDs that current user can access (for shared budgets)
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId, ?array $accessibleUserIds = null): SavingsGoal {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->andWhere($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        return $this->findEntity($qb);
    }

    /**
     * @param string $userId Current user ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch goals for (for shared budgets)
     * @return SavingsGoal[]
     */
    public function findAll(string $userId, ?array $accessibleUserIds = null): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName());

        if ($accessibleUserIds !== null && count($accessibleUserIds) > 0) {
            $qb->where($qb->expr()->in('user_id', $qb->createNamedParameter($accessibleUserIds, IQueryBuilder::PARAM_STR_ARRAY)));
        } else {
            $qb->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        }

        $qb->orderBy('name', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Delete all savings goals for a user
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
