<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\InterestAccrualJob;
use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\InterestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class InterestAccrualJobTest extends TestCase {
    private InterestAccrualJob $job;
    private ITimeFactory $timeFactory;
    private InterestService $interestService;
    private AccountMapper $accountMapper;
    private IDBConnection $db;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->timeFactory = $this->createMock(ITimeFactory::class);
        $this->interestService = $this->createMock(InterestService::class);
        $this->accountMapper = $this->createMock(AccountMapper::class);
        $this->db = $this->createMock(IDBConnection::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturnMap([
            [InterestService::class, $this->interestService],
            [AccountMapper::class, $this->accountMapper],
            [IDBConnection::class, $this->db],
            [LoggerInterface::class, $this->logger],
        ]);
        \OC::$server = $container;

        $this->job = new InterestAccrualJob($this->timeFactory);
    }

    protected function tearDown(): void {
        \OC::$server = null;
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

    public function testRunCompletesWithNoUsers(): void {
        $this->mockGetAllUserIds([]);

        $this->accountMapper->expects($this->never())->method('findWithInterestEnabled');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('0 accounts updated'),
                $this->callback(fn($ctx) => $ctx['app'] === 'budget')
            );

        $this->invokeRun();
    }

    public function testRunRefreshesEachAccountForEachUser(): void {
        $this->mockGetAllUserIds(['user1', 'user2']);

        $account1 = $this->makeAccount(10, 'user1');
        $account2 = $this->makeAccount(20, 'user1');
        $account3 = $this->makeAccount(30, 'user2');

        $this->accountMapper->method('findWithInterestEnabled')
            ->willReturnCallback(function (string $userId) use ($account1, $account2, $account3) {
                return $userId === 'user1' ? [$account1, $account2] : [$account3];
            });

        $this->interestService->expects($this->exactly(3))
            ->method('refreshAccruedInterestCache');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('3 accounts updated'),
                $this->anything()
            );

        $this->invokeRun();
    }

    public function testRunContinuesOnPerAccountError(): void {
        $this->mockGetAllUserIds(['user1']);

        $account1 = $this->makeAccount(10, 'user1');
        $account2 = $this->makeAccount(20, 'user1');

        $this->accountMapper->method('findWithInterestEnabled')->willReturn([$account1, $account2]);

        $callCount = 0;
        $this->interestService->method('refreshAccruedInterestCache')
            ->willReturnCallback(function () use (&$callCount): string {
                $callCount++;
                if ($callCount === 1) {
                    throw new \RuntimeException('Rate calculation error');
                }
                return '0.00';
            });

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Failed to refresh interest for account 10'),
                $this->anything()
            );

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('1 accounts updated'),
                $this->anything()
            );

        $this->invokeRun();
    }

    public function testRunLogsCompletionWithErrorCount(): void {
        $this->mockGetAllUserIds(['user1']);

        $this->accountMapper->method('findWithInterestEnabled')
            ->willThrowException(new \RuntimeException('DB error'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Failed to process interest for user user1'),
                $this->anything()
            );

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('1 errors'),
                $this->anything()
            );

        $this->invokeRun();
    }

    // ===== Helpers =====

    private function makeAccount(int $id, string $userId): Account {
        $account = new Account();
        $account->setId($id);
        $account->setUserId($userId);
        $account->setName('Test Account ' . $id);
        return $account;
    }

    private function mockGetAllUserIds(array $userIds): void {
        $rows = array_map(fn($id) => ['user_id' => $id], $userIds);
        $currentIndex = 0;

        $result = $this->createMock(IResult::class);
        $result->method('fetch')->willReturnCallback(function () use (&$currentIndex, $rows) {
            return $rows[$currentIndex++] ?? false;
        });
        $result->method('closeCursor');

        $expr = $this->createMock(IExpressionBuilder::class);

        $qb = $this->createMock(IQueryBuilder::class);
        $qb->method('selectDistinct')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn(':param');
        $qb->method('executeQuery')->willReturn($result);

        $this->db->method('getQueryBuilder')->willReturn($qb);
    }

    private function invokeRun(): void {
        $method = new \ReflectionMethod($this->job, 'run');
        $method->invoke($this->job, null);
    }
}
