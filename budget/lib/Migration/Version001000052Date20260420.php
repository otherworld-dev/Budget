<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add nextcloud_user_id column to budget_contacts for linking to Nextcloud users.
 */
class Version001000052Date20260420 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_contacts')) {
            $table = $schema->getTable('budget_contacts');

            if (!$table->hasColumn('nextcloud_user_id')) {
                $table->addColumn('nextcloud_user_id', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                    'default' => null,
                ]);
            }
        }

        return $schema;
    }
}
