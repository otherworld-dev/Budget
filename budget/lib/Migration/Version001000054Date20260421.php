<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fix: Rename budget_budget_snapshots to budget_bgt_snapshots.
 * The original table name caused "Primary index name too long" errors
 * because the auto-generated PK name exceeded Nextcloud's 30-char limit.
 *
 * - If old table exists (migration 053 succeeded): drop it
 * - Create new table with shorter name
 */
class Version001000054Date20260421 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Drop the old table if it exists (from users where 053 succeeded)
        if ($schema->hasTable('budget_budget_snapshots')) {
            $schema->dropTable('budget_budget_snapshots');
        }

        if (!$schema->hasTable('budget_bgt_snapshots')) {
            $table = $schema->createTable('budget_bgt_snapshots');

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
            $table->addIndex(['user_id', 'effective_from'], 'bgt_snap_user_eff');
            $table->addIndex(['category_id', 'effective_from'], 'bgt_snap_cat_eff');
            $table->addUniqueIndex(['user_id', 'category_id', 'effective_from'], 'bgt_snap_unique');
        }

        return $schema;
    }
}
