<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use Doctrine\DBAL\Types\Types;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000063Date20260520 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('budget_bc');
        if (!$table->hasColumn('apply_rules')) {
            $table->addColumn('apply_rules', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
        }

        return $schema;
    }
}
