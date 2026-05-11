<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add description column to budget_bills and budget_recurring_income.
 * Used as the transaction description when auto-generating transactions.
 */
class Version001000060Date20260503 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $billsTable = $schema->getTable('budget_bills');
        if (!$billsTable->hasColumn('description')) {
            $billsTable->addColumn('description', Types::STRING, [
                'notnull' => false,
                'length' => 255,
                'default' => null,
            ]);
        }

        $incomeTable = $schema->getTable('budget_recurring_income');
        if (!$incomeTable->hasColumn('description')) {
            $incomeTable->addColumn('description', Types::STRING, [
                'notnull' => false,
                'length' => 255,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
