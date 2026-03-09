<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\BillReminderJob;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class BillReminderJobTest extends TestCase {
	private BillReminderJob $job;
	private ITimeFactory $timeFactory;
	private BillMapper $billMapper;
	private BillService $billService;
	private INotificationManager $notificationManager;
	private IDBConnection $db;
	private LoggerInterface $logger;
	private SettingService $settingService;

	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->billMapper = $this->createMock(BillMapper::class);
		$this->billService = $this->createMock(BillService::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->settingService = $this->createMock(SettingService::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[BillMapper::class, $this->billMapper],
			[BillService::class, $this->billService],
			[INotificationManager::class, $this->notificationManager],
			[IDBConnection::class, $this->db],
			[LoggerInterface::class, $this->logger],
			[SettingService::class, $this->settingService],
		]);
		\OC::$server = $container;

		$this->job = new BillReminderJob($this->timeFactory);
	}

	protected function tearDown(): void {
		\OC::$server = null;
	}

	// ===== Constructor Config =====

	public function testIntervalIsSixHours(): void {
		$reflection = new \ReflectionProperty($this->job, 'interval');
		$this->assertEquals(6 * 60 * 60, $reflection->getValue($this->job));
	}

	public function testIsNotTimeSensitive(): void {
		$reflection = new \ReflectionProperty($this->job, 'timeSensitivity');
		$this->assertEquals(IJob::TIME_INSENSITIVE, $reflection->getValue($this->job));
	}

	// ===== shouldSendReminder() =====

	public function testShouldSendReminderWhenNoLastReminder(): void {
		$bill = $this->makeBill(['lastReminderSent' => null]);
		$dueDate = new \DateTime('2026-04-15');

		$result = $this->invokeShouldSendReminder($bill, $dueDate);
		$this->assertTrue($result);
	}

	public function testShouldSendReminderWhenLastReminderWasForPreviousCycle(): void {
		$bill = $this->makeBill(['lastReminderSent' => '2026-03-01 10:00:00']);
		$dueDate = new \DateTime('2026-04-15');

		$result = $this->invokeShouldSendReminder($bill, $dueDate);
		$this->assertTrue($result);
	}

	public function testShouldNotSendReminderWhenAlreadySentThisCycle(): void {
		$bill = $this->makeBill(['lastReminderSent' => '2026-04-13 10:00:00']);
		$dueDate = new \DateTime('2026-04-15');

		$result = $this->invokeShouldSendReminder($bill, $dueDate);
		$this->assertFalse($result);
	}

	// ===== formatAmount() =====

	public function testFormatAmountWithUsd(): void {
		$this->settingService->method('get')
			->with('user1', 'currency')
			->willReturn('USD');

		$result = $this->invokeFormatAmount('user1', 15.99);
		$this->assertEquals('$15.99', $result);
	}

	public function testFormatAmountWithEur(): void {
		$this->settingService->method('get')
			->with('user1', 'currency')
			->willReturn('EUR');

		$result = $this->invokeFormatAmount('user1', 100.00);
		$this->assertEquals('€100.00', $result);
	}

	public function testFormatAmountWithGbp(): void {
		$this->settingService->method('get')
			->with('user1', 'currency')
			->willReturn('GBP');

		$result = $this->invokeFormatAmount('user1', 50.50);
		$this->assertEquals('£50.50', $result);
	}

	public function testFormatAmountWithUnknownCurrency(): void {
		$this->settingService->method('get')
			->with('user1', 'currency')
			->willReturn('SEK');

		$result = $this->invokeFormatAmount('user1', 299.00);
		$this->assertEquals('SEK 299.00', $result);
	}

	public function testFormatAmountDefaultsToUsdOnError(): void {
		$this->settingService->method('get')
			->willThrowException(new \RuntimeException('Settings unavailable'));

		$result = $this->invokeFormatAmount('user1', 25.00);
		$this->assertEquals('$25.00', $result);
	}

	// ===== run() - Reminders =====

	public function testRunSendsReminderForBillWithinWindow(): void {
		$this->mockGetAllUserIds(['user1']);
		$this->billMapper->method('findDueForAutoPay')->willReturn([]);

		$tomorrow = (new \DateTime('+1 day'))->format('Y-m-d');
		$bill = $this->makeBill([
			'reminderDays' => 3,
			'nextDueDate' => $tomorrow,
			'lastReminderSent' => null,
		]);

		$this->billMapper->method('findActive')->with('user1')->willReturn([$bill]);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();

		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify');

		$this->billMapper->expects($this->once())->method('update');

		$this->invokeRun();
	}

	public function testRunSkipsBillWithNoReminder(): void {
		$this->mockGetAllUserIds(['user1']);
		$this->billMapper->method('findDueForAutoPay')->willReturn([]);

		$bill = $this->makeBill([
			'reminderDays' => null,
			'nextDueDate' => (new \DateTime('+1 day'))->format('Y-m-d'),
		]);

		$this->billMapper->method('findActive')->with('user1')->willReturn([$bill]);
		$this->notificationManager->expects($this->never())->method('notify');

		$this->invokeRun();
	}

	public function testRunSkipsBillWithNoNextDueDate(): void {
		$this->mockGetAllUserIds(['user1']);
		$this->billMapper->method('findDueForAutoPay')->willReturn([]);

		$bill = $this->makeBill([
			'reminderDays' => 3,
			'nextDueDate' => null,
		]);

		$this->billMapper->method('findActive')->with('user1')->willReturn([$bill]);
		$this->notificationManager->expects($this->never())->method('notify');

		$this->invokeRun();
	}

	public function testRunSendsOverdueNotification(): void {
		$this->mockGetAllUserIds(['user1']);
		$this->billMapper->method('findDueForAutoPay')->willReturn([]);

		$yesterday = (new \DateTime('-1 day'))->format('Y-m-d');
		$bill = $this->makeBill([
			'reminderDays' => 3,
			'nextDueDate' => $yesterday,
			'lastReminderSent' => null,
		]);

		$this->billMapper->method('findActive')->with('user1')->willReturn([$bill]);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->with('bill_overdue', $this->anything())->willReturnSelf();

		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify');

		$this->invokeRun();
	}

	// ===== run() - Auto-Pay =====

	public function testRunProcessesAutoPayBeforeReminders(): void {
		$this->mockGetAllUserIds(['user1']);

		$bill = $this->makeBill(['id' => 10]);

		$this->billMapper->method('findDueForAutoPay')
			->with('user1')
			->willReturn([$bill]);

		$this->billService->method('processAutoPay')
			->with(10, 'user1')
			->willReturn([
				'success' => true,
				'bill' => $bill,
			]);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);

		$this->notificationManager->expects($this->once())->method('notify');

		$this->billMapper->method('findActive')->willReturn([]);

		$this->invokeRun();
	}

	public function testRunSendsAutoPayFailureNotification(): void {
		$this->mockGetAllUserIds(['user1']);

		$bill = $this->makeBill(['id' => 10]);

		$this->billMapper->method('findDueForAutoPay')
			->willReturn([$bill]);

		$this->billService->method('processAutoPay')
			->willReturn([
				'success' => false,
				'message' => 'Insufficient funds',
			]);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->method('setSubject')->with('bill_auto_pay_failed', $this->anything())->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);

		$this->notificationManager->expects($this->once())->method('notify');

		$this->billMapper->method('findActive')->willReturn([]);

		$this->invokeRun();
	}

	// ===== run() - Error Handling =====

	public function testRunContinuesOnPerUserFailure(): void {
		$this->mockGetAllUserIds(['user1', 'user2']);

		$this->billMapper->method('findDueForAutoPay')->willReturn([]);

		$callCount = 0;
		$this->billMapper->method('findActive')
			->willReturnCallback(function () use (&$callCount) {
				$callCount++;
				if ($callCount === 1) {
					throw new \RuntimeException('User error');
				}
				return [];
			});

		$this->logger->expects($this->once())->method('warning');

		$this->invokeRun();
	}

	public function testRunLogsErrorOnTotalFailure(): void {
		$this->db->method('getQueryBuilder')
			->willThrowException(new \RuntimeException('DB down'));

		$this->logger->expects($this->once())
			->method('error')
			->with(
				$this->stringContains('DB down'),
				$this->callback(fn($ctx) => $ctx['app'] === 'budget')
			);

		$this->invokeRun();
	}

	// ===== Helpers =====

	private function makeBill(array $overrides = []): Bill {
		$bill = new Bill();
		$bill->setId($overrides['id'] ?? 1);
		$bill->setUserId($overrides['userId'] ?? 'user1');
		$bill->setName($overrides['name'] ?? 'Netflix');
		$bill->setAmount($overrides['amount'] ?? 15.99);
		$bill->setFrequency($overrides['frequency'] ?? 'monthly');
		$bill->setIsActive($overrides['isActive'] ?? true);
		$bill->setReminderDays($overrides['reminderDays'] ?? null);
		$bill->setNextDueDate($overrides['nextDueDate'] ?? '2099-06-15');
		$bill->setLastReminderSent($overrides['lastReminderSent'] ?? null);
		return $bill;
	}

	private function mockGetAllUserIds(array $userIds): void {
		$rows = array_map(fn($id) => ['user_id' => $id], $userIds);
		$currentIndex = 0;

		$result = $this->createMock(\OCP\DB\IResult::class);
		$result->method('fetch')->willReturnCallback(function () use (&$currentIndex, $rows) {
			return $rows[$currentIndex++] ?? false;
		});
		$result->method('closeCursor');

		$expr = $this->createMock(\OCP\DB\QueryBuilder\IExpressionBuilder::class);
		$expr->method('eq')->willReturn('is_active = 1');

		$qb = $this->createMock(\OCP\DB\QueryBuilder\IQueryBuilder::class);
		$qb->method('selectDistinct')->willReturnSelf();
		$qb->method('from')->willReturnSelf();
		$qb->method('where')->willReturnSelf();
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn('1');
		$qb->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	private function invokeShouldSendReminder($bill, \DateTime $dueDate): bool {
		$method = new \ReflectionMethod($this->job, 'shouldSendReminder');
		return $method->invoke($this->job, $bill, $dueDate);
	}

	private function invokeFormatAmount(string $userId, float $amount): string {
		$method = new \ReflectionMethod($this->job, 'formatAmount');
		return $method->invoke($this->job, $this->settingService, $userId, $amount);
	}

	private function invokeRun(): void {
		$method = new \ReflectionMethod($this->job, 'run');
		$method->invoke($this->job, null);
	}
}
