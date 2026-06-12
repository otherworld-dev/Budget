<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rollover (envelope) budgets: per-category flag plus the anchor month the
 * carryover chain starts from. The carried amount itself is always derived
 * from budgets and spending at read time — never stored (#274 lesson).
 */
class Version001000079Date20260613 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_categories')) {
            return null;
        }

        $table = $schema->getTable('budget_categories');
        $changed = false;

        if (!$table->hasColumn('budget_rollover')) {
            $table->addColumn('budget_rollover', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            $changed = true;
        }

        if (!$table->hasColumn('rollover_start')) {
            // YYYY-MM anchor; carryover into months <= anchor is always 0
            $table->addColumn('rollover_start', Types::STRING, [
                'notnull' => false,
                'length' => 7,
            ]);
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
