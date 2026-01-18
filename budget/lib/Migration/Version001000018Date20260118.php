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
 * Cleanup migration: Drops and recreates tables with broken boolean columns.
 * This runs AFTER the problematic migrations to fix installations that failed.
 */
class Version001000018Date20260118 extends SimpleMigrationStep {

    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $connection = \OC::$server->getDatabaseConnection();
        $prefix = \OC::$server->getConfig()->getSystemValue('dbtableprefix', 'oc_');

        // Drop tables that may have been created with broken boolean defaults
        $tablesToDrop = [
            'budget_expense_shares',
            'budget_contacts',
            'budget_settlements',
            'budget_recurring_income',
        ];

        foreach ($tablesToDrop as $table) {
            try {
                $connection->executeStatement("DROP TABLE IF EXISTS {$prefix}{$table}");
            } catch (\Exception $e) {
                // Table might not exist, continue
            }
        }

        // Drop broken columns from existing tables
        $columnsToDrop = [
            'budget_transactions' => 'is_split',
            'budget_import_rules' => 'apply_on_import',
        ];

        foreach ($columnsToDrop as $table => $column) {
            try {
                $schema = $schemaClosure();
                if ($schema->hasTable($table)) {
                    $tableObj = $schema->getTable($table);
                    if ($tableObj->hasColumn($column)) {
                        $connection->executeStatement("ALTER TABLE {$prefix}{$table} DROP COLUMN {$column}");
                    }
                }
            } catch (\Exception $e) {
                // Column might not exist, continue
            }
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Recreate budget_contacts table
        if (!$schema->hasTable('budget_contacts')) {
            $table = $schema->createTable('budget_contacts');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('email', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'bgt_contact_uid');
        }

        // Recreate budget_expense_shares table with correct boolean defaults
        if (!$schema->hasTable('budget_expense_shares')) {
            $table = $schema->createTable('budget_expense_shares');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('transaction_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('contact_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('amount', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('is_settled', Types::BOOLEAN, [
                'notnull' => false,
                'default' => 0,
            ]);
            $table->addColumn('notes', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'bgt_expshare_uid');
            $table->addIndex(['transaction_id'], 'bgt_expshare_txn');
            $table->addIndex(['contact_id'], 'bgt_expshare_contact');
            $table->addIndex(['is_settled'], 'bgt_expshare_settled');
        }

        // Recreate budget_settlements table
        if (!$schema->hasTable('budget_settlements')) {
            $table = $schema->createTable('budget_settlements');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('contact_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('amount', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('date', Types::DATE, [
                'notnull' => true,
            ]);
            $table->addColumn('notes', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'bgt_settle_uid');
            $table->addIndex(['contact_id'], 'bgt_settle_contact');
            $table->addIndex(['date'], 'bgt_settle_date');
        }

        // Recreate budget_recurring_income table
        if (!$schema->hasTable('budget_recurring_income')) {
            $table = $schema->createTable('budget_recurring_income');
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
            $table->addColumn('expected_day', Types::SMALLINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $table->addColumn('expected_month', Types::SMALLINT, [
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
            $table->addColumn('source', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('auto_detect_pattern', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('is_active', Types::BOOLEAN, [
                'notnull' => false,
                'default' => 1,
            ]);
            $table->addColumn('last_received_date', Types::DATE, [
                'notnull' => false,
            ]);
            $table->addColumn('next_expected_date', Types::DATE, [
                'notnull' => false,
            ]);
            $table->addColumn('notes', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id'], 'bgt_recinc_pk');
            $table->addIndex(['user_id'], 'bgt_recinc_uid');
            $table->addIndex(['user_id', 'is_active'], 'bgt_recinc_active');
            $table->addIndex(['user_id', 'next_expected_date'], 'bgt_recinc_next');
            $table->addIndex(['user_id', 'category_id'], 'bgt_recinc_cat');
        }

        // Add is_split column to budget_transactions
        if ($schema->hasTable('budget_transactions')) {
            $table = $schema->getTable('budget_transactions');
            if (!$table->hasColumn('is_split')) {
                $table->addColumn('is_split', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => 0,
                ]);
            }
        }

        // Add apply_on_import column to budget_import_rules
        if ($schema->hasTable('budget_import_rules')) {
            $table = $schema->getTable('budget_import_rules');
            if (!$table->hasColumn('apply_on_import')) {
                $table->addColumn('apply_on_import', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => 1,
                ]);
            }
        }

        return $schema;
    }
}
