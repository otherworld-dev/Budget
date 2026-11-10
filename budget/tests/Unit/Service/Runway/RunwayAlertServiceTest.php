<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Runway;

use OCA\Budget\Service\Runway\RunwayAlertService;
use OCA\Budget\Service\Runway\RunwayService;
use OCA\Budget\Service\SettingService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class RunwayAlertServiceTest extends TestCase {
	private RunwayAlertService $service;

	/** What the runway says about the current spending */
	private array $current = ['state' => 'running_out', 'monthlyDrain' => '1000.00', 'months' => 20.0, 'runsOutOn' => '2028-07-20'];
	/** @var array<string, string> */
	private array $settings = [];
	/** @var array[] subject parameters of each notification sent */
	private array $sent = [];
	/** @var string[] objects of each notification sent */
	private array $objects = [];

	private const USER_ID = 'alice';
	private const KEY = 'runway_alert_baseline';

	protected function setUp(): void {
		$runway = $this->createMock(RunwayService::class);
		$runway->method('get')->willReturnCallback(fn () => ['scenarios' => ['current' => $this->current]]);

		$settingService = $this->createMock(SettingService::class);
		$settingService->method('get')
			->willReturnCallback(fn (string $userId, string $key) => $this->settings[$key] ?? null);
		$settingService->method('set')
			->willReturnCallback(function (string $userId, string $key, string $value) {
				$this->settings[$key] = $value;
				return new \OCA\Budget\Db\Setting();
			});
		$settingService->method('delete')
			->willReturnCallback(function (string $userId, string $key) {
				$had = isset($this->settings[$key]);
				unset($this->settings[$key]);
				return $had ? 1 : 0;
			});

		$notification = $this->createMock(INotification::class);
		$notification->method('setSubject')
			->willReturnCallback(function (string $subject, array $params) use ($notification) {
				$this->assertSame('runway_warning', $subject);
				$this->sent[] = $params;
				return $notification;
			});
		$notification->method('setObject')
			->willReturnCallback(function (string $type, string $id) use ($notification) {
				$this->objects[] = $type . ':' . $id;
				return $notification;
			});
		$notification->method($this->anything())->willReturnSelf();
		$notificationManager = $this->createMock(INotificationManager::class);
		$notificationManager->method('createNotification')->willReturn($notification);

		$this->service = new RunwayAlertService($runway, $settingService, $notificationManager);
	}

	private function runsOutOn(string $date): void {
		$this->current['runsOutOn'] = $date;
	}

	public function testFirstRunStoresBaselineSilently(): void {
		$this->assertFalse($this->service->checkAndNotify(self::USER_ID));

		$this->assertSame('2028-07-20', $this->settings[self::KEY]);
		$this->assertSame([], $this->sent);
	}

	public function testOneMonthEarlierNotifiesOnceAndResetsBaseline(): void {
		$this->settings[self::KEY] = '2028-07-20';
		$this->runsOutOn('2028-06-19');

		$this->assertTrue($this->service->checkAndNotify(self::USER_ID));
		$this->assertSame([['runsOutOn' => '2028-06-19', 'previous' => '2028-07-20', 'months' => 1]], $this->sent);
		$this->assertSame(['runway_warning:2028-06-19'], $this->objects);
		$this->assertSame('2028-06-19', $this->settings[self::KEY]);

		$this->assertFalse($this->service->checkAndNotify(self::USER_ID));
		$this->assertCount(1, $this->sent);
	}

	public function testMonthsEarlierIsRounded(): void {
		$this->settings[self::KEY] = '2028-07-20';
		$this->runsOutOn('2028-01-05');

		$this->service->checkAndNotify(self::USER_ID);

		$this->assertSame(6, $this->sent[0]['months']);
	}

	public function testThreeWeeksEarlierDoesNothing(): void {
		$this->settings[self::KEY] = '2028-07-20';
		$this->runsOutOn('2028-06-29');

		$this->assertFalse($this->service->checkAndNotify(self::USER_ID));
		$this->assertSame([], $this->sent);
		$this->assertSame('2028-07-20', $this->settings[self::KEY], 'small slips accumulate against the old baseline');
	}

	public function testLaterDateRaisesBaseline(): void {
		$this->settings[self::KEY] = '2028-07-20';
		$this->runsOutOn('2029-01-01');

		$this->assertFalse($this->service->checkAndNotify(self::USER_ID));
		$this->assertSame('2029-01-01', $this->settings[self::KEY]);

		// and a fall from the improved position is now caught
		$this->runsOutOn('2028-11-30');
		$this->assertTrue($this->service->checkAndNotify(self::USER_ID));
	}

	public function testNotRunningOutClearsBaseline(): void {
		$this->settings[self::KEY] = '2028-07-20';
		$this->current = ['state' => 'not_running_out', 'monthlyDrain' => null, 'months' => null, 'runsOutOn' => null];

		$this->assertFalse($this->service->checkAndNotify(self::USER_ID));
		$this->assertArrayNotHasKey(self::KEY, $this->settings);
	}

	public function testThresholdSettingRespected(): void {
		$this->settings['runway_alert_months'] = '2';
		$this->settings[self::KEY] = '2028-07-20';
		$this->runsOutOn('2028-06-19');
		$this->assertFalse($this->service->checkAndNotify(self::USER_ID));

		$this->runsOutOn('2028-05-19');
		$this->assertTrue($this->service->checkAndNotify(self::USER_ID));
		$this->assertSame(2, $this->sent[0]['months']);
	}

	public function testGarbageThresholdFallsBackToOneAndIsClamped(): void {
		$this->settings[self::KEY] = '2028-07-20';
		$this->settings['runway_alert_months'] = 'soon';
		$this->runsOutOn('2028-06-19');
		$this->assertTrue($this->service->checkAndNotify(self::USER_ID));

		$this->settings['runway_alert_months'] = '99';
		$this->settings[self::KEY] = '2028-07-20';
		$this->runsOutOn('2028-01-15');
		$this->assertTrue($this->service->checkAndNotify(self::USER_ID), 'clamped to 6 months');
		$this->settings[self::KEY] = '2028-07-20';
		$this->runsOutOn('2028-01-25');
		$this->assertFalse($this->service->checkAndNotify(self::USER_ID), '99 is not honoured');
	}
}
