<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add interest accrual columns to budget_accounts.
 */
class Version001000048Date20260417 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_accounts')) {
            $table = $schema->getTable('budget_accounts');

            if (!$table->hasColumn('interest_enabled')) {
                $table->addColumn('interest_enabled', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => null,
                ]);
            }

            if (!$table->hasColumn('compounding_frequency')) {
                $table->addColumn('compounding_frequency', Types::STRING, [
                    'notnull' => false,
                    'length' => 16,
                    'default' => null,
                ]);
            }

            if (!$table->hasColumn('accrued_interest')) {
                $table->addColumn('accrued_interest', Types::DECIMAL, [
                    'notnull' => false,
                    'precision' => 15,
                    'scale' => 2,
                    'default' => 0,
                ]);
            }
        }

        return $schema;
    }
}
