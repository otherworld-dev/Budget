<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create budget_auth table for password protection feature.
 * Allows users to require password entry when accessing the budget app.
 */
class Version001000020Date20260123 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create auth table
        if (!$schema->hasTable('budget_auth')) {
            $table = $schema->createTable('budget_auth');

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

            $table->setPrimaryKey(['user_id']);
            $table->addIndex(['session_token'], 'budget_auth_session_token');
        }

        return $schema;
    }
}
