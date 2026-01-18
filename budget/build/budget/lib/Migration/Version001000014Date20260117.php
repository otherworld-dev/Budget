<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add minimum_payment column to accounts table for debt payoff planning.
 */
class Version001000014Date20260117 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('budget_accounts');

        // Minimum monthly payment for liability accounts
        if (!$table->hasColumn('minimum_payment')) {
            $table->addColumn('minimum_payment', Types::DECIMAL, [
                'notnull' => false,
                'precision' => 15,
                'scale' => 2,
                'default' => null,
            ]);
        }

        return $schema;
    }
}
