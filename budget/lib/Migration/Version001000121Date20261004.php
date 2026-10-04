<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A bill remembers which due date its last reminder or overdue notice was
 * for.
 *
 * The reminder job sent one of each per due date by comparing when the
 * last one went out with the reminder window. An overdue notice sent just
 * before a late payment fell inside the next occurrence's window, so that
 * occurrence's reminder never went out. Older rows start empty and are
 * judged by when their last notice went out, as before.
 */
class Version001000121Date20261004 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_bills')) {
			return null;
		}
		$table = $schema->getTable('budget_bills');
		if ($table->hasColumn('last_reminder_due')) {
			return null;
		}
		$table->addColumn('last_reminder_due', Types::DATE, [
			'notnull' => false,
		]);
		return $schema;
	}
}
