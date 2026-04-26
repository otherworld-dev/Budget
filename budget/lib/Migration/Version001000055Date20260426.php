<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fix: Rename budget_bank_account_mappings to budget_bam.
 * The original table name caused "table name too long" errors because
 * oc_budget_bank_account_mappings exceeds the 30-character limit.
 *
 * - If old table exists (migration 051 succeeded): drop it
 * - Create new table with shorter name
 */
class Version001000055Date20260426 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Drop the old table if it exists (from users where 051 succeeded)
        if ($schema->hasTable('budget_bank_account_mappings')) {
            $schema->dropTable('budget_bank_account_mappings');
        }

        if (!$schema->hasTable('budget_bam')) {
            $table = $schema->createTable('budget_bam');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('connection_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('external_account_id', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);

            $table->addColumn('external_account_name', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);

            $table->addColumn('budget_account_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);

            $table->addColumn('enabled', Types::BOOLEAN, [
                'notnull' => false,
                'default' => null,
            ]);

            $table->addColumn('requisition_id', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);

            $table->addColumn('consent_expires', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->addColumn('last_balance', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);

            $table->addColumn('last_currency', Types::STRING, [
                'notnull' => false,
                'length' => 8,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['connection_id'], 'bam_conn_idx');
            $table->addIndex(['budget_account_id'], 'bam_acct_idx');
            $table->addUniqueIndex(['connection_id', 'external_account_id'], 'bam_conn_ext_idx');
        }

        return $schema;
    }
}
