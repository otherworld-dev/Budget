<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000065Date20260521 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_debt_scenarios')) {
            $table = $schema->createTable('budget_debt_scenarios');

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

            $table->addColumn('strategy', Types::STRING, [
                'notnull' => true,
                'length' => 20,
                'default' => 'avalanche',
            ]);

            $table->addColumn('extra_payment', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
            ]);

            $table->addColumn('lump_sum', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
            ]);

            $table->addColumn('lump_sum_month', Types::INTEGER, [
                'notnull' => true,
                'default' => 1,
            ]);

            $table->addColumn('selected_debt_ids', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->addColumn('rate_overrides', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->addColumn('is_active', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);

            $table->addColumn('original_total_debt', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
                'default' => 0,
            ]);

            $table->addColumn('created_at', Types::STRING, [
                'notnull' => true,
                'length' => 19,
            ]);

            $table->addColumn('updated_at', Types::STRING, [
                'notnull' => true,
                'length' => 19,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'budget_dscen_user');
            $table->addIndex(['user_id', 'is_active'], 'budget_dscen_active');
        }

        return $schema;
    }
}
