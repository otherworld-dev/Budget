<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Tag "hidden" flag (#373). A hidden tag keeps everything that already carries
 * it and stays in the transaction filter and reports, but is no longer offered
 * when tagging a new transaction, bill, transfer or rule action, or when
 * linking a savings goal. Global and tag-set tags share budget_tags, so one
 * column serves both. NULL reads as not hidden: the column post-dates the table.
 */
class Version001000102Date20260902 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_tags')) {
            return null;
        }

        $table = $schema->getTable('budget_tags');

        if (!$table->hasColumn('hidden')) {
            $table->addColumn('hidden', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            return $schema;
        }

        return null;
    }
}
