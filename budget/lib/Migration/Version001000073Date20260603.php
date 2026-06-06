<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Auto-remember layer for OFX/QIF imports: stores which destination account
 * each source account (by key) was routed to, so the next import of the same
 * format can pre-fill the routing.
 *
 * Uniqueness of (user_id, format, source_key) is enforced in the service via
 * find-then-upsert rather than a unique index, to avoid the utf8mb4 index-prefix
 * length limits that long source keys would otherwise hit on older MariaDB.
 */
class Version001000073Date20260603 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_imp_links')) {
            return null;
        }

        $table = $schema->createTable('budget_imp_links');
        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        // Import format the link applies to: ofx | qif.
        $table->addColumn('format', Types::STRING, [
            'notnull' => true,
            'length' => 8,
        ]);
        // Source account key from the file: OFX account id/number, or QIF account name.
        $table->addColumn('source_key', Types::STRING, [
            'notnull' => true,
            'length' => 255,
        ]);
        $table->addColumn('budget_account_id', Types::BIGINT, [
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('updated_at', Types::DATETIME, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        // Recall queries filter by (user_id, format); the leftmost prefix also
        // serves per-source lookups.
        $table->addIndex(['user_id', 'format'], 'bdgt_implink_usr_idx');

        return $schema;
    }
}
