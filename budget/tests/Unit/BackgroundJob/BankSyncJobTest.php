<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\BankSyncJob;
use OCA\Budget\Db\BankConnection;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class BankSyncJobTest extends TestCase {
    private BankSyncJob $job;
    private ITimeFactory $timeFactory;
    private AdminSettingService $adminSettings;
    private BankSyncService $syncService;
    private BankConnectionMapper $connectionMapper;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->adminSettings = $this->createMock(AdminSettingService::class);
        $this->syncService = $this->createMock(BankSyncService::class);
        $this->connectionMapper = $this->createMock(BankConnectionMapper::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->job = new BankSyncJob(
            $this->timeFactory,
            $this->adminSettings,
            $this->syncService,
            $this->connectionMapper,
            $this->logger
        );
    }

    // ===== Constructor Config =====

    public function testIntervalIsTwentyFourHours(): void {
        $reflection = new \ReflectionProperty($this->job, 'interval');
        $this->assertEquals(24 * 60 * 60, $reflection->getValue($this->job));
    }

    public function testIsNotTimeSensitive(): void {
        $reflection = new \ReflectionProperty($this->job, 'timeSensitivity');
        $this->assertEquals(IJob::TIME_INSENSITIVE, $reflection->getValue($this->job));
    }

    // ===== run() =====

    public function testRunReturnsEarlyWhenBankSyncDisabled(): void {
        $this->adminSettings->method('isBankSyncEnabled')->willReturn(false);

        $this->connectionMapper->expects($this->never())->method('findActiveIdsForSync');
        $this->syncService->expects($this->never())->method('sync');

        $this->invokeRun();
    }

    public function testRunCompletesWithNoConnections(): void {
        $this->adminSettings->method('isBankSyncEnabled')->willReturn(true);
        $this->connectionMapper->method('findActiveIdsForSync')->willReturn([]);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('0 connections synced'),
                $this->callback(fn($ctx) => $ctx['app'] === 'budget')
            );

        $this->invokeRun();
    }

    public function testRunSyncsEachActiveConnection(): void {
        $this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

        $this->connectionMapper->method('findActiveIdsForSync')->willReturn([
            ['id' => 1, 'userId' => 'user1'],
            ['id' => 2, 'userId' => 'user2'],
        ]);

        $this->syncService->expects($this->exactly(2))
            ->method('sync')
            ->willReturnCallback(function (string $userId, int $connId) {
                $this->assertContains($userId, ['user1', 'user2']);
                $this->assertContains($connId, [1, 2]);
                return ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'accounts' => []];
            });

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('2 connections synced'),
                $this->anything()
            );

        $this->invokeRun();
    }

    public function testRunContinuesOnIndividualSyncFailure(): void {
        $this->adminSettings->method('isBankSyncEnabled')->willReturn(true);

        $this->connectionMapper->method('findActiveIdsForSync')->willReturn([
            ['id' => 1, 'userId' => 'user1'],
            ['id' => 2, 'userId' => 'user2'],
        ]);

        $callCount = 0;
        $this->syncService->method('sync')
            ->willReturnCallback(function () use (&$callCount): array {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('API timeout');
                }
                return ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'accounts' => []];
            });

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Bank sync failed for connection 1'),
                $this->anything()
            );

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('1 connections synced'),
                $this->anything()
            );

        $this->invokeRun();
    }

    // ===== Helpers =====

    private function makeConnection(int $id, string $userId): BankConnection {
        $conn = new BankConnection();
        $conn->setId($id);
        $conn->setUserId($userId);
        $conn->setProvider('gocardless');
        $conn->setName('Test Bank');
        $conn->setStatus('active');
        return $conn;
    }

    private function invokeRun(): void {
        $method = new \ReflectionMethod($this->job, 'run');
        $method->invoke($this->job, null);
    }
}
