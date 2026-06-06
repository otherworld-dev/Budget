<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Generalise import templates beyond CSV: add a format discriminator,
 * an account-routing map for OFX/QIF (source account key -> budget account id),
 * and the cross-format import options.
 */
class Version001000072Date20260603 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_import_templates')) {
            return null;
        }

        $table = $schema->getTable('budget_import_templates');
        $changed = false;

        // File format this template applies to: csv | ofx | qif.
        if (!$table->hasColumn('format')) {
            $table->addColumn('format', Types::STRING, [
                'notnull' => true,
                'length' => 8,
                'default' => 'csv',
            ]);
            $changed = true;
        }

        // JSON map of source-account key -> budget account id, for OFX/QIF.
        if (!$table->hasColumn('account_mapping')) {
            $table->addColumn('account_mapping', Types::TEXT, [
                'notnull' => false,
            ]);
            $changed = true;
        }

        // Cross-format import options. Nullable booleans for cross-DB compatibility;
        // null is treated as the documented default (skip_duplicates=true, apply_rules=false).
        if (!$table->hasColumn('skip_duplicates')) {
            $table->addColumn('skip_duplicates', Types::BOOLEAN, [
                'notnull' => false,
                'default' => true,
            ]);
            $changed = true;
        }
        if (!$table->hasColumn('apply_rules')) {
            $table->addColumn('apply_rules', Types::BOOLEAN, [
                'notnull' => false,
                'default' => false,
            ]);
            $changed = true;
        }

        // Index template lookups by format (used to filter the dropdown per upload).
        if (!$table->hasIndex('bdgt_imptpl_fmt_idx')) {
            $table->addIndex(['user_id', 'format'], 'bdgt_imptpl_fmt_idx');
            $changed = true;
        }

        return $changed ? $schema : null;
    }
}
