<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
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
 * uses that. Empty means the import stored no payee, NULL that it is not
 * known.
 *
 * Rows a file import already put in the account take their payee as it is
 * now, '' for none, so an edit made from here on is followed for them too.
 * An edit made before this ran can't be told from what the import stored,
 * so for those rows nothing changes. Only the import ids a file import
 * gives are filled: a content hash (hash_: CSV, spreadsheets, app exports)
 * and an id of the file's own (ofx_fitid_: the bank's id in an OFX or camt
 * file, or the one QifParser makes for a QIF row, ofx_fitid_qif_<md5>). The
 * first kind is compared with a new file's lines by every import, the
 * second by an app export's (see ImportService::matchesStoredRow()). Bank sync's
 * rows and rows typed in, which the imports never give one either, stay
 * NULL. (Oracle stores '' as NULL: there a row with no payee stays NULL and
 * is compared by its payee now, as before.)
 */
class Version001000126Date20261021 extends SimpleMigrationStep {
	/** The import ids a file import gives, as LIKE prefixes */
	public const FILE_IMPORT_ID_PREFIXES = ['hash_', 'ofx_fitid_'];

	public function __construct(
		private IDBConnection $db,
	) {
	}

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

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('budget_transactions')) {
			return;
		}
		$table = $schema->getTable('budget_transactions');
		foreach (['import_vendor', 'vendor', 'import_id'] as $column) {
			if (!$table->hasColumn($column)) {
				return;
			}
		}

		// One statement. A row it filled is no longer NULL, so running it
		// again changes nothing.
		$qb = $this->db->getQueryBuilder();
		$fromAFile = [];
		foreach (self::FILE_IMPORT_ID_PREFIXES as $prefix) {
			$fromAFile[] = $qb->expr()->like('import_id', $qb->createNamedParameter($this->db->escapeLikeParameter($prefix) . '%'));
		}
		$filled = $qb->update('budget_transactions')
			->set('import_vendor', $qb->createFunction('COALESCE(' . $qb->getColumnName('vendor') . ', ' . $qb->createNamedParameter('') . ')'))
			->where($qb->expr()->isNull('import_vendor'))
			->andWhere($qb->expr()->orX(...$fromAFile))
			->executeStatement();

		if ($filled > 0) {
			$output->info("Kept the payee of {$filled} imported transaction(s) for recognising them on a later import");
		}
	}
}
