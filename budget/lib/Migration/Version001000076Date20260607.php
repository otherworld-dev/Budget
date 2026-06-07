<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds an "exclude from forecast" flag to transactions, bills and recurring
 * income (#270). Extraordinary one-time amounts (e.g. selling a car) distort
 * financial projections; flagged items still affect the real balance but are
 * left out of the historical averages the forecast extrapolates from. The flag
 * on a recurring bill/income propagates to the transactions it generates.
 */
class Version001000076Date20260607 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        foreach (['budget_transactions', 'budget_bills', 'budget_recurring_income'] as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('excluded_from_forecast')) {
                $table->addColumn('excluded_from_forecast', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => false,
                ]);
            }
        }

        return $schema;
    }
}