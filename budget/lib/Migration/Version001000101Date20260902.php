<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Account "closed" flag (#372). A closed account keeps its history and still
 * counts in every total — reports, dashboard, net worth — but is left out of
 * every picker that assigns new activity to an account: the transaction and
 * quick-add forms, transfers, bills, income, imports, rules, bank sync.
 *
 * Closing is gated by AccountClosureService: the balance must be zero, nothing
 * may be dated after today, and nothing may still be scheduled to post into
 * the account. The dropdowns keep that true afterwards.
 */
class Version001000101Date20260902 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_accounts')) {
            return null;
        }

        $table = $schema->getTable('budget_accounts');

        if (!$table->hasColumn('closed')) {
            $table->addColumn('closed', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            return $schema;
        }

        return null;
    }
}
