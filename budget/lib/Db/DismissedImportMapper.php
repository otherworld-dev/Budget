<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<DismissedImport>
 */
class DismissedImportMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_dismissed_imports', DismissedImport::class);
    }

    /**
     * Check if an import ID has been dismissed for a given account.
     */
    public function isDismissed(int $accountId, string $importId): bool {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('import_id', $qb->createNamedParameter($importId)));

        $result = $qb->executeQuery();
        $count = (int) $result->fetchOne();
        $result->closeCursor();

        return $count > 0;
    }

    /**
     * Dismiss an import ID so it won't be re-imported.
     */
    public function dismiss(int $accountId, string $importId): DismissedImport {
        $entity = new DismissedImport();
        $entity->setAccountId($accountId);
        $entity->setImportId($importId);
        $entity->setDismissedAt(date('Y-m-d H:i:s'));
        return $this->insert($entity);
    }

    /**
     * Delete all dismissed imports for an account (e.g., when account is deleted).
     */
    public function deleteByAccount(int $accountId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        return $qb->executeStatement();
    }
}
