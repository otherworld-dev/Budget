<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add manual exchange rates table for per-user rate overrides.
 */
class Version001000038Date20260303 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_manual_rates')) {
            $table = $schema->createTable('budget_manual_rates');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);

            // Currency code (e.g. ARS, CLP)
            $table->addColumn('currency', Types::STRING, [
                'notnull' => true,
                'length' => 10,
            ]);

            // Units of this currency per 1 EUR (standing rate, no date)
            $table->addColumn('rate_per_eur', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 20,
                'scale' => 10,
            ]);

            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'currency'], 'budget_mrate_usr_curr');
            $table->addIndex(['user_id'], 'budget_mrate_user_idx');
        }

        return $schema;
    }
}
