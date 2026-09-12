<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Import template character encoding (#384). A statement whose encoding
 * cannot be detected — an undeclared Windows-1251 CSV reads as valid
 * Windows-1252 — needs the encoding picked by hand, and a template now
 * remembers that choice. NULL means detect automatically, which is what every
 * template saved before this column existed has always done.
 */
class Version001000103Date20260912 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_import_templates')) {
            return null;
        }

        $table = $schema->getTable('budget_import_templates');

        if (!$table->hasColumn('encoding')) {
            $table->addColumn('encoding', Types::STRING, [
                'notnull' => false,
                'length' => 32,
            ]);
            return $schema;
        }

        return null;
    }
}
