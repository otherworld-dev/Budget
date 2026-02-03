<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Enhance budget_import_rules table for advanced rule engine.
 * Adds support for:
 * - Complex boolean criteria trees (AND/OR/NOT operators with nesting)
 * - Schema version tracking for migration management
 * - Per-rule processing control (stop_processing flag)
 *
 * Maintains backward compatibility with v1 rules via schema_version field.
 */
class Version001000024Date20260204 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_import_rules')) {
			$table = $schema->getTable('budget_import_rules');

			// Add criteria column for complex boolean expression trees
			// Stores JSON structure: {"version": 2, "root": {"operator": "AND", "conditions": [...]}}
			if (!$table->hasColumn('criteria')) {
				$table->addColumn('criteria', Types::TEXT, [
					'notnull' => false,
					'comment' => 'JSON boolean tree for complex matching criteria',
				]);
			}

			// Add schema_version for migration tracking
			// Version 1 = legacy (field/pattern/matchType), Version 2 = complex criteria
			if (!$table->hasColumn('schema_version')) {
				$table->addColumn('schema_version', Types::INTEGER, [
					'notnull' => true,
					'default' => 1,
					'comment' => 'Schema version: 1=legacy single criteria, 2=complex boolean tree',
				]);
			}

			// Add stop_processing flag for rule chain control
			// If true, stop evaluating subsequent rules after this one matches
			if (!$table->hasColumn('stop_processing')) {
				$table->addColumn('stop_processing', Types::BOOLEAN, [
					'notnull' => true,
					'default' => true,
					'comment' => 'If true, stop evaluating rules after this one matches',
				]);
			}
		}

		return $schema;
	}
}
