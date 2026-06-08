<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create the budget_import_templates table for user-saved CSV import templates.
 */
class Version001000071Date20260602 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_import_templates')) {
            $table = $schema->createTable('budget_import_templates');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            // JSON-encoded column mapping (field => CSV header name).
            $table->addColumn('mapping', Types::TEXT, [
                'notnull' => true,
            ]);
            $table->addColumn('delimiter', Types::STRING, [
                'notnull' => true,
                'length' => 8,
                'default' => ',',
            ]);
            $table->addColumn('skip_first_row', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            // Optional default destination account for this template.
            $table->addColumn('account_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            // Use an explicit primary key name. Without one, Nextcloud derives a
            // default name from the table and rejects it on installs where the
            // table name (minus prefix) is >= 23 chars, which is the case here:
            // "budget_import_templates" is exactly 23. The check in
            // MigrationService::ensureOracleConstraints() then throws
            // "Primary index name on oc_budget_import_templates is too long."
            // This limit applies on Nextcloud <= 32 (raised to 63 only on master).
            $table->setPrimaryKey(['id'], 'bdgt_imptpl_pk');
            $table->addIndex(['user_id'], 'bdgt_imptpl_user_idx');
            $table->addUniqueIndex(['user_id', 'name'], 'bdgt_imptpl_name_unq');
        }

        return $schema;
    }
}
