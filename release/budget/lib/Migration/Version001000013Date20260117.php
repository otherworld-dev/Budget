<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add reminder columns to bills table for notification support.
 */
class Version001000013Date20260117 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $table = $schema->getTable('budget_bills');

        // Days before due date to send reminder (null = no reminder)
        if (!$table->hasColumn('reminder_days')) {
            $table->addColumn('reminder_days', Types::SMALLINT, [
                'notnull' => false,
                'unsigned' => true,
                'default' => null,
            ]);
        }

        // Timestamp of last reminder sent (to avoid duplicate reminders)
        if (!$table->hasColumn('last_reminder_sent')) {
            $table->addColumn('last_reminder_sent', Types::DATETIME, [
                'notnull' => false,
            ]);
        }

        return $schema;
    }
}
