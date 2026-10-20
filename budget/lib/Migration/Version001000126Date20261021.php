<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * A transaction keeps the payee its import stored (#399).
 *
 * A statement imported again under another column mapping is recognised by
 * comparing each line with the rows the account already holds. A payee
 * edited by hand after the import is none of the line's cells, so the line
 * went in a second time. The import now writes the payee it stored to
 * import_vendor as well, which an edit never touches, and the comparison
 * uses that. Empty means the import stored no payee. Rows already in the
 * table stay NULL (not known) and are compared by their payee, as before.
 */
class Version001000126Date20261021 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_transactions')) {
			return null;
		}
		$table = $schema->getTable('budget_transactions');
		if ($table->hasColumn('import_vendor')) {
			return null;
		}
		// As long as the payee itself
		$table->addColumn('import_vendor', Types::STRING, [
			'notnull' => false,
			'length' => 255,
		]);
		return $schema;
	}
}
