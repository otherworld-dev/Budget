<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Self-heal the "exclude from forecast" column (#289). On some instances the
 * original migration (076) was recorded as applied but the column never actually
 * landed (an interrupted upgrade, or a database restored from a backup taken
 * mid-upgrade), so every transaction insert failed with
 * "Unknown column 'excluded_from_forecast'". Because 076 is already marked
 * applied and guards itself with hasColumn(), re-running migrations never re-adds
 * it — so this fresh migration re-asserts the column, idempotently, on the three
 * tables 076 targeted. It's a no-op on healthy instances.
 */
class Version001000084Date20260618 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        foreach (['budget_transactions', 'budget_bills', 'budget_recurring_income'] as $tableName) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);
            if (!$table->hasColumn('excluded_from_forecast')) {
                $table->addColumn('excluded_from_forecast', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => false,
                ]);
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
