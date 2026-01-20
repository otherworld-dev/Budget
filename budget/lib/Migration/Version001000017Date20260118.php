<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fix boolean column defaults in existing tables.
 * Recreates tables that were created with incorrect boolean defaults (false/true instead of 0/1).
 */
class Version001000017Date20260118 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Fix budget_expense_shares table if it exists with wrong column definition
        if ($schema->hasTable('budget_expense_shares')) {
            $table = $schema->getTable('budget_expense_shares');

            // Drop and recreate the is_settled column with correct default
            if ($table->hasColumn('is_settled')) {
                $table->dropColumn('is_settled');
            }

            $table->addColumn('is_settled', Types::BOOLEAN, [
                'notnull' => false,
                'default' => 0,
            ]);
        }

        // Fix budget_recurring_income table if it exists with wrong column definition
        if ($schema->hasTable('budget_recurring_income')) {
            $table = $schema->getTable('budget_recurring_income');

            if ($table->hasColumn('is_active')) {
                $table->dropColumn('is_active');
            }

            $table->addColumn('is_active', Types::BOOLEAN, [
                'notnull' => false,
                'default' => 1,
            ]);
        }

        // Fix budget_transactions table if it exists with wrong column definition
        if ($schema->hasTable('budget_transactions')) {
            $table = $schema->getTable('budget_transactions');

            if ($table->hasColumn('is_split')) {
                $table->dropColumn('is_split');
            }

            $table->addColumn('is_split', Types::BOOLEAN, [
                'notnull' => false,
                'default' => 0,
            ]);
        }

        // Fix budget_import_rules table if it exists with wrong column definition
        if ($schema->hasTable('budget_import_rules')) {
            $table = $schema->getTable('budget_import_rules');

            if ($table->hasColumn('apply_on_import')) {
                $table->dropColumn('apply_on_import');
            }

            $table->addColumn('apply_on_import', Types::BOOLEAN, [
                'notnull' => false,
                'default' => 1,
            ]);
        }

        return $schema;
    }
}
