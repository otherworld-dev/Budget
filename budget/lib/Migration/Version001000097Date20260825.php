<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Reclaim split and attachment rows left behind by deleted transactions (#359).
 *
 * Deleting a transaction cascaded its tags, expense shares and attachments but
 * never its splits, and two paths — dropping a duplicate scheduled bill
 * placeholder, and deleting a bill's placeholders along with the bill — went
 * straight to the mapper and cascaded nothing at all. Both are fixed in
 * TransactionService::deleteWithChildren(); this clears what they already left.
 *
 * These rows cannot be reached any other way. Every cleanup query, factory
 * reset included, finds its rows by joining back through budget_transactions,
 * and an orphan has no transaction to join to — so it survives everything and
 * accumulates for the life of the install. On one long-lived test instance 46
 * of 79 split rows were orphans.
 *
 * Nothing user-visible is removed: an orphan references a transaction that no
 * longer exists, so no view, report or total has ever been able to reach it.
 * Attachment FILES in the user's Nextcloud Files are untouched — only the rows
 * pointing at them, whose transaction is already gone.
 */
class Version001000097Date20260825 extends SimpleMigrationStep {

    /** Child tables keyed by the column referencing budget_transactions.id */
    private const ORPHANED_TABLES = [
        'budget_tx_splits' => 'transaction_id',
        'budget_attachments' => 'transaction_id',
        'budget_transaction_tags' => 'transaction_id',
        'budget_expense_shares' => 'transaction_id',
    ];

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_transactions')) {
            return;
        }

        $live = $this->liveTransactionIds();

        foreach (self::ORPHANED_TABLES as $table => $column) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $deleted = $this->deleteOrphans($table, $column, $live);
            if ($deleted > 0) {
                $output->info("Removed {$deleted} orphaned row(s) from {$table}");
            }
        }
    }

    /**
     * @return array<int, true> live transaction ids, as a lookup
     */
    private function liveTransactionIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('budget_transactions');

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[(int)$row['id']] = true;
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * DELETE cannot join, and a NOT IN over every transaction id would be an
     * unbounded parameter list on a large ledger, so the orphans are found
     * first and deleted by their own primary key in batches.
     *
     * @param array<int, true> $liveTransactionIds
     */
    private function deleteOrphans(string $table, string $column, array $liveTransactionIds): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', $column)->from($table);

        $result = $qb->executeQuery();
        $orphanIds = [];
        while ($row = $result->fetch()) {
            $referenced = $row[$column] === null ? null : (int)$row[$column];
            // A NULL reference belongs to no transaction and is left alone:
            // this migration only reclaims rows pointing at one that is gone.
            if ($referenced !== null && !isset($liveTransactionIds[$referenced])) {
                $orphanIds[] = (int)$row['id'];
            }
        }
        $result->closeCursor();

        if (empty($orphanIds)) {
            return 0;
        }

        $deleted = 0;
        foreach (array_chunk($orphanIds, 500) as $chunk) {
            $del = $this->db->getQueryBuilder();
            $del->delete($table)
                ->where($del->expr()->in('id', $del->createNamedParameter($chunk, IQueryBuilder::PARAM_INT_ARRAY)));
            $deleted += $del->executeStatement();
        }

        return $deleted;
    }
}
