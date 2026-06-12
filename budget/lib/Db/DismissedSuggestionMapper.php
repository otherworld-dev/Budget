<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<DismissedSuggestion>
 */
class DismissedSuggestionMapper extends QBMapper {
    public function __construct(IDBConnection $db) {
        parent::__construct($db, 'budget_dismissed_sugg', DismissedSuggestion::class);
    }

    /**
     * All dismissed pattern hashes for a user and suggestion type.
     *
     * @return string[]
     */
    public function findHashes(string $userId, string $type = 'bill'): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('pattern_hash')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
            ->andWhere($qb->expr()->eq('suggestion_type', $qb->createNamedParameter($type)));

        $result = $qb->executeQuery();
        $hashes = array_map(fn($row) => (string) $row['pattern_hash'], $result->fetchAll());
        $result->closeCursor();

        return $hashes;
    }

    /**
     * Record a dismissal (idempotent — re-dismissing is not an error).
     */
    public function dismiss(string $userId, string $type, string $patternHash, ?string $pattern = null): void {
        $entity = new DismissedSuggestion();
        $entity->setUserId($userId);
        $entity->setSuggestionType($type);
        $entity->setPatternHash($patternHash);
        $entity->setPattern($pattern !== null ? mb_substr($pattern, 0, 255) : null);
        $entity->setDismissedAt(date('Y-m-d H:i:s'));

        try {
            $this->insert($entity);
        } catch (\Exception $e) {
            // Unique violation — already dismissed
        }
    }

    public function deleteAll(string $userId): int {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)));
        return $qb->executeStatement();
    }
}
