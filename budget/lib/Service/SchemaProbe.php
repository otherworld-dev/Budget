<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Reads the live database schema for {@see SchemaVersionService}.
 *
 * Kept apart from the service for one reason: it is the only place that
 * touches Doctrine's Schema objects, which Nextcloud provides at runtime but
 * the app's own vendor directory does not, so the service stays testable with
 * a plain mock of this class.
 *
 * createSchema() introspects the whole database, every app's tables included,
 * so it is read once per instance of this class and the caller is expected to
 * ask rarely - once per app version on the clean path, and again only when a
 * save has already failed on a missing column.
 */
class SchemaProbe {
    private ?object $schema = null;

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
    ) {
    }

    /**
     * The column names a table actually has, or null when the table itself is
     * not there. Names are lowercased so a case-preserving driver compares
     * equal to the snake_case the entities derive.
     *
     * @param string $table Unprefixed name, e.g. 'budget_bills'
     * @return string[]|null
     * @throws \Throwable whatever the driver throws - the caller decides
     *         whether not knowing is worth a warning (it is not)
     */
    public function tableColumns(string $table): ?array {
        $this->schema ??= $this->db->createSchema();
        $name = $this->config->getSystemValueString('dbtableprefix', 'oc_') . $table;

        if (!$this->schema->hasTable($name)) {
            return null;
        }

        $columns = [];
        foreach ($this->schema->getTable($name)->getColumns() as $column) {
            $columns[] = strtolower($column->getName());
        }

        return $columns;
    }
}
