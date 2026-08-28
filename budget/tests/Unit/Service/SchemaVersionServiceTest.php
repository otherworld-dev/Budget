<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

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
    private string $migrationDir;

    protected function setUp(): void {
        $this->config = $this->createMock(IConfig::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->l = $this->createMock(IL10N::class);
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
        foreach (glob($this->migrationDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->migrationDir);
    }

    /** @param string[] $applied */
    private function service(array $applied, string $verifiedFor = '', string $appVersion = '2.44.1'): SchemaVersionService {
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

        return new SchemaVersionService($this->config, $this->db, $this->l, $appVersion, $this->migrationDir);
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
            $this->config, $this->db, $this->l, '2.44.1', $this->migrationDir . '-does-not-exist'
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

    /** Nextcloud accepts any Version*.php migration, so this must too. */
    public function testAnUnconventionallyNamedMigrationIsStillCompared(): void {
        file_put_contents($this->migrationDir . '/VersionAmountTypeFix.php', '<?php');

        $service = $this->service(['001000096Date20260824', '001000097Date20260825']);

        $this->assertSame(['AmountTypeFix'], $service->getPendingMigrations());
        $this->assertTrue($service->isBehind());
    }
}
