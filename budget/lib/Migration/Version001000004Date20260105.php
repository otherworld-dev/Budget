<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000004Date20260105 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create audit log table for security-sensitive operations
        if (!$schema->hasTable('budget_audit_log')) {
            $table = $schema->createTable('budget_audit_log');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);

            $table->addColumn('action', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);

            $table->addColumn('entity_type', Types::STRING, [
                'notnull' => false,
                'length' => 64,
            ]);

            $table->addColumn('entity_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);

            $table->addColumn('ip_address', Types::STRING, [
                'notnull' => false,
                'length' => 45,
            ]);

            $table->addColumn('user_agent', Types::STRING, [
                'notnull' => false,
                'length' => 512,
            ]);

            $table->addColumn('details', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'bgt_auditlog_uid');
            $table->addIndex(['action'], 'bgt_auditlog_act');
            $table->addIndex(['entity_type', 'entity_id'], 'bgt_auditlog_ent');
            $table->addIndex(['created_at'], 'bgt_auditlog_crt');
            $table->addIndex(['user_id', 'action', 'created_at'], 'bgt_auditlog_ua');
        }

        return $schema;
    }
}
