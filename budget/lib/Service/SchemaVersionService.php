<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;

/**
 * Detects migrations that ship with this code but were never applied, and
 * columns the code writes that the database does not have.
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
 * WHY THE MIGRATION TABLE IS NOT ENOUGH EITHER. The instance in #333 ran
 * `occ app:disable && occ app:enable`, which does execute every migration
 * not yet recorded, and the column was still missing afterwards. That leaves
 * two states a files-versus-table diff cannot see: a migration recorded as
 * applied whose change is nevertheless absent, and a migration file that is
 * not on the server at all. Both show up the same way — a column the code
 * writes is not in the live schema — so that is what is checked, directly:
 * every entity's properties are what its mapper INSERTs, and a migration
 * quoting the column name is what should have created it.
 *
 * WHY IT ONLY REPORTS. Running the pending migrations from a web request would
 * be DDL outside Nextcloud's upgrade flow, with no maintenance mode and
 * concurrent requests free to hit a half-changed schema. The repair belongs in
 * occ, where Nextcloud already does it safely — and for a recorded-but-absent
 * change the exact command is `occ migrations:execute`, which re-runs one
 * named migration regardless of what the table says, so the warning names it.
 *
 * COST. The common path is a single appconfig read: once a version has been
 * found complete it is remembered, and the scan is skipped until the app
 * version changes. A failing check is deliberately NOT cached, so the warning
 * clears itself the moment the schema is fixed.
 */
class SchemaVersionService {

    private const CHECKED_KEY = 'schema_verified_for';

    /**
     * Every table the app writes, with the entity whose properties are its
     * columns. Mirrors each Mapper's `parent::__construct($db, table, entity)`
     * and SchemaVersionRegistryTest holds the two in step — a table missing
     * from here is simply never checked, which is the failure mode the backup
     * registry taught us (#351), so the test, not the reader, catches it.
     */
    public const TABLE_ENTITIES = [
        'budget_accounts' => \OCA\Budget\Db\Account::class,
        'budget_assets' => \OCA\Budget\Db\Asset::class,
        'budget_asset_snaps' => \OCA\Budget\Db\AssetSnapshot::class,
        'budget_attachments' => \OCA\Budget\Db\Attachment::class,
        'budget_audit_log' => \OCA\Budget\Db\AuditLog::class,
        'budget_bam' => \OCA\Budget\Db\BankAccountMapping::class,
        'budget_bc' => \OCA\Budget\Db\BankConnection::class,
        'budget_bills' => \OCA\Budget\Db\Bill::class,
        'budget_bgt_snapshots' => \OCA\Budget\Db\BudgetSnapshot::class,
        'budget_categories' => \OCA\Budget\Db\Category::class,
        'budget_cat_mutes' => \OCA\Budget\Db\CategoryMute::class,
        'budget_contacts' => \OCA\Budget\Db\Contact::class,
        'budget_dscn' => \OCA\Budget\Db\DebtScenario::class,
        'budget_dismiss_imp' => \OCA\Budget\Db\DismissedImport::class,
        'budget_dismissed_sugg' => \OCA\Budget\Db\DismissedSuggestion::class,
        'budget_exchange_rates' => \OCA\Budget\Db\ExchangeRate::class,
        'budget_expense_shares' => \OCA\Budget\Db\ExpenseShare::class,
        'budget_idem_keys' => \OCA\Budget\Db\IdempotencyKey::class,
        'budget_imp_links' => \OCA\Budget\Db\ImportAccountLink::class,
        'budget_import_rules' => \OCA\Budget\Db\ImportRule::class,
        'budget_import_templates' => \OCA\Budget\Db\ImportTemplate::class,
        'budget_interest_rates' => \OCA\Budget\Db\InterestRate::class,
        'budget_manual_rates' => \OCA\Budget\Db\ManualExchangeRate::class,
        'budget_nw_snaps' => \OCA\Budget\Db\NetWorthSnapshot::class,
        'budget_pensions' => \OCA\Budget\Db\PensionAccount::class,
        'budget_pen_contribs' => \OCA\Budget\Db\PensionContribution::class,
        'budget_pen_recur' => \OCA\Budget\Db\PensionRecurringContribution::class,
        'budget_pen_snaps' => \OCA\Budget\Db\PensionSnapshot::class,
        'budget_recon_sessions' => \OCA\Budget\Db\ReconciliationSession::class,
        'budget_recurring_income' => \OCA\Budget\Db\RecurringIncome::class,
        'budget_saved_reports' => \OCA\Budget\Db\SavedReport::class,
        'budget_savings_goals' => \OCA\Budget\Db\SavingsGoal::class,
        'budget_settings' => \OCA\Budget\Db\Setting::class,
        'budget_settlements' => \OCA\Budget\Db\Settlement::class,
        'budget_share_auto' => \OCA\Budget\Db\ShareAutoConfig::class,
        'budget_share_items' => \OCA\Budget\Db\ShareItem::class,
        'budget_shares' => \OCA\Budget\Db\Share::class,
        'budget_tags' => \OCA\Budget\Db\Tag::class,
        'budget_tag_sets' => \OCA\Budget\Db\TagSet::class,
        'budget_transactions' => \OCA\Budget\Db\Transaction::class,
        'budget_tx_splits' => \OCA\Budget\Db\TransactionSplit::class,
        'budget_transaction_tags' => \OCA\Budget\Db\TransactionTag::class,
    ];

