<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create budget_budget_snapshots table for per-month budget overrides.
 * Each snapshot represents a "from this month forward" budget baseline.
 */
class Version001000053Date20260421 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_budget_snapshots')) {
            $table = $schema->createTable('budget_budget_snapshots');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);

            $table->addColumn('category_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            // The month from which this budget applies (YYYY-MM)
            $table->addColumn('effective_from', Types::STRING, [
                'notnull' => true,
                'length' => 7,
            ]);

            $table->addColumn('amount', Types::DECIMAL, [
                'notnull' => false,
                'precision' => 15,
                'scale' => 6,
                'default' => null,
            ]);

            $table->addColumn('period', Types::STRING, [
                'notnull' => false,
                'length' => 20,
                'default' => 'monthly',
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id', 'effective_from'], 'budget_snap_user_eff');
            $table->addIndex(['category_id', 'effective_from'], 'budget_snap_cat_eff');
            $table->addUniqueIndex(['user_id', 'category_id', 'effective_from'], 'budget_snap_unique');
        }

        return $schema;
    }
}
