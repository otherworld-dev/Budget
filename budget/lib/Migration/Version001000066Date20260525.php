<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add group_name column to budget_import_rules for optional rule grouping.
 */
class Version001000066Date20260525 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_import_rules')) {
            $table = $schema->getTable('budget_import_rules');

            if (!$table->hasColumn('group_name')) {
                $table->addColumn('group_name', Types::STRING, [
                    'notnull' => false,
                    'length' => 100,
                ]);
            }
        }

        return $schema;
    }
}