    /** Marks a table that is absent altogether in getMissingColumns(). */
    public const WHOLE_TABLE = '*';

    /** Computed at most once per request: isBehind() and getWarning() share it. */
    private ?array $pending = null;

    /** Computed at most once per request. */
    private ?array $missing = null;

    /** True when the live schema could not be read; "unknown" must not warn. */
    private bool $probeFailed = false;

    /** Set by recheck(): a save has just failed, so the verified marker is stale. */
    private bool $ignoreMarker = false;

    /** Migration version => file contents, read once per request. */
    private ?array $sources = null;

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
        private SchemaProbe $probe,
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

    /**
     * The source of every shipped migration, keyed by version. Read from the
     * same scan as getShippedMigrations(), so an unreadable directory yields
     * nothing here too — and with it, nothing to expect of the schema.
     *
     * @return array<string, string>
     */
    private function migrationSources(): array {
        if ($this->sources !== null) {
            return $this->sources;
        }

        $sources = [];
        foreach ($this->getShippedMigrations() as $version) {
            $contents = @file_get_contents($this->migrationDir . '/Version' . $version . '.php');
            if ($contents !== false) {
                $sources[$version] = $contents;
            }
        }

        return $this->sources = $sources;
    }

    /**
     * Migration versions whose source names a table — and, when given, a
     * column of it in the same file — i.e. the ones that should have created
     * it, and so the ones to re-execute.
     *
     * The column is scoped to its table on purpose: a migration always fetches
     * or creates the table by name before adding a column to it, so both
     * literals sit in the same file, and matching the column alone made
     * Bill::$currency (a transient filled from the account) an expected column
     * of budget_bills because the ACCOUNTS migrations name 'currency'.
     *
     * @return string[]
     */
    public function migrationsNaming(string $table, ?string $column = null): array {
        $versions = [];
        foreach ($this->migrationSources() as $version => $source) {
            if (!$this->namesIdentifier($source, $table)) {
                continue;
            }
            if ($column !== null && !$this->namesIdentifier($source, $column)) {
                continue;
            }
            $versions[] = $version;
        }
        sort($versions);

        return $versions;
    }

    private function namesIdentifier(string $source, string $identifier): bool {
        return str_contains($source, "'" . $identifier . "'") || str_contains($source, '"' . $identifier . '"');
    }

    /**
     * The columns each table must have: every property of its entity that
     * some shipped migration names.
     *
     * The entity's properties are exactly what its mapper writes, so a missing
     * one is exactly the INSERT that fails. The migration filter is what keeps
     * this honest with zero upkeep: a property that no migration ever quoted
     * is not a column (TagSet::$tags, TransactionSplit::$categoryName) and is
     * left out, and a real column is always quoted by the migration that
     * added it. New table, new column — nothing to register here.
     *
     * @return array<string, string[]> table => columns
     */
    public function getExpectedColumns(): array {
        $expected = [];
        foreach (self::TABLE_ENTITIES as $table => $entityClass) {
            $columns = [];
            foreach ($this->entityColumns($entityClass) as $column) {
                if ($this->migrationsNaming($table, $column) !== []) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                $expected[$table] = $columns;
            }
        }

        return $expected;
    }

    /**
     * The column names an entity's own properties map to, derived the way
     * Nextcloud's Entity does it (camelCase => snake_case).
     *
     * @return string[]
     */
    private function entityColumns(string $entityClass): array {
        if (!class_exists($entityClass)) {
            return [];
        }
        $entity = new $entityClass();
        $columns = [];
        foreach ((new \ReflectionClass($entityClass))->getProperties(\ReflectionProperty::IS_PROTECTED) as $property) {
            // Only the entity's own declarations: the base Entity's bookkeeping
            // (id, _updatedFields, _fieldTypes) is not a column of ours.
            if ($property->getDeclaringClass()->getName() !== $entityClass) {
                continue;
            }
            $columns[] = strtolower($entity->propertyToColumn($property->getName()));
        }

        return $columns;
    }

