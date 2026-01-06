<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add composite indexes for common query patterns to improve performance.
 */
class Version001000005Date20260106 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add composite indexes to transactions table
        if ($schema->hasTable('budget_transactions')) {
            $table = $schema->getTable('budget_transactions');

            // Composite index for date range queries by account (heavily used by findByDateRange)
            if (!$table->hasIndex('budget_tx_account_date')) {
                $table->addIndex(['account_id', 'date'], 'budget_tx_account_date');
            }

            // Composite index for type-filtered queries (spending/income by date range)
            if (!$table->hasIndex('budget_tx_account_type_date')) {
                $table->addIndex(['account_id', 'type', 'date'], 'budget_tx_account_type_date');
            }

            // Composite index for category spending queries
            if (!$table->hasIndex('budget_tx_category_type_date')) {
                $table->addIndex(['category_id', 'type', 'date'], 'budget_tx_category_type_date');
            }
        }

        // Add composite index to categories table
        if ($schema->hasTable('budget_categories')) {
            $table = $schema->getTable('budget_categories');

            // Composite index for type-filtered queries
            if (!$table->hasIndex('budget_cat_user_type')) {
                $table->addIndex(['user_id', 'type'], 'budget_cat_user_type');
            }
        }

        return $schema;
    }
}
