<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Durable "mark as unpaid" (#365): markPaid persists its undo payload
 * (previous field values, created transaction ids, paid date) as JSON on the
 * bill, so the payment can be reverted long after the undo toast is gone —
 * including auto-paid and import-matched bills that never showed one.
 */
class Version001000099Date20260828 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_bills')) {
            return null;
        }
        $table = $schema->getTable('budget_bills');
        if ($table->hasColumn('paid_undo_state')) {
            return null;
        }
        $table->addColumn('paid_undo_state', Types::TEXT, [
            'notnull' => false,
        ]);
        return $schema;
    }
}
