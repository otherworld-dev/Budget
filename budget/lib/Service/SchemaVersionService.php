<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Detects migrations that ship with this code but were never applied.
 *
 * Nextcloud runs an app's migrations as part of upgrading it, and records each
 * one in the `migrations` table. If the files are replaced without that
 * upgrade completing, migrations are left unapplied and the columns they add
 * never exist. Nothing complains, because Nextcloud's mapper selects `*` and a
 * missing column reads as null — the first sign is a save failing with the
 * database's own words, "Unknown column 'amount_type'" (#333).
 *
 * WHY NOT COMPARE VERSIONS. The obvious check is appconfig's
 * `installed_version` against the version in info.xml. That was tried and is
 * useless here: when those differ Nextcloud sets `needsDbUpgrade` and serves
 * its own upgrade screen instead of the app, so a warning rendered by this app
 * could never be reached. The instance in #333 had the app fully usable with
 * columns missing, which means its recorded version already matched. Only the
 * migration table distinguishes "upgraded" from "actually migrated".
 *
 * WHY IT ONLY REPORTS. Running the pending migrations from a web request would
 * be DDL outside Nextcloud's upgrade flow, with no maintenance mode and
 * concurrent requests free to hit a half-changed schema. The repair belongs in
 * occ, where Nextcloud already does it safely.
 *
 * COST. The common path is a single appconfig read: once a version has been
 * found complete it is remembered, and the scan is skipped until the app
 * version changes. A failing check is deliberately NOT cached, so the warning
 * clears itself the moment the migrations are applied.
 */
class SchemaVersionService {

    private const CHECKED_KEY = 'schema_verified_for';

    /** Computed at most once per request: isBehind() and getWarning() share it. */
    private ?array $pending = null;

    /**
     * The directory scan, memoized: null = not scanned yet, false = the scan
     * failed. One scan per request, shared by every consumer — pending and
     * the verified marker MUST come from the same scan. When they did not, a
     * scandir failure (pending computed as "none" from the failed scan)
     * "repaired" by a later successful scan recorded the version as verified
     * while migrations were genuinely pending, permanently silencing the
     * warning on exactly the broken instance it exists for.
     */
    private array|false|null $shipped = null;

    public function __construct(
        private IConfig $config,
        private IDBConnection $db,
        private IL10N $l,
        private string $appVersion,
        private string $migrationDir,
    ) {
    }

    /**
     * Migration versions this code ships, e.g. '001000097Date20260825'.
     *
     * @return string[]
     */
    public function getShippedMigrations(): array {
        if ($this->shipped === null) {
            $this->shipped = $this->scanShippedMigrations();
        }

        return $this->shipped === false ? [] : $this->shipped;
    }

    /** @return string[]|false false when the directory could not be read */
    private function scanShippedMigrations(): array|false {
        $files = @scandir($this->migrationDir);
        if ($files === false) {
            return false;
        }

        $versions = [];
        foreach ($files as $file) {
            // The same shape Nextcloud's own MigrationService discovers —
            // anything stricter would silently drop a legitimately-pending
            // migration from the comparison and lock in a false "verified".
            if (preg_match('/^Version(.+)\.php$/i', $file, $m) === 1) {
                $versions[] = $m[1];
            }
        }
        sort($versions);

        return $versions;
    }

    /**
     * Migration versions Nextcloud has recorded as applied for this app.
     *
     * @return string[]
     */
    public function getAppliedMigrations(): array {
        $qb = $this->db->getQueryBuilder();
        $qb->select('version')
            ->from('migrations')
            ->where($qb->expr()->eq('app', $qb->createNamedParameter(Application::APP_ID)));

        $result = $qb->executeQuery();
        $versions = [];
        while ($row = $result->fetch()) {
            $versions[] = (string)$row['version'];
        }
        $result->closeCursor();

        return $versions;
    }

    /**
     * Shipped migrations with no record of having run.
     *
     * @return string[]
     */
    public function getPendingMigrations(): array {
        if ($this->pending !== null) {
            return $this->pending;
        }

        $shipped = $this->getShippedMigrations();
        if ($shipped === []) {
            // Cannot read the directory (or it ships nothing): report nothing
            // rather than warn on a guess, since this renders on every page
            // load. Deliberately not cached as $pending — this is not a
            // computed answer, and isBehind() must see the same failed-scan
            // state (via the memoized $shipped) rather than a cached [].
            return [];
        }

        $pending = array_values(array_diff($shipped, $this->getAppliedMigrations()));
        sort($pending);

        return $this->pending = $pending;
    }

    /** True when this code ships migrations the database has never had applied. */
    public function isBehind(): bool {
        if ($this->config->getAppValue(Application::APP_ID, self::CHECKED_KEY, '') === $this->appVersion) {
            return false;
        }

        if ($this->getPendingMigrations() !== []) {
            return true;
        }

        // "Nothing pending" only counts as verified when the migration
        // directory was actually readable. An unreadable directory also
        // reports nothing pending, and recording THAT as verified would
        // suppress the warning for the rest of this version's life on
        // exactly the broken instances this exists for — one scandir
        // hiccup during a file-swap deploy would be enough. The memoized
        // scan makes this the SAME scan pending was computed from, never
        // a lucky retry.
        if ($this->getShippedMigrations() !== []) {
            $this->config->setAppValue(Application::APP_ID, self::CHECKED_KEY, $this->appVersion);
        }

        return false;
    }

    /**
     * The warning to show, or null when there is nothing wrong.
     *
     * @return array{message: string, command: string}|null
     */
    public function getWarning(): ?array {
        if (!$this->isBehind()) {
            return null;
        }

        $pending = $this->getPendingMigrations();

        return [
            'message' => $this->l->n(
                'A database update that came with this version of Budget was never applied (%n change is missing). Saving may fail until it is finished.',
                'Database updates that came with this version of Budget were never applied (%n changes are missing). Saving may fail until they are finished.',
                count($pending)
            ),
            'command' => 'occ app:disable budget && occ app:enable budget',
        ];
    }
}
