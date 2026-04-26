<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fix: Rename budget_bank_connections to budget_bc.
 * The auto-generated primary key name oc_budget_bank_connections_id_primary
 * exceeds MariaDB's index name length limit.
 */
class Version001000056Date20260426 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_bank_connections')) {
            $schema->dropTable('budget_bank_connections');
        }

        if (!$schema->hasTable('budget_bc')) {
            $table = $schema->createTable('budget_bc');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);

            $table->addColumn('provider', Types::STRING, [
                'notnull' => true,
                'length' => 32,
            ]);

            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);

            $table->addColumn('credentials', Types::TEXT, [
                'notnull' => true,
            ]);

            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 16,
                'default' => 'active',
            ]);

            $table->addColumn('last_sync_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->addColumn('last_error', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'bc_user_idx');
            $table->addIndex(['user_id', 'provider'], 'bc_user_prov_idx');
        }

        return $schema;
    }
}
