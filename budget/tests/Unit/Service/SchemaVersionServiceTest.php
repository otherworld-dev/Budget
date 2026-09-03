<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\SchemaProbe;
use OCA\Budget\Service\SchemaVersionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * A missing column means Nextcloud never finished upgrading the app, so the
 * migrations that add it never ran. Reads do not notice (the mapper selects
 * `*`), so the first sign is a save failing with "Unknown column" (#333).
 *
 * Comparing appconfig's installed_version against info.xml was tried first and
 * does not work: when those differ Nextcloud serves its own upgrade screen
 * instead of the app, so the warning could never be seen. The migration table
 * is the only thing that distinguishes "upgraded" from "actually migrated".
 */
class SchemaVersionServiceTest extends TestCase {
    private IConfig $config;
    private IDBConnection $db;
    private IL10N $l;
    private SchemaProbe $probe;
    private string $migrationDir;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->probe = $this->createMock(SchemaProbe::class);
        $this->l = $this->createMock(IL10N::class);
        $this->l->method('t')->willReturnCallback(
            static fn(string $text, $params = []): string => vsprintf(str_replace('%1$s', '%s', $text), (array)$params)
        );
        $this->l->method('n')->willReturnCallback(
            static fn(string $one, string $many, int $count): string =>
                str_replace('%n', (string)$count, $count === 1 ? $one : $many)
        );

        // A directory holding two migration files, named as the real ones are.
        $this->migrationDir = sys_get_temp_dir() . '/budget-mig-' . getmypid();
        @mkdir($this->migrationDir, 0777, true);
        foreach (['Version001000096Date20260824.php', 'Version001000097Date20260825.php'] as $f) {
            file_put_contents($this->migrationDir . '/' . $f, '<?php');
        }
        // Something that is not a migration must be ignored.
        file_put_contents($this->migrationDir . '/README.md', 'not a migration');
    }

    protected function tearDown(): void {
        foreach ([$this->migrationDir, $this->migrationDir . '-late'] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }

    /** @param string[] $applied */
    private function service(array $applied, string $verifiedFor = '', string $appVersion = '2.44.1', ?string $migrationDir = null): SchemaVersionService {
        $result = $this->createMock(IResult::class);
        $rows = array_map(static fn(string $v): array => ['version' => $v], $applied);
        $rows[] = false; // fetch() returns false when exhausted
        $result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
        $result->method('closeCursor');

        $expr = $this->createMock(IExpressionBuilder::class);
        $qb = $this->createMock(IQueryBuilder::class);
        foreach (['select', 'from', 'where'] as $m) {
            $qb->method($m)->willReturnSelf();
        }
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn(':p');
        $qb->method('executeQuery')->willReturn($result);
        $this->db->method('getQueryBuilder')->willReturn($qb);

        // Pinned to the exact key: a read/write key mismatch in the service
        // would make the cache never hit and re-query on every page load,
        // and an any-args stub would never notice.
        $this->config->method('getAppValue')
            ->with('budget', 'schema_verified_for', '')
            ->willReturn($verifiedFor);

        return new SchemaVersionService($this->config, $this->db, $this->probe, $this->l, $appVersion, $migrationDir ?? $this->migrationDir);
    }

    /**
     * A migration file whose source quotes the identifiers a real one would,
     * so the entity-driven column check has something to expect.
     */
    private function shipMigration(string $version, array $identifiers, ?string $dir = null): void {
        $quoted = implode(', ', array_map(static fn(string $i): string => "'" . $i . "'", $identifiers));
        file_put_contents(($dir ?? $this->migrationDir) . '/Version' . $version . '.php', '<?php // ' . $quoted);
    }

    public function testShippedMigrationsAreReadFromDiskAndNonMigrationsIgnored(): void {
        $shipped = $this->service([])->getShippedMigrations();

        $this->assertSame(['001000096Date20260824', '001000097Date20260825'], $shipped);
    }

    public function testNothingPendingWhenEveryShippedMigrationHasRun(): void {
        $service = $this->service(['001000096Date20260824', '001000097Date20260825']);

        $this->assertSame([], $service->getPendingMigrations());
        $this->assertFalse($service->isBehind());
        $this->assertNull($service->getWarning());
    }

    public function testAMigrationThatNeverRanIsReported(): void {
        $service = $this->service(['001000096Date20260824']);

        $this->assertSame(['001000097Date20260825'], $service->getPendingMigrations());
        $this->assertTrue($service->isBehind());
    }

    public function testTheWarningCountsTheMissingChangesAndNamesTheCommand(): void {
        $warning = $this->service([])->getWarning();

        $this->assertNotNull($warning);
        $this->assertStringContainsString('2 changes are missing', $warning['message']);
        $this->assertStringContainsString('occ app:disable budget', $warning['command']);
        $this->assertStringContainsString('occ app:enable budget', $warning['command']);
    }

    public function testTheWarningReadsNaturallyForASingleMissingChange(): void {
        $warning = $this->service(['001000096Date20260824'])->getWarning();

        $this->assertStringContainsString('1 change is missing', $warning['message']);
    }

    /**
     * Once a version has been found complete the scan is skipped, so the
     * common path costs one appconfig read rather than a query per page load.
     */
    public function testAVerifiedVersionSkipsTheCheckEntirely(): void {
        $this->db->expects($this->never())->method('getQueryBuilder');

        $service = $this->service([], '2.44.1', '2.44.1');

        $this->assertFalse($service->isBehind());
    }

    /** A stale marker from an older version must not suppress the check. */
    public function testAMarkerFromAnEarlierVersionDoesNotSuppressTheCheck(): void {
        $service = $this->service(['001000096Date20260824'], '2.43.0', '2.44.1');

        $this->assertTrue($service->isBehind());
    }

    /**
     * An unreadable migration directory means we know nothing. Warning on a
     * guess would put a red banner on every page of a healthy instance.
     */
    public function testAnUnreadableMigrationDirectoryReportsNothing(): void {
        $service = new SchemaVersionService(
            $this->config, $this->db, $this->probe, $this->l, '2.44.1', $this->migrationDir . '-does-not-exist'
        );

        // The dangerous half: "nothing pending" from an unreadable directory
        // must not be recorded as verified, or the marker would suppress the
        // warning for the rest of the version's life once the directory is
        // readable again.
        $this->config->expects($this->never())->method('setAppValue');

        $this->assertSame([], $service->getShippedMigrations());
        $this->assertSame([], $service->getPendingMigrations());
        $this->assertFalse($service->isBehind());
    }

    public function testACleanReadableInstanceIsRecordedAsVerified(): void {
        $service = $this->service(['001000096Date20260824', '001000097Date20260825']);

        $this->config->expects($this->once())
            ->method('setAppValue')
            ->with('budget', 'schema_verified_for', '2.44.1');

        $this->assertFalse($service->isBehind());
    }

    /**
     * The race the verified marker must survive: a scandir that fails and
     * then succeeds within the same request (a file-swap deploy finishing
     * mid-request). getPendingMigrations() computed pending = [] from the
     * FAILED scan; isBehind() then re-scanned, saw files, and recorded the
     * version as verified while a migration was genuinely pending —
     * permanently silencing the warning banner on exactly the broken
     * instance it exists for. The marker may only ever come from the same
     * coherent successful scan that pending was computed from.
     */
    public function testAScanFailureIsNotRepairedIntoAVerifiedMarkerLaterInTheRequest(): void {
        $lateDir = $this->migrationDir . '-late';
        // Nothing applied, so once the directory exists its migration is
        // genuinely pending.
        $service = $this->service([], '', '2.44.1', $lateDir);

        $this->config->expects($this->never())->method('setAppValue');

        // First scan fails: the directory does not exist yet.
        $this->assertSame([], $service->getPendingMigrations());

        // The directory appears mid-request; a fresh scan would now succeed.
        mkdir($lateDir, 0777, true);
        file_put_contents($lateDir . '/Version001000097Date20260825.php', '<?php');

        $this->assertFalse($service->isBehind());
        $this->assertNull($service->getWarning());
    }

    /**
     * The clean path reads the migration directory once per request: pending
     * and the verified marker share one memoized scan. Deleting the
     * directory after the first read proves it — a second scan would fail
     * and (correctly) refuse to record the marker.
     */
    public function testTheCleanPathScansTheMigrationDirectoryOnce(): void {
        $service = $this->service(['001000096Date20260824', '001000097Date20260825']);

        $this->assertSame([], $service->getPendingMigrations());

        foreach (glob($this->migrationDir . '/*') ?: [] as $f) {
            unlink($f);
        }
        rmdir($this->migrationDir);

        $this->config->expects($this->once())
            ->method('setAppValue')
            ->with('budget', 'schema_verified_for', '2.44.1');

        $this->assertFalse($service->isBehind());
    }

    /** Nextcloud accepts any Version*.php migration, so this must too. */
    public function testAnUnconventionallyNamedMigrationIsStillCompared(): void {
        file_put_contents($this->migrationDir . '/VersionAmountTypeFix.php', '<?php');

        $service = $this->service(['001000096Date20260824', '001000097Date20260825']);

        $this->assertSame(['AmountTypeFix'], $service->getPendingMigrations());
        $this->assertTrue($service->isBehind());
    }

    // ── the live schema, not just the migrations table (#333) ────────

    /**
     * The instance in #333 had every migration recorded and the column still
     * missing, and app:disable/enable — which re-runs only what is NOT
     * recorded — changed nothing. Only the schema itself can tell.
     */
    public function testAColumnTheCodeWritesButTheDatabaseLacksIsReported(): void {
        $this->shipMigration('001000094Date20260819', ['budget_bills', 'amount_type']);
        $this->probe->method('tableColumns')->willReturnCallback(
            static fn(string $table): ?array => $table === 'budget_bills' ? ['id', 'name', 'amount'] : ['id']
        );

        // Everything recorded as applied: a files-vs-table diff sees nothing.
        $service = $this->service(['001000094Date20260819', '001000096Date20260824', '001000097Date20260825']);

        $this->assertSame([], $service->getPendingMigrations());
        $this->assertSame(['budget_bills' => ['amount_type']], $service->getMissingColumns());
        $this->assertTrue($service->isBehind());
    }

    public function testTheWarningNamesTheColumnAndTheMigrationToReExecute(): void {
        $this->shipMigration('001000094Date20260819', ['budget_bills', 'amount_type']);
        $this->probe->method('tableColumns')->willReturn(['id', 'name']);

        $warning = $this->service(['001000094Date20260819', '001000096Date20260824', '001000097Date20260825'])->getWarning();

        $this->assertNotNull($warning);
        $this->assertStringContainsString('1 column', $warning['message']);
        $this->assertSame(['budget_bills.amount_type'], $warning['details']);
        $this->assertStringContainsString('occ migrations:execute budget 001000094Date20260819', $warning['command']);
        // The migrations:* commands only exist with debug on — say so, both ways.
        $this->assertStringContainsString('debug --value=true', $warning['command']);
        $this->assertStringContainsString('debug --value=false', $warning['command']);
        // Not the generic line: it was followed on the #333 instance and did nothing.
        $this->assertStringNotContainsString('app:disable', $warning['command']);
    }

    /** Unrecorded AND absent: app:enable applies it, no re-execute needed. */
    public function testAnUnrecordedMigrationsMissingColumnPointsAtAppEnableOnly(): void {
        $this->shipMigration('001000094Date20260819', ['budget_bills', 'amount_type']);
        $this->probe->method('tableColumns')->willReturn(['id', 'name']);

        $warning = $this->service(['001000096Date20260824', '001000097Date20260825'])->getWarning();

        $this->assertStringContainsString('occ app:enable budget', $warning['command']);
        $this->assertStringNotContainsString('migrations:execute', $warning['command']);
        $this->assertSame(['budget_bills.amount_type'], $warning['details']);
    }

    /**
     * The other way #333 can happen: the file that adds the column is not on
     * the server at all. Nothing can re-run what is not there, so say so
     * instead of inventing a command.
     */
    public function testAMissingColumnNoShippedMigrationNamesIsCalledOut(): void {
        // budget_bills is named, so the table is expected — but only its
        // 'name' column is, and the probe has it; amount_type is unknown
        // to every migration on disk, so it is not expected either. Force
        // the case through a column a migration DOES name whose file we then
        // pretend is the one missing: name the table only.
        $this->shipMigration('001000094Date20260819', ['budget_bills', 'due_day']);
        $this->probe->method('tableColumns')->willReturn(['id', 'name']);

        $service = $this->service(['001000094Date20260819', '001000096Date20260824', '001000097Date20260825']);
        $warning = $service->getWarning();

        // due_day is expected (a migration names it) and missing, and that
        // migration is recorded — so it is the one to re-execute.
        $this->assertSame(['budget_bills' => ['due_day']], $service->getMissingColumns());
        $this->assertStringContainsString('migrations:execute budget 001000094Date20260819', $warning['command']);
    }

    public function testAWholeTableThatIsAbsentIsReportedAsSuch(): void {
        $this->shipMigration('001000094Date20260819', ['budget_bills', 'amount_type']);
        $this->probe->method('tableColumns')->willReturn(null);

        $service = $this->service(['001000094Date20260819', '001000096Date20260824', '001000097Date20260825']);

        $this->assertSame(['budget_bills' => [SchemaVersionService::WHOLE_TABLE]], $service->getMissingColumns());
        $this->assertSame(['budget_bills'], $service->getWarning()['details']);
    }

    /**
     * An entity property no migration ever quoted is not a column
     * (TagSet::$tags, TransactionSplit::$categoryName): it must never be
     * demanded of the database. Only what a migration names is expected.
     */
    public function testOnlyColumnsAMigrationNamesAreExpected(): void {
        $this->shipMigration('001000094Date20260819', ['budget_tag_sets', 'name']);

        $expected = $this->service([])->getExpectedColumns();

        // TagSet has a $tags property and a $name property; only the one a
        // migration names is a column the database is expected to have.
        $this->assertSame(['name'], $expected['budget_tag_sets']);
        // Bill has a $name too, but no shipped migration names budget_bills,
        // so nothing is expected of that table.
        $this->assertArrayNotHasKey('budget_bills', $expected);
    }

    /**
     * Found live on the dev instance: Bill::$currency is filled from the
     * account and is not a column, but the ACCOUNTS migrations name
     * 'currency', so matching the column name alone demanded it of
     * budget_bills. A column only counts for the table whose migration
     * names them both.
     */
    public function testAColumnNamedForAnotherTableIsNotExpectedOfThisOne(): void {
        $this->shipMigration('001000033Date20260227', ['budget_accounts', 'currency']);
        $this->shipMigration('001000094Date20260819', ['budget_bills', 'amount_type']);

        $expected = $this->service([])->getExpectedColumns();

        $this->assertSame(['currency'], $expected['budget_accounts']);
        $this->assertSame(['amount_type'], $expected['budget_bills']);
    }

    public function testMigrationsNamingIsScopedToTheTable(): void {
        $this->shipMigration('001000033Date20260227', ['budget_accounts', 'currency']);
        $this->shipMigration('001000094Date20260819', ['budget_bills', 'amount_type']);
        $service = $this->service([]);

        $this->assertSame(['001000033Date20260227'], $service->migrationsNaming('budget_accounts', 'currency'));
        $this->assertSame([], $service->migrationsNaming('budget_bills', 'currency'));
        $this->assertSame(['001000094Date20260819'], $service->migrationsNaming('budget_bills'));
    }

    /**
     * Not being able to read the schema is not evidence of anything. No
     * warning — and, as with an unreadable directory, no verified marker
     * either, or the next readable request would never look again.
     */
    public function testAProbeFailureReportsNothingAndIsNotRecordedAsVerified(): void {
        $this->shipMigration('001000094Date20260819', ['budget_bills', 'amount_type']);
        $this->probe->method('tableColumns')->willThrowException(new \RuntimeException('no schema for you'));
        $this->config->expects($this->never())->method('setAppValue');

        $service = $this->service(['001000094Date20260819', '001000096Date20260824', '001000097Date20260825']);

        $this->assertSame([], $service->getMissingColumns());
        $this->assertFalse($service->isBehind());
        $this->assertNull($service->getWarning());
    }

    /** A save just failed on the schema: the marker is stale by definition. */
    public function testRecheckDropsTheVerifiedMarkerAndLooksAgain(): void {
        $this->shipMigration('001000094Date20260819', ['budget_bills', 'amount_type']);
        $this->probe->method('tableColumns')->willReturn(['id', 'name']);
        $this->config->expects($this->once())
            ->method('deleteAppValue')
            ->with('budget', 'schema_verified_for');

        // Marked verified for this very version — isBehind() alone would say fine.
        $service = $this->service(['001000094Date20260819', '001000096Date20260824', '001000097Date20260825'], '2.44.1', '2.44.1');

        $warning = $service->recheck();

        $this->assertNotNull($warning);
        $this->assertSame(['budget_bills.amount_type'], $warning['details']);
    }
}
