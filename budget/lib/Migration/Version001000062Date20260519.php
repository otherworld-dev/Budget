<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000062Date20260519 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('budget_recurring_income');

        if (!$table->hasColumn('auto_create_enabled')) {
            $table->addColumn('auto_create_enabled', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
        }

        return $schema;
    }
}
