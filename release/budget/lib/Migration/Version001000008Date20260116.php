<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add updated_at column to categories table for consistency with other entities.
 */
class Version001000008Date20260116 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_categories')) {
            $table = $schema->getTable('budget_categories');

            if (!$table->hasColumn('updated_at')) {
                $table->addColumn('updated_at', Types::DATETIME, [
                    'notnull' => false,
                ]);
            }
        }

        return $schema;
    }
}
