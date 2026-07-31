<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add excluded_from_budget flag to budget_categories.
 *
 * Narrower than excluded_from_reports: the category keeps counting in reports,
 * the dashboard and every total — it is only left out of the budget surfaces
 * (Budget view, budget alerts, budget-vs-actual report, envelope rollover), for
 * spending the user tracks but does not want to budget against.
 */
class Version001000092Date20260801 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('budget_categories');
        if (!$table->hasColumn('excluded_from_budget')) {
            $table->addColumn('excluded_from_budget', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
        }

        return $schema;
    }
}
