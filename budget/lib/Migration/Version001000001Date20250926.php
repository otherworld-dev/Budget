<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000001Date20250926 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add additional banking fields to accounts table
        if ($schema->hasTable('budget_accounts')) {
            $table = $schema->getTable('budget_accounts');

            // Add routing number (US banks)
            if (!$table->hasColumn('routing_number')) {
                $table->addColumn('routing_number', Types::STRING, [
                    'notnull' => false,
                    'length' => 20,
                ]);
            }

            // Add sort code (UK banks)
            if (!$table->hasColumn('sort_code')) {
                $table->addColumn('sort_code', Types::STRING, [
                    'notnull' => false,
                    'length' => 10,
                ]);
            }

            // Add IBAN (international)
            if (!$table->hasColumn('iban')) {
                $table->addColumn('iban', Types::STRING, [
                    'notnull' => false,
                    'length' => 34,
                ]);
            }

            // Add SWIFT/BIC code
            if (!$table->hasColumn('swift_bic')) {
                $table->addColumn('swift_bic', Types::STRING, [
                    'notnull' => false,
                    'length' => 11,
                ]);
            }

            // Add account holder name
            if (!$table->hasColumn('account_holder_name')) {
                $table->addColumn('account_holder_name', Types::STRING, [
                    'notnull' => false,
                    'length' => 255,
                ]);
            }

            // Add opening date
            if (!$table->hasColumn('opening_date')) {
                $table->addColumn('opening_date', Types::DATE, [
                    'notnull' => false,
                ]);
            }

            // Add interest rate
            if (!$table->hasColumn('interest_rate')) {
                $table->addColumn('interest_rate', Types::DECIMAL, [
                    'notnull' => false,
                    'precision' => 5,
                    'scale' => 4,
                ]);
            }

            // Add credit limit (for credit cards)
            if (!$table->hasColumn('credit_limit')) {
                $table->addColumn('credit_limit', Types::DECIMAL, [
                    'notnull' => false,
                    'precision' => 15,
                    'scale' => 2,
                ]);
            }

            // Add overdraft limit
            if (!$table->hasColumn('overdraft_limit')) {
                $table->addColumn('overdraft_limit', Types::DECIMAL, [
                    'notnull' => false,
                    'precision' => 15,
                    'scale' => 2,
                ]);
            }
        }

        return $schema;
    }
}