<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCP\IDBConnection;

/**
 * Deletes a user's rows from the backup registry's tables
 * (MigrationService::EXTRA_TABLES_PRE / _POST).
 *
 * Shared by backup restore (wipe-then-restore) and factory reset so the two
 * can never disagree about what "all of a user's data" is: a table added to
 * the registry is cleared by both. Before this, factory reset kept its own
 * hand-written list and silently left a dozen tables behind, including
 * transaction tags, which are only reachable by joining through transactions
 * and so were orphaned for good once the transactions were gone.
 *
 * Join-scoped tables resolve their owner through a parent chain that ends at
 * transactions, accounts or categories, so callers must run this BEFORE
 * deleting those parents.
 */
class UserTableCleaner {
    /**
     * The user's rows a factory reset deletes but a backup restore keeps, in
     * clearing order (children first). None of these is in the backup, so a
     * restore that cleared them would destroy them for good:
     *   - shares the user GRANTED, with their items and auto-share config.
     *     Shares granted TO the user belong to the other user and survive
     *     both (they are matched on owner_user_id only);
     *   - bank connections and their account mappings. Deleted as rows, the
     *     encrypted credentials are never read, so an unreadable secret
     *     cannot block the reset;
     *   - API idempotency keys and the legacy forecasts table.
     * The audit log is kept by both, for compliance.
     *
     * Specs use the registry's shape; 'userColumn' (or a third element on
     * the last join) names the owner column when it is not user_id.
     */
    public const FACTORY_RESET_ONLY = [
        'share_items' => [
            'table' => 'budget_share_items',
            'scope' => ['joins' => [['budget_shares', 'share_id', 'owner_user_id']]],
        ],
        'share_auto' => [
            'table' => 'budget_share_auto',
            'scope' => ['joins' => [['budget_shares', 'share_id', 'owner_user_id']]],
        ],
        'shares' => [
            'table' => 'budget_shares',
            'scope' => 'user',
            'userColumn' => 'owner_user_id',
        ],
        'bank_mappings' => [
            'table' => 'budget_bam',
            'scope' => ['joins' => [['budget_bc', 'connection_id']]],
        ],
        'bank_connections' => [
            'table' => 'budget_bc',
            'scope' => 'user',
        ],
        'idem_keys' => [
            'table' => 'budget_idem_keys',
            'scope' => 'user',
        ],
        'forecasts' => [
            'table' => 'budget_forecasts',
            'scope' => 'user',
        ],
    ];

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    /**
     * Registry specs in clearing order: POST before PRE, because PRE holds
     * tag sets and tags, which POST rows (transaction tags, savings goals)
     * point at.
     *
     * @return array<string, array> registry key => spec
     */
    public static function clearOrder(): array {
        return MigrationService::EXTRA_TABLES_POST + MigrationService::EXTRA_TABLES_PRE;
    }

    /**
     * Clear every registry table for the user.
     *
     * @param bool $skipMissingTables Treat a table that does not exist yet as
     *                                empty instead of failing
     * @return array<string, int> rows deleted per registry key
     */
    public function clearRegisteredTables(string $userId, bool $skipMissingTables = false): array {
        return $this->clearTables($userId, self::clearOrder(), $skipMissingTables);
    }

    /**
     * Clear the tables only a factory reset may touch (FACTORY_RESET_ONLY).
     *
     * @return array<string, int> rows deleted per key
     */
    public function clearFactoryResetOnlyTables(string $userId, bool $skipMissingTables = false): array {
        return $this->clearTables($userId, self::FACTORY_RESET_ONLY, $skipMissingTables);
    }

    /**
     * @param array<string, array> $specs key => spec, in clearing order
     * @return array<string, int>
     */
    private function clearTables(string $userId, array $specs, bool $skipMissingTables): array {
        $counts = [];
        foreach ($specs as $key => $spec) {
            try {
                $counts[$key] = $this->clearTable($userId, $spec);
            } catch (\Exception $e) {
                if ($skipMissingTables && self::isMissingTable($e)) {
                    $counts[$key] = 0;
                    continue;
                }
                throw $e;
            }
        }
        return $counts;
    }

    /**
     * Delete one table's rows for the user. A spec's scope is 'user' (the
     * table has a user_id column, or the column 'userColumn' names) or
     * ['joins' => [[table, localColumn], …]], a chain ending at a table with
     * user_id (or with the column named as a third element of the last join).
     *
     * @return int rows deleted
     */
    public function clearTable(string $userId, array $spec): int {
        if (($spec['scope'] ?? 'user') === 'user') {
            $qb = $this->db->getQueryBuilder();
            $qb->delete($spec['table'])
                ->where($qb->expr()->eq($spec['userColumn'] ?? 'user_id', $qb->createNamedParameter($userId)));
            return $qb->executeStatement();
        }

        $joins = $spec['scope']['joins'];
        // Innermost select: ids of the deepest parent owned by the user
        $deepest = $joins[count($joins) - 1];
        $sql = 'SELECT id FROM *PREFIX*' . $deepest[0] . ' WHERE ' . ($deepest[2] ?? 'user_id') . ' = ?';
        // Wrap outward through the chain
        for ($i = count($joins) - 2; $i >= 0; $i--) {
            [$table] = $joins[$i];
            [, $childColumn] = $joins[$i + 1];
            $sql = 'SELECT id FROM *PREFIX*' . $table . ' WHERE ' . $childColumn . ' IN (' . $sql . ')';
        }
        [, $localColumn] = $joins[0];
        return $this->db->executeStatement(
            'DELETE FROM *PREFIX*' . $spec['table'] . ' WHERE ' . $localColumn . ' IN (' . $sql . ')',
            [$userId]
        );
    }

    /**
     * Whether a database error means the table has not been created yet
     * (sqlite / MySQL wording).
     */
    public static function isMissingTable(\Throwable $e): bool {
        $message = $e->getMessage();
        return str_contains($message, 'no such table')
            || (str_contains($message, 'Table') && str_contains($message, "doesn't exist"))
            // PostgreSQL: relation "oc_budget_x" does not exist
            || (str_contains($message, 'relation') && str_contains($message, 'does not exist'));
    }
}
