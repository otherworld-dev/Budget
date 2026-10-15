<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Scheduled pension contributions keep their own day and can undo a post.
 *
 * - anchor_date is the date the schedule started from. Its day and month
 *   are the schedule's, so a contribution on the 31st comes back to the
 *   31st after a short month. Each post used to take them from the date it
 *   had just posted, which was already moved to the 28th or 30th, so the
 *   schedule drifted for good. Older rows get theirs the first time they
 *   post.
 * - post_undo_state is what Post now changed (the dates and the
 *   contribution it recorded) as JSON, so it can be put back.
 */
class Version001000113Date20261002 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_pen_recur')) {
			return null;
		}
		$table = $schema->getTable('budget_pen_recur');
		$changed = false;
		if (!$table->hasColumn('anchor_date')) {
			$table->addColumn('anchor_date', Types::DATE, [
				'notnull' => false,
			]);
			$changed = true;
		}
		if (!$table->hasColumn('post_undo_state')) {
			$table->addColumn('post_undo_state', Types::TEXT, [
				'notnull' => false,
			]);
			$changed = true;
		}
		return $changed ? $schema : null;
	}
}
