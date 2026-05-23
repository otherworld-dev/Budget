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

        // Rename old table if it exists from pre-release versions
        if ($schema->hasTable('budget_debt_scenarios') && !$schema->hasTable('budget_dscn')) {
            $schema->renameTable('budget_debt_scenarios', 'budget_dscn');
            return $schema;
        }

        if (!$schema->hasTable('budget_dscn')) {
            $table = $schema->createTable('budget_dscn');

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
            $table->addIndex(['user_id'], 'budget_dscn_user');
            $table->addIndex(['user_id', 'is_active'], 'budget_dscn_active');
        }

        return $schema;
    }
}
