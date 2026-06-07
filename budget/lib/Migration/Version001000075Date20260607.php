<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds an optional start date to bills. A bill only occurs on/after its start
 * date — letting users change a recurring service's cost mid-year by ending one
 * bill and starting another at a specific date (#268).
 */
class Version001000075Date20260607 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_bills')) {
            return null;
        }

        $table = $schema->getTable('budget_bills');
        if (!$table->hasColumn('start_date')) {
            $table->addColumn('start_date', Types::DATE, [
                'notnull' => false,
            ]);
        }

        return $schema;
    }
}
