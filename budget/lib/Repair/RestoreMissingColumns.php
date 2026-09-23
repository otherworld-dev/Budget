<?php

declare(strict_types=1);

namespace OCA\Budget\Repair;

use OCA\Budget\Service\SchemaVersionService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Puts back the columns (or tables) the app writes that the database has
 * lost, by re-running the migrations that add them.
 *
 * HOW A RECORDED MIGRATION LOSES ITS COLUMN. Nextcloud's migrator snapshots
 * the whole database schema when a step starts, applies the step's change to
 * that snapshot, then diffs a second snapshot against it and executes the
 * difference — dropping anything the first snapshot did not have. Two
 * updates running at once therefore remove each other's freshly added
 * columns, and the losing step is already recorded as done. The Apps page's
 * "Update all" fires every app's update in parallel (its pLimit(1) is never
 * handed the promise), which is how a single click gets there. Reproduced on
 * SQLite with two steps and a sleep; it is the state behind #398, #333, #302
 * and #289: one column gone, its migration recorded, everything else intact.
 *
 * WHEN THIS RUNS. As a post-migration repair step: after the app's own
 * migrations on every update, on app:enable (the Apps page's Enable button
 * goes the same way), and on `occ maintenance:repair`. That is inside
 * Nextcloud's upgrade flow, which is where DDL belongs; the page-load check
 * in SchemaVersionService deliberately only reports.
 *
 * SCHEMA ONLY. A migration is re-run with executeStep's schema-only flag, so
 * its data steps do not run again: they were written to run once, and
 * Version001000061 for one negates every positive liability balance, which
 * would flip a legitimately overpaid card a second time. The column comes
 * back empty, as it is after any drop, and every reader copes with that.
 *
 * WHAT IT CANNOT DO. A column no migration on the server names cannot be
 * restored from here: the app's files are incomplete, and the step says so.
 */
class RestoreMissingColumns implements IRepairStep {

    public function __construct(
        private SchemaVersionService $schema,
        private MigrationStepRunner $runner,
    ) {
    }

    public function getName(): string {
        return 'Restore Budget database columns lost to a concurrent or interrupted update';
    }

    public function run(IOutput $output): void {
        $missing = $this->schema->getMissingColumns();
        if ($missing === []) {
            // Runs on every update: a clean schema is not worth a line.
            return;
        }

        // version => the columns it is re-run for
        $versions = [];
        foreach ($missing as $table => $columns) {
            foreach ($columns as $column) {
                $whole = $column === SchemaVersionService::WHOLE_TABLE;
                $name = $whole ? $table : $table . '.' . $column;
                $named = $this->schema->migrationsNaming($table, $whole ? null : $column);
                if ($named === []) {
                    $output->warning("Budget: no migration on this server adds $name, so it cannot be restored. The app's files are incomplete: reinstall the app.");
                    continue;
                }
                foreach ($named as $version) {
                    $versions[$version][] = $name;
                }
            }
        }
        ksort($versions, SORT_STRING);

        foreach ($versions as $version => $names) {
            $version = (string)$version;
            $output->info("Budget: re-running migration $version to restore " . implode(', ', array_unique($names)));
            try {
                $this->runner->execute($version, $output);
            } catch (\Throwable $e) {
                $output->warning("Budget: migration $version failed: " . $e->getMessage());
            }
        }

        // Look again, from the live schema. This also drops the "verified"
        // marker, which was recorded before whatever removed the column.
        $this->schema->refresh();
        $still = $this->schema->getMissingColumns();
        if ($still === []) {
            $output->info('Budget: every column the app writes is present again.');
            return;
        }
        $output->warning('Budget: still missing after the repair: ' . implode(', ', self::names($still)));
    }

    /**
     * @param array<string, string[]> $missing
     * @return string[]
     */
    private static function names(array $missing): array {
        $names = [];
        foreach ($missing as $table => $columns) {
            foreach ($columns as $column) {
                $names[] = $column === SchemaVersionService::WHOLE_TABLE ? $table : $table . '.' . $column;
            }
        }

        return $names;
    }
}
