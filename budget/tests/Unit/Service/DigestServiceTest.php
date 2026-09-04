<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\AnomalyDetectionService;
use OCA\Budget\Service\Bill\BillSuggestionService;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\DigestService;
use OCA\Budget\Service\GoalsService;
use OCA\Budget\Service\Mail\BudgetMailService;
use OCA\Budget\Service\SettingService;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class TestableDigestService extends DigestService {
    public string $now = '2026-06-03'; // a Wednesday

    protected function getNow(): \DateTimeImmutable {
        return new \DateTimeImmutable($this->now);
    }
}

class DigestServiceTest extends TestCase {
    private TestableDigestService $service;
    private AnomalyDetectionService $anomalyService;

    /** [$start, $end] the service asked anomalies for */
    private array $periodAsked = [];
    private array $subjectParameters = [];

    private const PERIOD_ANOMALY = [
        'categoryId' => 1,
        'categoryName' => 'FromPeriod',
        'mtdSpend' => 600.0,
        'baseline' => 400.0,
        'percentAbove' => 50,
    ];
    private const LIVE_ANOMALY = [
        'categoryId' => 2,
        'categoryName' => 'FromLiveMtd',
        'mtdSpend' => 90.0,
        'baseline' => 50.0,
        'percentAbove' => 80,
    ];

    protected function setUp(): void {
        $budgetAlertService = $this->createMock(BudgetAlertService::class);
        $budgetAlertService->method('getSummary')->willReturn([
            'totalCategories' => 0, 'totalBudget' => 0, 'totalSpent' => 0,
            'totalRemaining' => 0, 'overallPercentage' => 0,
            'overBudgetCount' => 0, 'warningCount' => 0, 'onTrackCount' => 0,
        ]);

        $billService = $this->createMock(BillService::class);
        $billService->method('findUpcoming')->willReturn([]);
        $billService->method('enrichBillsWithCurrency')->willReturn([]);

        $goalsService = $this->createMock(GoalsService::class);
        $goalsService->method('findAll')->willReturn([]);

        // The two sources are deliberately distinguishable: a digest that
        // reaches for live month-to-date figures returns the wrong one.
        $this->anomalyService = $this->createMock(AnomalyDetectionService::class);
        $this->anomalyService->method('detectForPeriod')
            ->willReturnCallback(function (string $userId, string $start, string $end) {
                $this->periodAsked = [$start, $end];
                return [self::PERIOD_ANOMALY];
            });
        $this->anomalyService->method('detect')->willReturn([self::LIVE_ANOMALY]);

        $suggestionService = $this->createMock(BillSuggestionService::class);
        $suggestionService->method('countSuggestions')->willReturn(0);

        $amountFormatter = $this->createMock(AmountFormatter::class);
        $amountFormatter->method('formatForUser')->willReturnCallback(fn($u, float $a) => '$' . number_format($a, 2));

        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturn(null);

        $notification = $this->createMock(INotification::class);
        $notification->method($this->anything())->willReturnSelf();
        $notification->method('setSubject')
            ->willReturnCallback(function (string $subject, array $params) use ($notification) {
                $this->subjectParameters = $params;
                return $notification;
            });
        $notificationManager = $this->createMock(INotificationManager::class);
        $notificationManager->method('createNotification')->willReturn($notification);

        $transactionMapper = $this->createMock(TransactionMapper::class);
        $transactionMapper->method('getAccountSummaries')->willReturn([
            ['income' => 3000.0, 'expenses' => 2000.0],
        ]);

        $this->service = new TestableDigestService(
            $budgetAlertService,
            $billService,
            $goalsService,
            $this->anomalyService,
            $suggestionService,
            $amountFormatter,
            $settingService,
            $this->createMock(BudgetMailService::class),
            $notificationManager,
            $this->createMock(IFactory::class),
            $transactionMapper
        );
    }

    public function testWeeklyDigestCoversLastMondayToSunday(): void {
        $digest = $this->service->buildDigest('alice', 'weekly');

        $this->assertSame('2026-05-25', $digest['periodStart']);
        $this->assertSame('2026-05-31', $digest['periodEnd']);
    }

    public function testMonthlyDigestCoversLastCalendarMonth(): void {
        $digest = $this->service->buildDigest('alice', 'monthly');

        $this->assertSame('2026-05-01', $digest['periodStart']);
        $this->assertSame('2026-05-31', $digest['periodEnd']);
    }

    public function testWeeklyDigestReportsAnomaliesForItsOwnPeriod(): void {
        $digest = $this->service->buildDigest('alice', 'weekly');

        $this->assertSame(['2026-05-25', '2026-05-31'], $this->periodAsked);
        $this->assertSame('FromPeriod', $digest['anomalies'][0]['categoryName']);
    }

    /**
     * The bug this fixes: a monthly digest is sent on the 1st, and live
     * month-to-date detection is blind before day 10, so 'anomalies' was
     * always empty for monthly subscribers.
     */
    public function testMonthlyDigestReportsAnomaliesForItsOwnPeriod(): void {
        $this->service->now = '2026-06-01';

        $digest = $this->service->buildDigest('alice', 'monthly');

        $this->assertSame(['2026-05-01', '2026-05-31'], $this->periodAsked);
        $this->assertCount(1, $digest['anomalies']);
        $this->assertSame('FromPeriod', $digest['anomalies'][0]['categoryName']);
    }

    public function testDigestNotificationCarriesTheAnomalyCount(): void {
        $this->service->sendDigest('alice', 'weekly');

        $this->assertSame('1', $this->subjectParameters['anomalyCount']);
    }
}
