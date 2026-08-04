<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<IdempotencyKey>
 */
class IdempotencyKeyMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_idem_keys', IdempotencyKey::class);
    }

    /**
     * @throws \OCP\AppFramework\Db\DoesNotExistException
     */
    public function findByKey(string $userId, string $idemKey): IdempotencyKey {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('idem_key', $qb->createNamedParameter($idemKey)));

        return $this->findEntity($qb);
    }

    /**
     * Drop keys older than the retention window. Called opportunistically on
     * writes — no background job for a table this small.
     */
    public function purgeOlderThan(\DateTimeImmutable $cutoff): void {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->lt('created_at', $qb->createNamedParameter($cutoff->format('Y-m-d H:i:s'))));
        $qb->executeStatement();
    }
}
