<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create pension tracking tables: pensions, pension_snapshots, pension_contributions.
 */
class Version001000009Date20260116 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create pensions table
        if (!$schema->hasTable('budget_pensions')) {
            $table = $schema->createTable('budget_pensions');
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
            $table->addColumn('provider', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('type', Types::STRING, [
                'notnull' => true,
                'length' => 50,
                'default' => 'workplace',
            ]);
            $table->addColumn('currency', Types::STRING, [
                'notnull' => true,
                'length' => 3,
                'default' => 'GBP',
            ]);
            $table->addColumn('current_balance', Types::DECIMAL, [
                'notnull' => false,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('monthly_contribution', Types::DECIMAL, [
                'notnull' => false,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('expected_return_rate', Types::DECIMAL, [
                'notnull' => false,
                'precision' => 5,
                'scale' => 4,
            ]);
            $table->addColumn('retirement_age', Types::INTEGER, [
                'notnull' => false,
            ]);
            $table->addColumn('annual_income', Types::DECIMAL, [
                'notnull' => false,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('transfer_value', Types::DECIMAL, [
                'notnull' => false,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'bgt_pensions_uid');
            $table->addIndex(['type'], 'bgt_pensions_type');
        }

        // Create pension_snapshots table (shortened name for index limits)
        if (!$schema->hasTable('budget_pen_snaps')) {
            $table = $schema->createTable('budget_pen_snaps');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('pension_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('balance', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('date', Types::DATE, [
                'notnull' => true,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'bgt_penssnap_uid');
            $table->addIndex(['pension_id'], 'bgt_penssnap_pid');
            $table->addIndex(['date'], 'bgt_penssnap_date');
        }

        // Create pension_contributions table (shortened name for index limits)
        if (!$schema->hasTable('budget_pen_contribs')) {
            $table = $schema->createTable('budget_pen_contribs');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('pension_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('amount', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('date', Types::DATE, [
                'notnull' => true,
            ]);
            $table->addColumn('note', Types::STRING, [
                'notnull' => false,
                'length' => 500,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'bgt_penscontr_uid');
            $table->addIndex(['pension_id'], 'bgt_penscontr_pid');
            $table->addIndex(['date'], 'bgt_penscontr_date');
        }

        return $schema;
    }
}
