<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Adds an optional start date to recurring income. For weekly/biweekly income
 * the start date anchors the schedule: occurrences fall on start date
 * + n*interval days, so the week parity comes from the user's first payment
 * date instead of whichever week the entry happened to be created in (#363).
 */
class Version001000098Date20260828 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_recurring_income')) {
            return null;
        }

        $table = $schema->getTable('budget_recurring_income');
        if (!$table->hasColumn('start_date')) {
            $table->addColumn('start_date', Types::DATE, [
                'notnull' => false,
            ]);
        }

        return $schema;
    }
}
