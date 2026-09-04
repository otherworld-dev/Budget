<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\DigestJob;
use OCA\Budget\Service\AnomalyDetectionService;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\DigestService;
use OCA\Budget\Service\Forecast\ForecastWarningService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class DigestJobTest extends TestCase {
	private DigestJob $job;
	private IDBConnection $db;
	private DigestService $digestService;
	private AnomalyDetectionService $anomalyService;
	private BudgetAlertService $budgetAlertService;
	private ForecastWarningService $forecastWarningService;
	/** @var array<string, string> */
	private array $settings = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->digestService = $this->createMock(DigestService::class);
		$this->anomalyService = $this->createMock(AnomalyDetectionService::class);
		$this->budgetAlertService = $this->createMock(BudgetAlertService::class);
		$this->forecastWarningService = $this->createMock(ForecastWarningService::class);

		$settingService = $this->createMock(SettingService::class);
		$settingService->method('get')
			->willReturnCallback(fn(string $userId, string $key) => $this->settings[$key] ?? null);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[IDBConnection::class, $this->db],
			[SettingService::class, $settingService],
			[DigestService::class, $this->digestService],
			[AnomalyDetectionService::class, $this->anomalyService],
			[BudgetAlertService::class, $this->budgetAlertService],
			[ForecastWarningService::class, $this->forecastWarningService],
			[LoggerInterface::class, $this->createMock(LoggerInterface::class)],
		]);
		\OC::$server = $container;

		$this->job = new DigestJob($this->createMock(ITimeFactory::class));
	}

	protected function tearDown(): void {
		\OC::$server = null;
	}

	/**
	 * The job runs two enumerations in order: users opted in to the digest,
	 * then everyone who owns an account.
	 */
	private function mockUserQueries(array $digestUsers, array $accountUsers): void {
		$resultSets = [
			array_map(static fn($id) => ['user_id' => $id], $digestUsers),
			array_map(static fn($id) => ['user_id' => $id], $accountUsers),
		];

		$this->db->method('getQueryBuilder')->willReturnCallback(function () use (&$resultSets) {
			$result = $this->createMock(IResult::class);
			$result->method('fetchAll')->willReturn(array_shift($resultSets) ?? []);
			$result->method('closeCursor');

			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('selectDistinct')->willReturnSelf();
			$qb->method('from')->willReturnSelf();
			$qb->method('where')->willReturnSelf();
			$qb->method('andWhere')->willReturnSelf();
			$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
			$qb->method('createNamedParameter')->willReturn(':p');
			$qb->method('executeQuery')->willReturn($result);

			return $qb;
		});
	}

	private function invokeRun(): void {
		(new \ReflectionMethod($this->job, 'run'))->invoke($this->job, null);
	}

	public function testSendsBudgetAlertsForEveryUserByDefault(): void {
		$this->mockUserQueries([], ['alice']);

		$this->budgetAlertService->expects($this->once())
			->method('notifyAlerts')
			->with('alice');

		$this->invokeRun();
	}

	public function testSkipsBudgetAlertsWhenTheUserTurnedThemOff(): void {
		$this->mockUserQueries([], ['alice']);
		$this->settings['notification_budget_alert'] = 'false';

		$this->budgetAlertService->expects($this->never())->method('notifyAlerts');

		$this->invokeRun();
	}

	public function testSendsForecastWarningsForEveryUserByDefault(): void {
		$this->mockUserQueries([], ['alice']);

		$this->forecastWarningService->expects($this->once())
			->method('checkAndNotify')
			->with('alice');

		$this->invokeRun();
	}

	public function testSkipsForecastWarningsWhenTheUserTurnedThemOff(): void {
		$this->mockUserQueries([], ['alice']);
		$this->settings['notification_forecast_warning'] = 'false';

		$this->forecastWarningService->expects($this->never())->method('checkAndNotify');

		$this->invokeRun();
	}

	public function testSkipsAnomalyAlertsWhenTheUserTurnedThemOff(): void {
		$this->mockUserQueries([], ['alice']);
		$this->settings['anomaly_alerts_enabled'] = 'false';

		$this->anomalyService->expects($this->never())->method('detectAndNotify');

		$this->invokeRun();
	}

	/**
	 * One failing check must not cost the user the others — they are
	 * independent notifications that happen to share a nightly run.
	 */
	public function testAFailingBudgetAlertStillLetsTheForecastWarningRun(): void {
		$this->mockUserQueries([], ['alice']);
		$this->budgetAlertService->method('notifyAlerts')
			->willThrowException(new \RuntimeException('boom'));

		$this->forecastWarningService->expects($this->once())->method('checkAndNotify');

		$this->invokeRun();
	}

	public function testDigestIsSentOnlyToUsersWhoOptedIn(): void {
		$this->mockUserQueries(['alice'], []);

		$this->digestService->expects($this->once())
			->method('sendDigest')
			->with('alice', 'weekly');

		$this->invokeRun();
	}
}
