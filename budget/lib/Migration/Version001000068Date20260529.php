<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create budget_dismissed_imports table.
 * Tracks import IDs of deleted transactions so bank sync and CSV import
 * don't re-import them.
 */
class Version001000068Date20260529 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_dismissed_imports')) {
            $table = $schema->createTable('budget_dismissed_imports');

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
            $table->addUniqueIndex(['account_id', 'import_id'], 'budget_dismiss_acct_imp');
            $table->addIndex(['account_id'], 'budget_dismiss_acct_idx');
        }

        return $schema;
    }
}
