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
 * Reclaim expense splits and settlements left behind by deleted contacts (#391).
 *
 * Deleting a contact removed only the contact row, although the confirmation
 * said everything split with them would go too. SharedExpenseService::
 * deleteContact() now removes them; this clears what it already left.
 *
 * Every balance and contact view starts from the contact, so these rows have
 * not counted anywhere since the contact went. The one visible trace is the
 * Shared badge on the split transaction, which nothing could clear and which
 * goes with them.
 */
class Version001000105Date20260919 extends SimpleMigrationStep {

    private const ORPHANED_TABLES = ['budget_expense_shares', 'budget_settlements'];

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

        if (!$schema->hasTable('budget_contacts')) {
            return;
        }

        $live = $this->liveContactIds();

        foreach (self::ORPHANED_TABLES as $table) {
            if (!$schema->hasTable($table)) {
                continue;
            }

            $deleted = $this->deleteOrphans($table, $live);
            if ($deleted > 0) {
                $output->info("Removed {$deleted} row(s) from {$table} whose contact was deleted");
            }
        }
    }

    /**
     * @return array<int, true> live contact ids, as a lookup
     */
    private function liveContactIds(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id')->from('budget_contacts');

        $result = $qb->executeQuery();
        $ids = [];
        while ($row = $result->fetch()) {
            $ids[(int)$row['id']] = true;
        }
        $result->closeCursor();

        return $ids;
    }

    /**
     * Found first and deleted by primary key in batches, as DELETE cannot
     * join and a NOT IN over every contact id would be unbounded.
     *
     * @param array<int, true> $liveContactIds
     */
    private function deleteOrphans(string $table, array $liveContactIds): int {
        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'contact_id')->from($table);

        $result = $qb->executeQuery();
        $orphanIds = [];
        while ($row = $result->fetch()) {
            if (!isset($liveContactIds[(int)$row['contact_id']])) {
                $orphanIds[] = (int)$row['id'];
            }
        }
        $result->closeCursor();

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
