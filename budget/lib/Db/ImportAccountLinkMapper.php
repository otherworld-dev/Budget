<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ImportAccountLink>
 */
class ImportAccountLinkMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_imp_links', ImportAccountLink::class);
    }

    /**
     * @throws DoesNotExistException
     */
    public function findBySource(string $userId, string $format, string $sourceKey): ImportAccountLink {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('format', $qb->createNamedParameter($format)))
            ->andWhere($qb->expr()->eq('source_key', $qb->createNamedParameter($sourceKey)));

        return $this->findEntity($qb);
    }

    /**
     * @return ImportAccountLink[]
     */
    public function findAllByFormat(string $userId, string $format): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('format', $qb->createNamedParameter($format)));

        return $this->findEntities($qb);
    }
}
