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
 * Resolve the is_split grey states once, from the parts table (#360).
 *
 * is_split predates its own default, so rows imported before the column
 * existed hold NULL — and restores from archives made while budget_tx_splits
 * was missing from the backup registry (#351) wrote is_split = true for
 * parts that never made it into the backup. Both grey states forced every
 * reader into "parts are the truth" workarounds (the partition predicates),
 * while write paths trusting the raw flag silently discarded categories on
 * stray-true rows and refused rule runs on NULL rows.
 *
 * After this runs the flag agrees with the parts table for every existing
 * row: NULL resolves to whether parts exist, and a true claim with no parts
 * behind it is cleared. An explicit false is left alone even when stray
 * parts reference the row — that is the deliberate unsplit policy (#356):
 * the row counts directly and the leftovers do not.
 *
 * The restore path (MigrationService::markSplitParents) now resolves the
 * flag the same way on every import, so the grey states do not come back.
 */
class Version001000100Date20260828 extends SimpleMigrationStep {

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

        if (!$schema->hasTable('budget_transactions') || !$schema->hasTable('budget_tx_splits')) {
            return;
        }

        $resolved = $this->resolveFlag(true, nullOnly: true, mustHaveParts: true)
            + $this->resolveFlag(false, nullOnly: true, mustHaveParts: false);
        $cleared = $this->resolveFlag(false, nullOnly: false, mustHaveParts: false);

        if ($resolved > 0) {
            $output->info("Resolved is_split from the parts table on {$resolved} transaction(s)");
        }
        if ($cleared > 0) {
            $output->info("Cleared a split claim with no parts behind it on {$cleared} transaction(s)");
        }
    }

    /**
     * One set-based UPDATE: rows in the targeted grey state (NULL flag, or a
     * true claim when $nullOnly is false) whose parts existence matches
     * $mustHaveParts get $newValue. The subquery reads only budget_tx_splits,
     * so updating budget_transactions in the same statement is fine on
     * SQLite, MySQL/MariaDB and PostgreSQL alike.
     *
     * @return int affected rows
     */
    private function resolveFlag(bool $newValue, bool $nullOnly, bool $mustHaveParts): int {
        $qb = $this->db->getQueryBuilder();

        $exists = 'EXISTS (SELECT 1 FROM ' . $qb->getTableName('budget_tx_splits') . ' bsx'
            . ' WHERE bsx.transaction_id = ' . $qb->getTableName('budget_transactions') . '.id)';

        $qb->update('budget_transactions')
            ->set('is_split', $qb->createNamedParameter($newValue, IQueryBuilder::PARAM_BOOL));

        if ($nullOnly) {
            $qb->where($qb->expr()->isNull('is_split'));
        } else {
            $qb->where($qb->expr()->eq('is_split', $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)));
        }

        $qb->andWhere(($mustHaveParts ? '' : 'NOT ') . $exists);

        return $qb->executeStatement();
    }
}
