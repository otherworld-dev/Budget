<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob\Queued;

use OCA\Budget\BackgroundJob\Queued\UserDigestJob;
use OCA\Budget\Service\AnomalyDetectionService;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\DigestService;
use OCA\Budget\Service\Forecast\ForecastWarningService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class UserDigestJobTest extends TestCase {
	private UserDigestJob $job;
	private DigestService $digestService;
	private AnomalyDetectionService $anomalyService;
	private BudgetAlertService $budgetAlertService;
	private ForecastWarningService $forecastWarningService;
	/** @var array<string, string> */
	private array $settings = [];
	/** @var array<string, string> settings written */
	private array $written = [];

	protected function setUp(): void {
		$this->digestService = $this->createMock(DigestService::class);
		$this->anomalyService = $this->createMock(AnomalyDetectionService::class);
		$this->budgetAlertService = $this->createMock(BudgetAlertService::class);
		$this->forecastWarningService = $this->createMock(ForecastWarningService::class);

		$settingService = $this->createMock(SettingService::class);
		$settingService->method('get')
			->willReturnCallback(fn (string $userId, string $key) => $this->settings[$key] ?? null);
		$settingService->method('set')
			->willReturnCallback(function (string $userId, string $key, string $value) {
				$this->written[$key] = $value;
				return new \OCA\Budget\Db\Setting();
			});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[SettingService::class, $settingService],
			[DigestService::class, $this->digestService],
			[AnomalyDetectionService::class, $this->anomalyService],
			[BudgetAlertService::class, $this->budgetAlertService],
			[ForecastWarningService::class, $this->forecastWarningService],
			[LoggerInterface::class, $this->createMock(LoggerInterface::class)],
		]);
		\OC::$server = $container;

		$this->job = new UserDigestJob($this->createMock(ITimeFactory::class));
	}

	protected function tearDown(): void {
		\OC::$server = null;
	}

	private function runFor(mixed $argument = ['userId' => 'alice']): void {
		(new \ReflectionMethod($this->job, 'run'))->invoke($this->job, $argument);
	}

	public function testSendsBudgetAlertsByDefault(): void {
		$this->budgetAlertService->expects($this->once())->method('notifyAlerts')->with('alice');

		$this->runFor();
	}

	public function testSkipsBudgetAlertsWhenTheUserTurnedThemOff(): void {
		$this->settings['notification_budget_alert'] = 'false';
		$this->budgetAlertService->expects($this->never())->method('notifyAlerts');

		$this->runFor();
	}

	public function testSendsForecastWarningsByDefault(): void {
		$this->forecastWarningService->expects($this->once())->method('checkAndNotify')->with('alice');

		$this->runFor();
	}

	public function testSkipsForecastWarningsWhenTheUserTurnedThemOff(): void {
		$this->settings['notification_forecast_warning'] = 'false';
		$this->forecastWarningService->expects($this->never())->method('checkAndNotify');

		$this->runFor();
	}

	public function testRunsAnomalyDetectionByDefault(): void {
		$this->anomalyService->expects($this->once())->method('detectAndNotify')->with('alice');

		$this->runFor();
	}

	public function testSkipsAnomalyAlertsWhenTheUserTurnedThemOff(): void {
		$this->settings['anomaly_alerts_enabled'] = 'false';
		$this->anomalyService->expects($this->never())->method('detectAndNotify');

		$this->runFor();
	}

	/**
	 * One failing check must not cost the user the others — they are
	 * independent notifications that happen to share a run.
	 */
	public function testAFailingBudgetAlertStillLetsTheForecastWarningRun(): void {
		$this->budgetAlertService->method('notifyAlerts')->willThrowException(new \RuntimeException('boom'));
		$this->forecastWarningService->expects($this->once())->method('checkAndNotify');

		$this->runFor();
	}

	public function testAFailingDigestStillLetsTheAlertsRun(): void {
		$this->settings['digest_enabled'] = 'true';
		$this->digestService->method('sendDigest')->willThrowException(new \RuntimeException('smtp'));
		$this->budgetAlertService->expects($this->once())->method('notifyAlerts');

		$this->runFor();

		$this->assertArrayNotHasKey('digest_last_period', $this->written, 'a failed digest must be retried');
	}

	public function testDigestIsSentOnlyToUsersWhoOptedIn(): void {
		$this->digestService->expects($this->never())->method('sendDigest');

		$this->runFor();
	}

	public function testSendsTheDigestOncePerPeriod(): void {
		$this->settings['digest_enabled'] = 'true';
		$this->digestService->expects($this->once())->method('sendDigest')->with('alice', 'weekly');

		$this->runFor();
		$this->assertSame(date('o-\WW'), $this->written['digest_last_period']);

		// The period key now matches: the next run sends nothing
		$this->settings['digest_last_period'] = $this->written['digest_last_period'];
		$this->runFor();
	}

	public function testMonthlyDigestUsesTheMonthAsItsPeriod(): void {
		$this->settings['digest_enabled'] = 'true';
		$this->settings['digest_frequency'] = 'monthly';
		$this->digestService->expects($this->once())->method('sendDigest')->with('alice', 'monthly');

		$this->runFor();

		$this->assertSame(date('Y-m'), $this->written['digest_last_period']);
	}

	public function testIgnoresAJobWithoutAUser(): void {
		$this->budgetAlertService->expects($this->never())->method('notifyAlerts');

		$this->runFor(null);
		$this->runFor(['userId' => '']);
	}
}
