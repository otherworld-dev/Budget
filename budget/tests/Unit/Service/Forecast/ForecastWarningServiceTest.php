<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Forecast;

use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\Forecast\ForecastWarningService;
use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\SettingService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class TestableForecastWarningService extends ForecastWarningService {
    public string $now = '2026-06-15';

    protected function getNow(): \DateTimeImmutable {
        return new \DateTimeImmutable($this->now);
    }
}

class ForecastWarningServiceTest extends TestCase {
    private TestableForecastWarningService $service;

    private array $projections = [];
    private array $settings = [];
    /** @var array[] subject parameters of each notification sent */
    private array $sent = [];

    private const USER_ID = 'alice';

    protected function setUp(): void {
        $forecastService = $this->createMock(ForecastService::class);
        $forecastService->method('getLiveForecast')
            ->willReturnCallback(fn() => ['monthlyProjections' => $this->projections]);

        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')
            ->willReturnCallback(fn(string $userId, string $key) => $this->settings[$key] ?? null);
        $settingService->method('set')
            ->willReturnCallback(function (string $userId, string $key, string $value) {
                $this->settings[$key] = $value;
                return new \OCA\Budget\Db\Setting();
            });

        $amountFormatter = $this->createMock(AmountFormatter::class);
        $amountFormatter->method('formatForUser')->willReturnCallback(fn($u, float $a) => '$' . number_format($a, 2));

        $notification = $this->createMock(INotification::class);
        $notification->method('setSubject')
            ->willReturnCallback(function (string $subject, array $params) use ($notification) {
                $this->sent[] = $params;
                return $notification;
            });
        $notification->method($this->anything())->willReturnSelf();
        $notificationManager = $this->createMock(INotificationManager::class);
        $notificationManager->method('createNotification')->willReturn($notification);

        $this->service = new TestableForecastWarningService(
            $forecastService,
            $settingService,
            $amountFormatter,
            $notificationManager
        );
    }

    /** @param array<string, float> $balanceByMonth */
    private function seedProjections(array $balanceByMonth): void {
        $this->projections = [];
        foreach ($balanceByMonth as $month => $balance) {
            $this->projections[] = ['month' => $month, 'balance' => $balance];
        }
    }

    public function testNotifiesWhenTheForecastDipsBelowZero(): void {
        $this->seedProjections(['Jul 2026' => 500.0, 'Aug 2026' => -120.50]);

        $this->assertTrue($this->service->checkAndNotify(self::USER_ID));
        $this->assertCount(1, $this->sent);
        $this->assertSame('Aug 2026', $this->sent[0]['month']);
    }

    public function testStaysQuietWhileEveryProjectedBalanceIsPositive(): void {
        $this->seedProjections(['Jul 2026' => 500.0, 'Aug 2026' => 250.0]);

        $this->assertFalse($this->service->checkAndNotify(self::USER_ID));
        $this->assertSame([], $this->sent);
    }

    public function testReportsTheFirstMonthThatGoesNegative(): void {
        $this->seedProjections(['Jul 2026' => 10.0, 'Aug 2026' => -50.0, 'Sep 2026' => -400.0]);

        $this->service->checkAndNotify(self::USER_ID);

        $this->assertSame('Aug 2026', $this->sent[0]['month']);
    }

    public function testDoesNotNotifyTwiceInTheSameMonth(): void {
        $this->seedProjections(['Aug 2026' => -120.0]);

        $this->service->checkAndNotify(self::USER_ID);
        $second = $this->service->checkAndNotify(self::USER_ID);

        $this->assertFalse($second);
        $this->assertCount(1, $this->sent);
    }

    public function testNotifiesAgainOnceTheMonthRollsOver(): void {
        $this->seedProjections(['Aug 2026' => -120.0]);
        $this->service->checkAndNotify(self::USER_ID);

        $this->service->now = '2026-07-01';
        $second = $this->service->checkAndNotify(self::USER_ID);

        $this->assertTrue($second);
        $this->assertCount(2, $this->sent);
    }

    /**
     * A balance of exactly zero is not a negative-cash-flow prediction.
     */
    public function testZeroBalanceIsNotAWarning(): void {
        $this->seedProjections(['Jul 2026' => 0.0]);

        $this->assertFalse($this->service->checkAndNotify(self::USER_ID));
    }
}
