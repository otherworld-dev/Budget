<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Account-level "exclude from reports" flag (#286). When set, the account is
 * omitted from every "all accounts" aggregation — reports, dashboard, forecast,
 * net worth, total balance and category budgets — while remaining fully usable
 * on its own (accounts list, detail page, transaction list).
 */
class Version001000082Date20260614 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_accounts')) {
            return null;
        }

        $table = $schema->getTable('budget_accounts');

        if (!$table->hasColumn('excluded_from_reports')) {
            $table->addColumn('excluded_from_reports', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            return $schema;
        }

        return null;
    }
}
