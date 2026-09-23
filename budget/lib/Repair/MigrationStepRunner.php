<?php

declare(strict_types=1);

namespace OCA\Budget\Repair;

use OCA\Budget\AppInfo\Application;
use OCP\Migration\IOutput;

/**
 * Re-runs one of this app's migrations, as `occ migrations:execute budget
 * <version>` does, but schema only: changeSchema() and the DDL it produces,
 * never preSchemaChange() or postSchemaChange().
 *
 * Nextcloud has no public API for this. MigrationService is internal, which
 * is why the call is kept alone here: RestoreMissingColumns stays testable
 * with a mock of this class, and Psalm only needs telling once.
 */
class MigrationStepRunner {

    public function execute(string $version, IOutput $output): void {
        /** @psalm-suppress UndefinedClass */
        $connection = \OCP\Server::get(\OC\DB\Connection::class);
        /** @psalm-suppress UndefinedClass */
        $service = new \OC\DB\MigrationService(Application::APP_ID, $connection, $output);
        $service->executeStep($version, true);
    }
}
