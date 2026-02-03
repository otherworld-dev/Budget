<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add custom_recurrence_pattern column to budget_bills table.
 * Enables custom frequency patterns for bills (e.g., specific months of the year).
 */
class Version001000023Date20260203 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_bills')) {
            $table = $schema->getTable('budget_bills');

            // Add custom_recurrence_pattern column for storing JSON pattern data
            if (!$table->hasColumn('custom_recurrence_pattern')) {
                $table->addColumn('custom_recurrence_pattern', Types::TEXT, [
                    'notnull' => false,
                    'comment' => 'JSON pattern for custom frequency (e.g., {"months": [1, 6, 7]})',
                ]);
            }
        }

        return $schema;
    }
}
