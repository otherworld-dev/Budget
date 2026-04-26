<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCA\Budget\Db\Trait\EncryptedFieldsTrait;
use OCA\Budget\Service\EncryptionService;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<BankConnection>
 */
class BankConnectionMapper extends QBMapper {
    use EncryptedFieldsTrait;

    public function __construct(IDBConnection $db, EncryptionService $encryptionService) {
        parent::__construct($db, 'budget_bc', BankConnection::class);
        $this->initializeEncryption($encryptionService, BankConnection::class);
    }

    public function find(int $id, string $userId): BankConnection {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));

        $entity = $this->findEntity($qb);
        $this->decryptEntity($entity);
        return $entity;
    }

    /**
     * @return BankConnection[]
     */
    public function findAll(string $userId): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->orderBy('created_at', 'DESC');

        $entities = $this->findEntities($qb);
        foreach ($entities as $entity) {
            $this->decryptEntity($entity);
        }
        return $entities;
    }

    /**
     * Find all active connections across all users (for background job).
     *
     * @return BankConnection[]
     */
    public function findActiveForSync(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('status', $qb->createNamedParameter('active')));

        $entities = $this->findEntities($qb);
        foreach ($entities as $entity) {
            $this->decryptEntity($entity);
        }
        return $entities;
    }

    public function insert(Entity $entity): Entity {
        $this->encryptEntity($entity);
        $result = parent::insert($entity);
        $this->decryptEntity($result);
        return $result;
    }

    public function update(Entity $entity): Entity {
        $this->encryptEntity($entity);
        $result = parent::update($entity);
        $this->decryptEntity($result);
        return $result;
    }
}
