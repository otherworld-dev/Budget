<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rename budget_dismissed_imports → budget_dismiss_imp.
 * The original table name was too long for MariaDB when prefixed with oc_.
 * Users on PostgreSQL/SQLite may already have the old table name.
 */
class Version001000069Date20260530 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_dismissed_imports') && !$schema->hasTable('budget_dismiss_imp')) {
            $schema->renameTable('budget_dismissed_imports', 'budget_dismiss_imp');
            return $schema;
        }

        return null;
    }
}
