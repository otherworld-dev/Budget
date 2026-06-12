<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\AnomalyDetectionService;
use OCA\Budget\Service\SettingService;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class TestableAnomalyDetectionService extends AnomalyDetectionService {
    public string $now = '2026-06-20';

    protected function getNow(): \DateTimeImmutable {
        return new \DateTimeImmutable($this->now);
    }
}

class AnomalyDetectionServiceTest extends TestCase {
    private TestableAnomalyDetectionService $service;
    private TransactionMapper $transactionMapper;
    private CategoryMapper $categoryMapper;
    private SettingService $settingService;
    private INotificationManager $notificationManager;

    /** Spending per call: first call = MTD, then prior months 1..6 */
    private array $spendingByCall = [];
    private array $settings = [];
    private int $notificationsSent = 0;

    protected function setUp(): void {
        $this->categoryMapper = $this->createMock(CategoryMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->transactionMapper->method('getCategorySpendingBatch')
            ->willReturnCallback(function () {
                // Consume one entry per call; re-seeding the array restarts the sequence
                return array_shift($this->spendingByCall) ?? [];
            });
        $this->settingService = $this->createMock(SettingService::class);
        $this->settingService->method('get')
            ->willReturnCallback(fn(string $userId, string $key) => $this->settings[$key] ?? null);
        $this->settingService->method('set')
            ->willReturnCallback(function (string $userId, string $key, string $value) {
                $this->settings[$key] = $value;
                return new \OCA\Budget\Db\Setting();
            });
        $amountFormatter = $this->createMock(AmountFormatter::class);
        $amountFormatter->method('formatForUser')->willReturnCallback(fn($u, float $a) => '$' . number_format($a, 2));
        $this->notificationManager = $this->createMock(INotificationManager::class);
        $notification = $this->createMock(INotification::class);
        $notification->method($this->anything())->willReturnSelf();
        $this->notificationManager->method('createNotification')->willReturn($notification);
        $this->notificationManager->method('notify')
            ->willReturnCallback(function () {
                $this->notificationsSent++;
            });

        $this->service = new TestableAnomalyDetectionService(
            $this->categoryMapper,
            $this->transactionMapper,
            $this->settingService,
            $amountFormatter,
            $this->notificationManager
        );

        $category = new Category();
        $category->setId(1);
        $category->setName('Groceries');
        $category->setType('expense');
        $this->categoryMapper->method('findAll')->willReturn([$category]);
    }

    /**
     * Baseline 6 months at 400/month (median 400). On June 20 (day 20 of 30),
     * pro-rated expectation = 400 × 20/30 ≈ 266.67; 30% threshold → 346.67.
     */
    private function seedBaseline(float $mtd, float $monthly = 400.0): void {
        $this->spendingByCall = [[1 => $mtd]];
        for ($i = 0; $i < 6; $i++) {
            $this->spendingByCall[] = [1 => $monthly];
        }
    }

    public function testFlagsClearOverspend(): void {
        $this->seedBaseline(500.0);

        $anomalies = $this->service->detect('alice');

        $this->assertCount(1, $anomalies);
        $this->assertSame('Groceries', $anomalies[0]['categoryName']);
        $this->assertSame(400.0, $anomalies[0]['baseline']);
        $this->assertGreaterThan(30, $anomalies[0]['percentAbove']);
    }

    public function testWithinThresholdNotFlagged(): void {
        $this->seedBaseline(300.0); // under the 346.67 limit

        $this->assertCount(0, $this->service->detect('alice'));
    }

    public function testEarlyMonthGuard(): void {
        $this->service->now = '2026-06-05'; // before day 10
        $this->seedBaseline(5000.0);

        $this->assertCount(0, $this->service->detect('alice'));
    }

    public function testMinAmountFloor(): void {
        // €12 against a €10 typical is +100% but trivial — below the floor
        $this->seedBaseline(12.0, 10.0);

        $this->assertCount(0, $this->service->detect('alice'));
    }

    public function testInsufficientHistorySkipped(): void {
        // Only 2 non-zero history months
        $this->spendingByCall = [[1 => 500.0], [1 => 400.0], [1 => 400.0], [], [], [], []];

        $this->assertCount(0, $this->service->detect('alice'));
    }

    public function testMedianResistsSpikes(): void {
        // One holiday month at 2000 doesn't poison the baseline
        $this->spendingByCall = [[1 => 500.0]];
        foreach ([400.0, 380.0, 2000.0, 420.0, 410.0, 390.0] as $monthly) {
            $this->spendingByCall[] = [1 => $monthly];
        }

        $anomalies = $this->service->detect('alice');

        $this->assertCount(1, $anomalies);
        $this->assertSame(405.0, $anomalies[0]['baseline']); // median of sorted set
    }

    public function testNotificationSuppressedWithinSameMonth(): void {
        $this->seedBaseline(500.0);
        $this->service->detectAndNotify('alice');
        $this->assertSame(1, $this->notificationsSent);

        // Same month, second run: anomaly still reported, no second notification
        $callReset = [[1 => 520.0]];
        for ($i = 0; $i < 6; $i++) {
            $callReset[] = [1 => 400.0];
        }
        $this->spendingByCall = $callReset;
        $anomalies = $this->service->detectAndNotify('alice');

        $this->assertCount(1, $anomalies);
        $this->assertSame(1, $this->notificationsSent);
    }

    public function testIncomeCategoriesIgnored(): void {
        $income = new Category();
        $income->setId(2);
        $income->setName('Salary');
        $income->setType('income');
        $categoryMapper = $this->createMock(CategoryMapper::class);
        $categoryMapper->method('findAll')->willReturn([$income]);

        $service = new TestableAnomalyDetectionService(
            $categoryMapper,
            $this->transactionMapper,
            $this->settingService,
            $this->createMock(AmountFormatter::class),
            $this->notificationManager
        );
        $this->seedBaseline(5000.0);

        $this->assertCount(0, $service->detect('alice'));
    }
}
