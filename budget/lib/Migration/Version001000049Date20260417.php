<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create budget_interest_rates table for variable rate history.
 */
class Version001000049Date20260417 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_interest_rates')) {
            $table = $schema->createTable('budget_interest_rates');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('account_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);

            $table->addColumn('rate', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 7,
                'scale' => 4,
            ]);

            $table->addColumn('compounding_frequency', Types::STRING, [
                'notnull' => true,
                'length' => 16,
            ]);

            $table->addColumn('effective_date', Types::DATE, [
                'notnull' => true,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['account_id', 'effective_date'], 'budget_ir_account_date');
            $table->addIndex(['user_id'], 'budget_ir_user_idx');
        }

        return $schema;
    }
}
