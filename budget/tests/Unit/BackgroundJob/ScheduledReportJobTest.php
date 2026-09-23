<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\ScheduledReportJob;
use OCA\Budget\Service\Report\ScheduledReportService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ScheduledReportJobTest extends TestCase {
	private ScheduledReportJob $job;
	private IDBConnection $db;
	private SettingService $settingService;
	private ScheduledReportService $reportService;
	private LoggerInterface $logger;
	/** @var array<string, array<string, string>> userId => key => value */
	private array $settings = [];
	/** @var array<int, array{0: string, 1: string, 2: string}> set() calls */
	private array $writes = [];
	/** @var array<int, mixed> values bound into the eligibility query */
	private array $boundParams = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->reportService = $this->createMock(ScheduledReportService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->settingService = $this->createMock(SettingService::class);
		$this->settingService->method('get')
			->willReturnCallback(fn(string $userId, string $key) => $this->settings[$userId][$key] ?? null);
		$this->settingService->method('set')
			->willReturnCallback(function (string $userId, string $key, string $value) {
				$this->writes[] = [$userId, $key, $value];
				$this->settings[$userId][$key] = $value;
				return new \OCA\Budget\Db\Setting();
			});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[IDBConnection::class, $this->db],
			[SettingService::class, $this->settingService],
			[ScheduledReportService::class, $this->reportService],
			[LoggerInterface::class, $this->logger],
		]);
		\OC::$server = $container;

		$this->job = new ScheduledReportJob($this->createMock(ITimeFactory::class));
	}

	protected function tearDown(): void {
		\OC::$server = null;
	}

	/** The month the job targets when run now: always the previous calendar month. */
	private static function lastMonth(): string {
		return date('Y-m', strtotime('first day of last month'));
	}

	private function givenEligibleUsers(array $userIds): void {
		$result = $this->createMock(IResult::class);
		$rows = array_map(static fn($id) => ['user_id' => $id], $userIds);
		$result->method('fetchAll')->willReturn($rows);
		// Each run reads the rows afresh; rewind when the query is re-executed
		$cursor = 0;
		$result->method('fetch')->willReturnCallback(function () use (&$cursor, $rows) {
			return $rows[$cursor++] ?? false;
		});
		$result->method('closeCursor')->willReturnCallback(function () use (&$cursor) {
			$cursor = 0;
			return true;
		});

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('selectDistinct')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('createNamedParameter')->willReturnCallback(function ($value) {
			$this->boundParams[] = $value;
			return ':p' . count($this->boundParams);
		});
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	private function invokeRun(): void {
		(new \ReflectionMethod($this->job, 'run'))->invoke($this->job, null);
	}

	// ── eligibility ─────────────────────────────────────────────────

	public function testEligibilityQueryLooksForEitherChannelSwitchedOn(): void {
		$this->givenEligibleUsers([]);

		$this->invokeRun();

		$this->assertContains(['report_files_enabled', 'report_email_enabled'], $this->boundParams);
		$this->assertContains('true', $this->boundParams);
	}

	public function testNoEligibleUsersDeliversNothing(): void {
		$this->givenEligibleUsers([]);
		$this->reportService->expects($this->never())->method('deliverMonthlyReport');

		$this->invokeRun();

		$this->assertSame([], $this->writes);
	}

	// ── delivery ────────────────────────────────────────────────────

	public function testDeliversLastMonthsReportToFilesOnly(): void {
		$this->givenEligibleUsers(['alice']);
		$this->settings['alice'] = ['report_files_enabled' => 'true'];
		$this->reportService->expects($this->once())
			->method('deliverMonthlyReport')
			->with('alice', self::lastMonth(), true, false)
			->willReturn(true);

		$this->invokeRun();

		$this->assertSame([['alice', 'report_last_month', self::lastMonth()]], $this->writes);
	}

	public function testDeliversByEmailOnly(): void {
		$this->givenEligibleUsers(['alice']);
		$this->settings['alice'] = ['report_email_enabled' => 'true'];
		$this->reportService->expects($this->once())
			->method('deliverMonthlyReport')
			->with('alice', self::lastMonth(), false, true)
			->willReturn(true);

		$this->invokeRun();
	}

	public function testDeliversThroughBothChannelsWhenBothAreOn(): void {
		$this->givenEligibleUsers(['alice']);
		$this->settings['alice'] = ['report_files_enabled' => 'true', 'report_email_enabled' => 'true'];
		$this->reportService->expects($this->once())
			->method('deliverMonthlyReport')
			->with('alice', self::lastMonth(), true, true)
			->willReturn(true);

		$this->invokeRun();
	}

	public function testTheTargetMonthIsFormattedYearDashMonth(): void {
		$this->givenEligibleUsers(['alice']);
		$this->settings['alice'] = ['report_files_enabled' => 'true'];
		$month = null;
		$this->reportService->method('deliverMonthlyReport')
			->willReturnCallback(function ($user, $m) use (&$month) {
				$month = $m;
				return true;
			});

		$this->invokeRun();

		$this->assertMatchesRegularExpression('/^\d{4}-(0[1-9]|1[0-2])$/', $month);
		$this->assertLessThan(date('Y-m'), $month);
	}

	// ── idempotency ─────────────────────────────────────────────────

	public function testAReportAlreadyDeliveredForTheMonthIsNotSentAgain(): void {
		$this->givenEligibleUsers(['alice']);
		$this->settings['alice'] = [
			'report_files_enabled' => 'true',
			'report_last_month' => self::lastMonth(),
		];
		$this->reportService->expects($this->never())->method('deliverMonthlyReport');

		$this->invokeRun();

		$this->assertSame([], $this->writes);
	}

	public function testAnOlderDeliveryDoesNotBlockThisMonthsReport(): void {
		$this->givenEligibleUsers(['alice']);
		$this->settings['alice'] = [
			'report_files_enabled' => 'true',
			'report_last_month' => '2001-01',
		];
		$this->reportService->expects($this->once())->method('deliverMonthlyReport')->willReturn(true);

		$this->invokeRun();

		$this->assertSame(self::lastMonth(), $this->settings['alice']['report_last_month']);
	}

	public function testRunningTwiceInOneMonthDeliversOnce(): void {
		$this->givenEligibleUsers(['alice']);
		$this->settings['alice'] = ['report_files_enabled' => 'true'];
		$this->reportService->expects($this->once())->method('deliverMonthlyReport')->willReturn(true);

		$this->invokeRun();
		$this->invokeRun();
	}

	/**
	 * When every channel fails, the month is not marked done, so tomorrow's
	 * run tries again instead of silently skipping the report.
	 */
	public function testAllChannelsFailingLeavesTheMonthOpenForARetry(): void {
		$this->givenEligibleUsers(['alice']);
		$this->settings['alice'] = ['report_files_enabled' => 'true'];
		$this->reportService->method('deliverMonthlyReport')->willReturn(false);
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('will retry tomorrow'));

		$this->invokeRun();

		$this->assertSame([], $this->writes);
		$this->assertArrayNotHasKey('report_last_month', $this->settings['alice']);
	}

	public function testAFailedRunIsRetriedOnTheNextRun(): void {
		$this->givenEligibleUsers(['alice']);
		$this->settings['alice'] = ['report_files_enabled' => 'true'];
		$this->reportService->expects($this->exactly(2))
			->method('deliverMonthlyReport')
			->willReturnOnConsecutiveCalls(false, true);

		$this->invokeRun();
		$this->invokeRun();

		$this->assertSame(self::lastMonth(), $this->settings['alice']['report_last_month']);
	}

	// ── switches ────────────────────────────────────────────────────

	public function testAUserWithBothChannelsOffIsSkipped(): void {
		// Listed by the query (stale row) but both switches now read off.
		$this->givenEligibleUsers(['alice']);
		$this->settings['alice'] = ['report_files_enabled' => 'false', 'report_email_enabled' => 'false'];
		$this->reportService->expects($this->never())->method('deliverMonthlyReport');

		$this->invokeRun();

		$this->assertSame([], $this->writes);
	}

	public function testOnlyTheExactStringTrueSwitchesAChannelOn(): void {
		$this->givenEligibleUsers(['alice']);
		$this->settings['alice'] = ['report_files_enabled' => '1', 'report_email_enabled' => 'TRUE'];
		$this->reportService->expects($this->never())->method('deliverMonthlyReport');

		$this->invokeRun();
	}

	// ── isolation between users ─────────────────────────────────────

	public function testOneUsersFailureDoesNotStopTheOthers(): void {
		$this->givenEligibleUsers(['alice', 'bob']);
		$this->settings['alice'] = ['report_files_enabled' => 'true'];
		$this->settings['bob'] = ['report_email_enabled' => 'true'];
		$this->reportService->method('deliverMonthlyReport')
			->willReturnCallback(function (string $userId) {
				if ($userId === 'alice') {
					throw new \RuntimeException('TCPDF exploded');
				}
				return true;
			});
		$this->logger->expects($this->once())
			->method('warning')
			->with($this->logicalAnd($this->stringContains('alice'), $this->stringContains('TCPDF exploded')));

		$this->invokeRun();

		$this->assertSame([['bob', 'report_last_month', self::lastMonth()]], $this->writes);
	}

	public function testEachUserGetsTheirOwnChannels(): void {
		$this->givenEligibleUsers(['alice', 'bob']);
		$this->settings['alice'] = ['report_files_enabled' => 'true'];
		$this->settings['bob'] = ['report_email_enabled' => 'true'];
		$calls = [];
		$this->reportService->method('deliverMonthlyReport')
			->willReturnCallback(function (...$args) use (&$calls) {
				$calls[] = $args;
				return true;
			});

		$this->invokeRun();

		$this->assertSame([
			['alice', self::lastMonth(), true, false],
			['bob', self::lastMonth(), false, true],
		], $calls);
	}

	// ── summary log ─────────────────────────────────────────────────

	public function testLogsHowManyReportsWereDelivered(): void {
		$this->givenEligibleUsers(['alice', 'bob', 'carol']);
		$this->settings['alice'] = ['report_files_enabled' => 'true'];
		$this->settings['bob'] = ['report_files_enabled' => 'true', 'report_last_month' => self::lastMonth()];
		$this->settings['carol'] = ['report_email_enabled' => 'true'];
		$this->reportService->method('deliverMonthlyReport')
			->willReturnCallback(fn(string $userId) => $userId === 'alice');
		$this->logger->expects($this->once())
			->method('info')
			->with($this->stringContains('1 reports delivered for ' . self::lastMonth()));

		$this->invokeRun();
	}
}
