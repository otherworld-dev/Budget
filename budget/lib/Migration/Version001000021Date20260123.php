<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Fix budget_auth table to use auto-increment id as primary key.
 * This resolves the "Entity which should be updated has no id" error.
 */
class Version001000021Date20260123 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_auth')) {
            // Drop and recreate the table with the correct schema
            // This is safe during development as the feature just launched
            $schema->dropTable('budget_auth');

            $table = $schema->createTable('budget_auth');

            // Add auto-increment id as primary key
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->setPrimaryKey(['id']);

            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);

            $table->addColumn('password_hash', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);

            $table->addColumn('session_token', Types::STRING, [
                'notnull' => false,
                'length' => 128,
            ]);

            $table->addColumn('session_expires_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->addColumn('failed_attempts', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('locked_until', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ]);

            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ]);

            // Make user_id unique
            $table->addUniqueIndex(['user_id'], 'budget_auth_user_id_unique');
            $table->addIndex(['session_token'], 'budget_auth_session_token');
        }

        return $schema;
    }
}
