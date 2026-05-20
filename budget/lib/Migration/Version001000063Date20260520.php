<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000063Date20260520 extends SimpleMigrationStep {
    /**
     * @param IOutput $output
     * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
     * @param array $options
     * @return null|ISchemaWrapper
     */
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        
        // Target the app's budget accounts table
        $table = $schema->getTable('budget_savings_goals');

        // Check if the column exists first to avoid fatal execution crashes
        if (!$table->hasColumn('color')) {
            $table->addColumn('color', Types::STRING, [
                'length' => 7,
                'notnull' => false,
                'default' => null,
            ]);
            $output->info('Added the optional hex "color" column to budget_savings_goals table.');
        }

        if (!$table->hasColumn('account_id')) {
            $table->addColumn('account_id', Types::STRING, [
                'notnull' => false,
                'default' => null,
            ]);
            $output->info('Added the optional "account_id" column to budget_savings_goals table.');
        }

        return $schema;
    }
}
