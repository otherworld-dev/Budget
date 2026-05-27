<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000064Date20260520 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('budget_savings_goals');
        if (!$table->hasColumn('account_id')) {
            $table->addColumn('account_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
        }
        if (!$table->hasColumn('color')) {
            $table->addColumn('color', Types::STRING, [
                'notnull' => false,
                'length' => 7,
            ]);
        }

        return $schema;
    }
}