    /**
     * Columns the code writes that the database does not have, per table.
     * A table that is absent altogether is reported as [WHOLE_TABLE].
     *
     * Empty when the live schema could not be read: not knowing is not a
     * reason to put a red banner on every page.
     *
     * @return array<string, string[]>
     */
    public function getMissingColumns(): array {
        if ($this->missing !== null) {
            return $this->missing;
        }

        $missing = [];
        try {
            foreach ($this->getExpectedColumns() as $table => $columns) {
                $live = $this->probe->tableColumns($table);
                if ($live === null) {
                    $missing[$table] = [self::WHOLE_TABLE];
                    continue;
                }
                $absent = array_values(array_diff($columns, $live));
                if ($absent !== []) {
                    $missing[$table] = $absent;
                }
            }
        } catch (\Throwable $e) {
            $this->probeFailed = true;
            return $this->missing = [];
        }

        return $this->missing = $missing;
    }

    /** True when this code ships migrations the database has never had applied. */
    public function isBehind(): bool {
        if (!$this->ignoreMarker
            && $this->config->getAppValue(Application::APP_ID, self::CHECKED_KEY, '') === $this->appVersion) {
            return false;
        }

        if ($this->getPendingMigrations() !== [] || $this->getMissingColumns() !== []) {
            return true;
        }

        // "Nothing pending" only counts as verified when the migration
        // directory was actually readable and the live schema could be read.
        // An unreadable directory also reports nothing pending, and recording
        // THAT as verified would suppress the warning for the rest of this
        // version's life on exactly the broken instances this exists for —
        // one scandir hiccup during a file-swap deploy would be enough. The
        // memoized scan makes this the SAME scan pending was computed from,
        // never a lucky retry.
        if ($this->getShippedMigrations() !== [] && !$this->probeFailed) {
            $this->config->setAppValue(Application::APP_ID, self::CHECKED_KEY, $this->appVersion);
        }

        return false;
    }

    /**
     * Re-run the check ignoring the verified marker, and drop the marker if
     * something is wrong after all.
     *
     * For the error path: a save has just failed on a missing column, so the
     * schema is NOT fine whatever the marker says — it was recorded before
     * whatever went wrong, and would otherwise keep the banner off the very
     * instance that is broken.
     *
     * @return array{message: string, command: string, details: string[]}|null
     */
    public function recheck(): ?array {
        $this->ignoreMarker = true;
        $warning = $this->getWarning();
        if ($warning !== null) {
            // Wrong after all: take the marker down so every following page
            // load shows the banner, not just this failed request.
            $this->config->deleteAppValue(Application::APP_ID, self::CHECKED_KEY);
        }

        return $warning;
    }

    /**
     * The warning to show, or null when there is nothing wrong.
     *
     * `details` lists what is missing, one line each ("budget_bills.amount_type");
     * `command` is what an administrator runs to fix it.
     *
     * @return array{message: string, command: string, details: string[]}|null
     */
    public function getWarning(): ?array {
        if (!$this->isBehind()) {
            return null;
        }

        $pending = $this->getPendingMigrations();
        $missing = $this->getMissingColumns();

        $details = [];
        $reExecute = [];
        $unrecoverable = [];
        foreach ($missing as $table => $columns) {
            foreach ($columns as $column) {
                $name = $column === self::WHOLE_TABLE ? $table : $table . '.' . $column;
                $details[] = $name;
                $versions = $this->migrationsNaming($table, $column === self::WHOLE_TABLE ? null : $column);
                if ($versions === []) {
                    $unrecoverable[] = $name;
                    continue;
                }
                // A migration that is merely unrecorded is applied by the
                // app:enable below; one that IS recorded yet left no column
                // behind has to be named and re-executed.
                foreach (array_diff($versions, $pending) as $version) {
                    $reExecute[$version] = true;
                }
            }
        }

        $commands = [];
        if ($pending !== []) {
            $commands[] = 'occ app:disable budget && occ app:enable budget';
        }
        if ($reExecute !== []) {
            $versions = array_keys($reExecute);
            sort($versions);
            // The migrations:* commands only exist while debug is on.
            $commands[] = 'occ config:system:set debug --value=true --type=boolean';
            foreach ($versions as $version) {
                $commands[] = 'occ migrations:execute budget ' . $version;
            }
            $commands[] = 'occ config:system:set debug --value=false --type=boolean';
        }
        foreach ($unrecoverable as $name) {
            $details[] = $this->l->t('No migration on this server adds %1$s — the app\'s files are incomplete. Reinstall the app, then run the command.', [$name]);
        }

        $missingCount = array_sum(array_map('count', $missing));
        if ($pending !== []) {
            $message = $this->l->n(
                'A database update that came with this version of Budget was never applied (%n change is missing). Saving may fail until it is finished.',
                'Database updates that came with this version of Budget were never applied (%n changes are missing). Saving may fail until they are finished.',
                count($pending)
            );
        } else {
            $message = $this->l->n(
                'The database is missing %n column this version of Budget writes to. Saving will fail until it is added.',
                'The database is missing %n columns this version of Budget writes to. Saving will fail until they are added.',
                $missingCount
            );
        }

        return [
            'message' => $message,
            'command' => implode(' && ', $commands),
            'details' => $details,
        ];
    }
}
