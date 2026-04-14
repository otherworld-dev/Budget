<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create budget_shares table for multi-user budget sharing.
 *
 * Supports whole-budget sharing with pending/accepted states and read-only access.
 */
class Version001000046Date20260414 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_shares')) {
            $table = $schema->createTable('budget_shares');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 8,
            ]);
            $table->addColumn('owner_user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('shared_with_user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            // 'pending', 'accepted', 'declined'
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 16,
                'default' => 'pending',
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            // Each owner can share with a given user only once
            $table->addUniqueIndex(['owner_user_id', 'shared_with_user_id'], 'budget_share_unique');
            $table->addIndex(['owner_user_id'], 'budget_share_owner_idx');
            $table->addIndex(['shared_with_user_id'], 'budget_share_recipient_idx');
            $table->addIndex(['status'], 'budget_share_status_idx');
        }

        return $schema;
    }
}
