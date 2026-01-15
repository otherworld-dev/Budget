<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000000Date20250831 extends SimpleMigrationStep {
    
    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create accounts table
        if (!$schema->hasTable('budget_accounts')) {
            $table = $schema->createTable('budget_accounts');
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
            $table->addColumn('type', Types::STRING, [
                'notnull' => true,
                'length' => 50,
                'default' => 'checking',
            ]);
            $table->addColumn('balance', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
            ]);
            $table->addColumn('currency', Types::STRING, [
                'notnull' => true,
                'length' => 3,
                'default' => 'USD',
            ]);
            $table->addColumn('institution', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('account_number', Types::STRING, [
                'notnull' => false,
                'length' => 100,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'budget_accounts_user');
        }

        // Create categories table
        if (!$schema->hasTable('budget_categories')) {
            $table = $schema->createTable('budget_categories');
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
            $table->addColumn('type', Types::STRING, [
                'notnull' => true,
                'length' => 20,
                'default' => 'expense',
            ]);
            $table->addColumn('parent_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $table->addColumn('icon', Types::STRING, [
                'notnull' => false,
                'length' => 50,
            ]);
            $table->addColumn('color', Types::STRING, [
                'notnull' => false,
                'length' => 7,
            ]);
            $table->addColumn('budget_amount', Types::DECIMAL, [
                'notnull' => false,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'budget_categories_user');
            $table->addIndex(['parent_id'], 'budget_categories_parent');
        }

        // Create transactions table
        if (!$schema->hasTable('budget_transactions')) {
            $table = $schema->createTable('budget_transactions');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('account_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('category_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $table->addColumn('date', Types::DATE, [
                'notnull' => true,
            ]);
            $table->addColumn('description', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('vendor', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('amount', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('type', Types::STRING, [
                'notnull' => true,
                'length' => 20,
                'default' => 'debit',
            ]);
            $table->addColumn('reference', Types::STRING, [
                'notnull' => false,
                'length' => 100,
            ]);
            $table->addColumn('notes', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('import_id', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('reconciled', Types::BOOLEAN, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            
            $table->setPrimaryKey(['id']);
            $table->addIndex(['account_id'], 'budget_tx_account');
            $table->addIndex(['category_id'], 'budget_tx_category');
            $table->addIndex(['date'], 'budget_tx_date');
            $table->addIndex(['import_id'], 'budget_tx_import');
            $table->addUniqueIndex(['account_id', 'import_id'], 'budget_tx_unique_import');
        }

        // Create import_rules table
        if (!$schema->hasTable('budget_import_rules')) {
            $table = $schema->createTable('budget_import_rules');
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
            $table->addColumn('pattern', Types::STRING, [
                'notnull' => true,
                'length' => 500,
            ]);
            $table->addColumn('field', Types::STRING, [
                'notnull' => true,
                'length' => 50,
                'default' => 'description',
            ]);
            $table->addColumn('match_type', Types::STRING, [
                'notnull' => true,
                'length' => 20,
                'default' => 'contains',
            ]);
            $table->addColumn('category_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $table->addColumn('vendor_name', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('priority', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('active', Types::BOOLEAN, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'budget_rules_user');
            $table->addIndex(['category_id'], 'budget_rules_category');
            $table->addIndex(['priority'], 'budget_rules_priority');
        }

        // Create forecasts table
        if (!$schema->hasTable('budget_forecasts')) {
            $table = $schema->createTable('budget_forecasts');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('account_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('based_on_months', Types::INTEGER, [
                'notnull' => true,
                'default' => 3,
            ]);
            $table->addColumn('forecast_months', Types::INTEGER, [
                'notnull' => true,
                'default' => 6,
            ]);
            $table->addColumn('data', Types::JSON, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            
            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'budget_forecasts_user');
            $table->addIndex(['account_id'], 'budget_forecasts_account');
        }

        return $schema;
    }
}