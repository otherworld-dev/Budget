<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create savings_goals table for tracking user savings goals.
 */
class Version001000006Date20260107 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_savings_goals')) {
            $table = $schema->createTable('budget_savings_goals');

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

            $table->addColumn('target_amount', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
            ]);

            $table->addColumn('current_amount', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
            ]);

            $table->addColumn('target_months', Types::INTEGER, [
                'notnull' => false,
                'unsigned' => true,
            ]);

            $table->addColumn('description', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->addColumn('target_date', Types::DATE, [
                'notnull' => false,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'budget_goals_user_id');
        }

        return $schema;
    }
}
