<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add columns to import_rules table to support flexible actions and apply-on-import flag.
 * This enables rules to be applied to existing transactions (not just during import)
 * and to set any transaction field (not just category).
 */
class Version001000016Date20260118 extends SimpleMigrationStep {

    /**
     * Drop broken boolean columns with raw SQL
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $connection = \OC::$server->getDatabaseConnection();
        $prefix = \OC::$server->getConfig()->getSystemValue('dbtableprefix', 'oc_');

        try {
            $schema = $schemaClosure();
            if ($schema->hasTable('budget_import_rules')) {
                $table = $schema->getTable('budget_import_rules');
                if ($table->hasColumn('apply_on_import')) {
                    $tableName = $prefix . 'budget_import_rules';
                    $connection->executeStatement("ALTER TABLE $tableName DROP COLUMN apply_on_import");
                }
            }
        } catch (\Exception $e) {
            // Column might not exist, continue
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_import_rules')) {
            $table = $schema->getTable('budget_import_rules');

            // Add actions column - JSON storage for flexible field actions
            // Example: {"categoryId": 5, "vendor": "Amazon", "notes": "Shopping"}
            if (!$table->hasColumn('actions')) {
                $table->addColumn('actions', Types::TEXT, [
                    'notnull' => false,
                ]);
            }

            // Add apply_on_import flag (was dropped in preSchemaChange if existed)
            // Defaults to true for backward compatibility
            if (!$table->hasColumn('apply_on_import')) {
                $table->addColumn('apply_on_import', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => 1,
                ]);
            }

            // Add updated_at timestamp
            if (!$table->hasColumn('updated_at')) {
                $table->addColumn('updated_at', Types::DATETIME, [
                    'notnull' => false,
                ]);
            }
        }

        return $schema;
    }
}
