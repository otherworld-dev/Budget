<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Migrate budget_dismissed_imports → budget_dismiss_imp.
 * The original table name was too long for MariaDB when prefixed with oc_.
 * Users on PostgreSQL/SQLite may already have the old table name.
 */
class Version001000069Date20260530 extends SimpleMigrationStep {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // If old table exists and new one doesn't, create the new one
        // (data will be copied in postSchemaChange)
        if ($schema->hasTable('budget_dismissed_imports') && !$schema->hasTable('budget_dismiss_imp')) {
            $table = $schema->createTable('budget_dismiss_imp');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'length' => 8,
            ]);
            $table->addColumn('account_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('import_id', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('dismissed_at', Types::STRING, [
                'notnull' => true,
                'length' => 19,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['account_id', 'import_id'], 'bdgt_dismiss_acct_imp');
            $table->addIndex(['account_id'], 'bdgt_dismiss_acct_idx');

            return $schema;
        }

        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Copy data from old table to new, then drop old table
        if ($schema->hasTable('budget_dismissed_imports') && $schema->hasTable('budget_dismiss_imp')) {
            $this->db->executeStatement(
                'INSERT INTO `*PREFIX*budget_dismiss_imp` (`account_id`, `import_id`, `dismissed_at`) ' .
                'SELECT `account_id`, `import_id`, `dismissed_at` FROM `*PREFIX*budget_dismissed_imports`'
            );

            $this->db->executeStatement('DROP TABLE `*PREFIX*budget_dismissed_imports`');
        }
    }
}
