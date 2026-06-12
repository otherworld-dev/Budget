<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Dismissed recurring-bill suggestions: remembers which detected patterns
 * the user said "no" to, so the proactive suggestion card doesn't nag.
 * pattern_hash is the detector's own grouping key, hashed — dismissal
 * identity exactly matches detection identity.
 */
class Version001000081Date20260613 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_dismissed_sugg')) {
            return null;
        }

        $table = $schema->createTable('budget_dismissed_sugg');
        $table->addColumn('id', Types::BIGINT, [
            'autoincrement' => true,
            'notnull' => true,
            'unsigned' => true,
        ]);
        $table->addColumn('user_id', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('suggestion_type', Types::STRING, [
            'notnull' => true,
            'length' => 16,
            'default' => 'bill',
        ]);
        $table->addColumn('pattern_hash', Types::STRING, [
            'notnull' => true,
            'length' => 64,
        ]);
        $table->addColumn('pattern', Types::STRING, [
            'notnull' => false,
            'length' => 255,
        ]);
        $table->addColumn('dismissed_at', Types::DATETIME, [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id'], 'bgt_dsugg_pk');
        $table->addIndex(['user_id'], 'bgt_dsugg_user_idx');
        $table->addUniqueIndex(['user_id', 'suggestion_type', 'pattern_hash'], 'bgt_dsugg_unq');

        return $schema;
    }
}
