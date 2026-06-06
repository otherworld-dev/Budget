<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds the `include_pending` option to bank connections. When enabled (SimpleFIN
 * only), the sync requests pending transactions in addition to posted ones.
 */
class Version001000074Date20260606 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_bc')) {
            return null;
        }

        $table = $schema->getTable('budget_bc');
        if (!$table->hasColumn('include_pending')) {
            $table->addColumn('include_pending', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
        }

        return $schema;
    }
}
