<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create recurring income table for tracking expected income (paydays, dividends, etc.).
 */
class Version001000011Date20260117 extends SimpleMigrationStep {

    /**
     * Drop broken table entirely to avoid schema reconciliation issues
     */
    public function preSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        $connection = \OC::$server->getDatabaseConnection();
        $prefix = $connection->getPrefix();

        try {
            $connection->executeStatement("DROP TABLE IF EXISTS {$prefix}budget_recurring_income");
        } catch (\Exception $e) {
            // Table might not exist, continue
        }
    }

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

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
        // Note: else removed - table was dropped in preSchemaChange if it existed

        return $schema;
    }
}
