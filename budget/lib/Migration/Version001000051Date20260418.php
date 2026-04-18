<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create budget_bank_account_mappings table for mapping external bank accounts to Budget accounts.
 */
class Version001000051Date20260418 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_bank_account_mappings')) {
            $table = $schema->createTable('budget_bank_account_mappings');

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
            $table->addIndex(['connection_id'], 'budget_bam_conn_idx');
            $table->addIndex(['budget_account_id'], 'budget_bam_acct_idx');
            $table->addUniqueIndex(['connection_id', 'external_account_id'], 'budget_bam_conn_ext_idx');
        }

        return $schema;
    }
}
