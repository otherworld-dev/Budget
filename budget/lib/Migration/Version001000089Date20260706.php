<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Persist the "Create future transaction for this bill" checkbox (#311).
 * Previously it was a one-shot option at bill creation while markPaid/skip
 * pre-created the next occurrence unconditionally — so unticking it never
 * actually opted a bill out of placeholders. Default true (and null is read
 * as true) so existing bills keep the pre-booking behaviour they have today;
 * opting out is an explicit per-bill choice.
 */
class Version001000089Date20260706 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_bills')) {
            return null;
        }

        $table = $schema->getTable('budget_bills');
        if ($table->hasColumn('create_transaction')) {
            return null;
        }

        $table->addColumn('create_transaction', Types::BOOLEAN, [
            'notnull' => false,
            'default' => true,
        ]);

        return $schema;
    }
}
