<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Record who created a category when it isn't its owner.
 *
 * A recipient with Full control on a shared category can add subcategories
 * under it. Those belong to the category's owner, so they sit in the right
 * tree for both people, and created_by names the recipient who made them so
 * they can still delete one they added by mistake. NULL means the owner
 * created it, which is every existing row.
 */
class Version001000108Date20260927 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_categories')) {
			return null;
		}

		$table = $schema->getTable('budget_categories');

		if (!$table->hasColumn('created_by')) {
			$table->addColumn('created_by', Types::STRING, [
				'notnull' => false,
				'length' => 64,
			]);
			return $schema;
		}

		return null;
	}
}
