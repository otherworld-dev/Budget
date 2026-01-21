<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000003Date20260101 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create bills table for recurring bill tracking
        if (!$schema->hasTable('budget_bills')) {
            $table = $schema->createTable('budget_bills');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);

            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);

            $table->addColumn('amount', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
            ]);

            $table->addColumn('frequency', Types::STRING, [
                'notnull' => true,
                'length' => 20,
                'default' => 'monthly',
            ]);

            $table->addColumn('due_day', Types::SMALLINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);

            $table->addColumn('due_month', Types::SMALLINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);

            $table->addColumn('category_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);

            $table->addColumn('account_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);

            $table->addColumn('auto_detect_pattern', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);

            $table->addColumn('is_active', Types::BOOLEAN, [
                'notnull' => false,
            ]);

            $table->addColumn('last_paid_date', Types::DATE, [
                'notnull' => false,
            ]);

            $table->addColumn('next_due_date', Types::DATE, [
                'notnull' => false,
            ]);

            $table->addColumn('notes', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'budget_bills_user_id');
            $table->addIndex(['user_id', 'is_active'], 'budget_bills_active');
            $table->addIndex(['user_id', 'next_due_date'], 'budget_bills_due');
            $table->addIndex(['user_id', 'category_id'], 'budget_bills_category');
        }

        // Add budget_period column to categories for configurable budget periods
        if ($schema->hasTable('budget_categories')) {
            $table = $schema->getTable('budget_categories');

            if (!$table->hasColumn('budget_period')) {
                $table->addColumn('budget_period', Types::STRING, [
                    'notnull' => false,
                    'length' => 20,
                    'default' => 'monthly',
                ]);
            }
        }

        return $schema;
    }
}
