<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\BillReminderJob;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\PensionRecurringService;
use OCA\Budget\Service\RecurringIncomeService;
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
	private RecurringIncomeMapper $incomeMapper;
	private RecurringIncomeService $incomeService;
	private INotificationManager $notificationManager;
	private IDBConnection $db;
	private LoggerInterface $logger;
	private SettingService $settingService;
	/** @var PensionRecurringContributionMapper&\PHPUnit\Framework\MockObject\MockObject */
	private $pensionRecurMapper;
	/** @var PensionRecurringService&\PHPUnit\Framework\MockObject\MockObject */
	private $pensionRecurService;
	/** @var \OCA\Budget\Db\RecurringIncome[] what findDueForAutoCreate returns */
	private array $dueIncome = [];

	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->billMapper = $this->createMock(BillMapper::class);
		$this->billService = $this->createMock(BillService::class);
		$this->incomeMapper = $this->createMock(RecurringIncomeMapper::class);
		$this->incomeService = $this->createMock(RecurringIncomeService::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->settingService = $this->createMock(SettingService::class);
		$this->pensionRecurMapper = $this->createMock(PensionRecurringContributionMapper::class);
		$this->pensionRecurService = $this->createMock(PensionRecurringService::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[BillMapper::class, $this->billMapper],
			[BillService::class, $this->billService],
			[RecurringIncomeMapper::class, $this->incomeMapper],
			[RecurringIncomeService::class, $this->incomeService],
			[PensionRecurringContributionMapper::class, $this->pensionRecurMapper],
			[PensionRecurringService::class, $this->pensionRecurService],
			[INotificationManager::class, $this->notificationManager],
			[IDBConnection::class, $this->db],
			[LoggerInterface::class, $this->logger],
			[SettingService::class, $this->settingService],
		]);
		\OC::$server = $container;

		// Default: no income due for auto-create, no pension schedules due
		$this->incomeMapper->method('findDueForAutoCreate')->willReturnCallback(fn () => $this->dueIncome);
		$this->pensionRecurMapper->method('findDueForAutoPost')->willReturn([]);

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

	public function testALongReminderWindowRemindsOnce(): void {
		// "More than a week before the due date" read every reminder sent
		// 8 to 30 days out as an old one, and resent it every six hours
		$bill = $this->makeBill(['reminderDays' => 20, 'lastReminderSent' => '2026-03-26 10:00:00']);

		$this->assertFalse($this->invokeShouldSendReminder($bill, new \DateTime('2026-04-15')));
	}

	public function testTheOverdueNoticeFollowsAReminder(): void {
		// A reminder two days before the due date suppressed the overdue
		// notice for good
		$bill = $this->makeBill(['reminderDays' => 3, 'lastReminderSent' => '2026-04-13 10:00:00']);

		$this->assertTrue($this->invokeShouldSendReminder($bill, new \DateTime('2026-04-15'), true));
	}

	public function testTheOverdueNoticeIsSentOnce(): void {
		$bill = $this->makeBill(['reminderDays' => 3, 'lastReminderSent' => '2026-04-16 10:00:00']);

		$this->assertFalse($this->invokeShouldSendReminder($bill, new \DateTime('2026-04-15'), true));
	}

	public function testAWeeklyBillIsRemindedEveryWeek(): void {
		$bill = $this->makeBill(['reminderDays' => 2, 'lastReminderSent' => '2026-04-06 10:00:00']);

		$this->assertTrue($this->invokeShouldSendReminder($bill, new \DateTime('2026-04-15')));
	}

	public function testAReminderSentAfterMidnightEastOfUtcGoesOutOnce(): void {
		// Sent at 01:00 on 5 October in Sydney, stamped 14:00 on 4 October
		// UTC. Read as a UTC date it was older than the window opening on the
		// 5th, and every run that day sent it again
		$bill = $this->makeBill(['reminderDays' => 3, 'lastReminderSent' => '2026-10-04 14:00:00']);

		$this->assertFalse($this->invokeShouldSendReminder($bill, new \DateTime('2026-10-08'), false, 'Australia/Sydney'));
	}

	public function testAnOverdueNoticeSentAfterMidnightEastOfUtcGoesOutOnce(): void {
		$bill = $this->makeBill(['reminderDays' => 3, 'lastReminderSent' => '2026-10-04 14:00:00']);

		$this->assertFalse($this->invokeShouldSendReminder($bill, new \DateTime('2026-10-04'), true, 'Australia/Sydney'));
	}

	public function testALatePaymentDoesNotSwallowTheNextReminder(): void {
		// A weekly bill reminded a week ahead: the overdue notice for 4
		// October went out on the 5th, inside the next occurrence's window,
		// and the reminder for the 11th was never sent
		$bill = $this->makeBill(['reminderDays' => 7, 'nextDueDate' => '2026-10-11',
			'lastReminderSent' => '2026-10-05 08:00:00', 'lastReminderDue' => '2026-10-04']);

		$this->assertTrue($this->invokeShouldSendReminder($bill, new \DateTime('2026-10-11')));
	}

	public function testEachNoticeGoesOutOncePerDueDate(): void {
		// The reminder for 15 October went out on the 13th
		$bill = $this->makeBill(['reminderDays' => 3, 'lastReminderSent' => '2026-10-13 08:00:00', 'lastReminderDue' => '2026-10-15']);
		$this->assertFalse($this->invokeShouldSendReminder($bill, new \DateTime('2026-10-15')));
		// ...even after the window is shortened to one day
		$bill->setReminderDays(1);
		$this->assertFalse($this->invokeShouldSendReminder($bill, new \DateTime('2026-10-15')));
		// The overdue notice still follows it, once
		$this->assertTrue($this->invokeShouldSendReminder($bill, new \DateTime('2026-10-15'), true));
		$bill->setLastReminderSent('2026-10-16 08:00:00');
		$this->assertFalse($this->invokeShouldSendReminder($bill, new \DateTime('2026-10-15'), true));
	}

	public function testANoticeRecordsTheDueDateItWasFor(): void {
		$this->mockGetAllUserIds(['user1']);
		$this->billMapper->method('findDueForAutoPay')->willReturn([]);
		$tomorrow = (new \DateTime('+1 day'))->format('Y-m-d');
		$bill = $this->makeBill(['reminderDays' => 3, 'nextDueDate' => $tomorrow]);
		$this->billMapper->method('findActive')->willReturn([$bill]);
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $method) {
			$notification->method($method)->willReturnSelf();
		}
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->billMapper->expects($this->once())->method('update')
			->with($this->callback(fn (Bill $b) => $b->getLastReminderDue() === $tomorrow && $b->getLastReminderSent() !== null));

		$this->invokeRun();
	}

	// ===== formatAmount() =====

	public function testFormatAmountWithUsd(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('USD');

		$result = $this->invokeFormatAmount('user1', 15.99);
		$this->assertEquals('$15.99', $result);
	}

	public function testFormatAmountWithEur(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('EUR');

		$result = $this->invokeFormatAmount('user1', 100.00);
		$this->assertEquals('€100.00', $result);
	}

	public function testFormatAmountWithGbp(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('GBP');

		$result = $this->invokeFormatAmount('user1', 50.50);
		$this->assertEquals('£50.50', $result);
	}

	public function testFormatAmountWithUnknownCurrency(): void {
		$this->settingService->method('get')
			->with('user1', 'default_currency')
			->willReturn('SEK');

		$result = $this->invokeFormatAmount('user1', 299.00);
		$this->assertEquals('SEK 299.00', $result);
	}

	public function testFormatAmountDefaultsToGbpOnError(): void {
		$this->settingService->method('get')
			->willThrowException(new \RuntimeException('Settings unavailable'));

		$result = $this->invokeFormatAmount('user1', 25.00);
		$this->assertEquals('£25.00', $result);
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

	/**
	 * A reminder for a bill on a dollar account said £50.00: the bills were
	 * never given their account's currency, and the amount was formatted in
	 * the user's default one.
	 */
	public function testRemindersAndAutoPayNoticesUseTheBillsCurrency(): void {
		$this->mockGetAllUserIds(['user1']);
		$this->settingService->method('get')->willReturn('GBP');
		$tomorrow = (new \DateTime('+1 day'))->format('Y-m-d');
		$reminded = $this->makeBill(['id' => 1, 'amount' => 50.0, 'reminderDays' => 3, 'nextDueDate' => $tomorrow]);
		$autoPaid = $this->makeBill(['id' => 2, 'amount' => 20.0]);
		$paidCopy = $this->makeBill(['id' => 2, 'amount' => 20.0]);
		$this->billMapper->method('findActive')->willReturn([$reminded]);
		$this->billMapper->method('findDueForAutoPay')->willReturn([$autoPaid]);
		$this->billService->method('processAutoPay')->willReturn(['success' => true, 'bill' => $paidCopy]);
		$this->billService->method('enrichBillsWithCurrency')->willReturnCallback(function (array $bills) {
			foreach ($bills as $bill) {
				$bill->setCurrency('USD');
			}
			return $bills;
		});

		$amounts = [];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $method) {
			$notification->method($method)->willReturnSelf();
		}
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $params) use (&$amounts, $notification) {
			$amounts[$subject] = $params['amount'];
			return $notification;
		});
		$this->notificationManager->method('createNotification')->willReturn($notification);

		$this->invokeRun();

		$this->assertSame(['bill_auto_paid' => '$20.00', 'bill_reminder' => '$50.00'], $amounts);
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

	public function testAnAutoPayAnotherRunAlreadyMadeSendsNothing(): void {
		// Two runs at once: the second found nothing left to pay and told the
		// user auto-pay had failed
		$this->mockGetAllUserIds(['user1']);
		$this->billMapper->method('findDueForAutoPay')->willReturn([$this->makeBill(['id' => 10])]);
		$this->billService->method('processAutoPay')->willReturn([
			'success' => false, 'disabled' => false, 'message' => 'Nothing due', 'bill' => null,
		]);
		$this->billMapper->method('findActive')->willReturn([]);
		$this->notificationManager->expects($this->never())->method('notify');

		$this->invokeRun();
	}

	public function testAnIncomeAutoCreateWithNothingDueSendsNothing(): void {
		$this->mockGetAllUserIds(['user1']);
		$this->billMapper->method('findDueForAutoPay')->willReturn([]);
		$this->billMapper->method('findActive')->willReturn([]);
		$income = new \OCA\Budget\Db\RecurringIncome();
		$income->setId(4);
		$this->dueIncome = [$income];
		$this->incomeService->method('processAutoCreate')->willReturn([
			'success' => false, 'disabled' => false, 'message' => 'Nothing due', 'income' => $income,
		]);
		$this->notificationManager->expects($this->never())->method('notify');

		$this->invokeRun();
	}

	public function testAnIncomeAutoCreateThatSwitchedItselfOffSaysSo(): void {
		$this->mockGetAllUserIds(['user1']);
		$this->billMapper->method('findDueForAutoPay')->willReturn([]);
		$this->billMapper->method('findActive')->willReturn([]);
		$income = new \OCA\Budget\Db\RecurringIncome();
		$income->setId(4);
		$income->setName('Wages');
		$income->setAmount(100.0);
		$this->dueIncome = [$income];
		$this->incomeService->method('processAutoCreate')->willReturn([
			'success' => false, 'disabled' => true, 'message' => 'No account set for income', 'income' => $income,
		]);
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $method) {
			$notification->method($method)->willReturnSelf();
		}
		$notification->method('setSubject')->with('income_auto_create_failed', $this->anything())->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify');

		$this->invokeRun();
	}

	/**
	 * A failure while looking up or paying a user's auto-pay bills is logged
	 * and the run carries on to the reminders. The catch used to call an
	 * undefined $logger, so the "log it" path crashed the whole job instead.
	 */
	public function testRunLogsAutoPayFailureAndStillSendsReminders(): void {
		$this->mockGetAllUserIds(['user1']);

		$this->billMapper->method('findDueForAutoPay')
			->willThrowException(new \RuntimeException('Auto-pay lookup failed'));

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				$this->logicalAnd(
					$this->stringContains('Auto-pay processing failed for user user1'),
					$this->stringContains('Auto-pay lookup failed')
				),
				$this->callback(fn ($ctx) => ($ctx['app'] ?? null) === 'budget')
			);
		$this->logger->expects($this->never())->method('error');

		// The reminder pass for the same user still runs
		$this->billMapper->expects($this->once())->method('findActive')->with('user1')->willReturn([]);

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
				$this->callback(fn ($ctx) => $ctx['app'] === 'budget')
			);

		$this->invokeRun();
	}

	// ===== Helpers =====

	/**
	 * A pension auto-post that can't post switches itself off and says so.
	 * It used to log a warning and fail again every six hours, with the
	 * schedule still showing a normal next date.
	 */
	public function testAPensionAutoPostThatSwitchedItselfOffTellsTheUser(): void {
		$this->mockGetAllUserIds(['user1']);
		$schedule = new \OCA\Budget\Db\PensionRecurringContribution();
		$schedule->setId(7);
		$schedule->setPensionId(2);
		$schedule->setAmount(200.0);
		$this->pensionRecurService->method('findDueForAutoPost')->with('user1')->willReturn([$schedule]);
		$this->pensionRecurService->method('processAutoPost')->willReturn([
			'success' => false,
			'disabled' => true,
			'recurring' => $schedule,
			'pensionName' => 'Work',
			'message' => 'The account this contribution comes from no longer exists',
		]);

		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->willReturnSelf();
		$notification->method('setUser')->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setObject')->willReturnSelf();
		$notification->expects($this->once())->method('setSubject')
			->with('pension_auto_post_failed', $this->callback(function (array $params) {
				return $params['pensionName'] === 'Work'
					&& $params['recurringId'] === 7
					&& $params['reason'] === 'The account this contribution comes from no longer exists';
			}))
			->willReturnSelf();
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->notificationManager->expects($this->once())->method('notify');
		$this->billMapper->method('findActive')->willReturn([]);

		$this->invokeRun();
	}

	public function testAPensionAutoPostFailureShowsThePensionsCurrency(): void {
		// A euro pension's contribution read "£75.00" for a pound user
		$this->mockGetAllUserIds(['user1']);
		$this->settingService->method('get')->willReturn('GBP');
		$schedule = new \OCA\Budget\Db\PensionRecurringContribution();
		$schedule->setId(7);
		$schedule->setPensionId(2);
		$schedule->setAmount(75.0);
		$this->pensionRecurService->method('findDueForAutoPost')->willReturn([$schedule]);
		$this->pensionRecurService->method('processAutoPost')->willReturn([
			'success' => false, 'disabled' => true, 'recurring' => $schedule, 'pensionName' => 'Euro pot',
			'pensionCurrency' => 'EUR', 'message' => 'The account this contribution comes from no longer exists',
		]);
		$amount = null;
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject'] as $method) {
			$notification->method($method)->willReturnSelf();
		}
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $params) use (&$amount, $notification) {
			$amount = $params['amount'];
			return $notification;
		});
		$this->notificationManager->method('createNotification')->willReturn($notification);
		$this->billMapper->method('findActive')->willReturn([]);

		$this->invokeRun();

		$this->assertSame('€75.00', $amount);
	}

	public function testAPensionScheduleWithNothingDueSendsNothing(): void {
		$this->mockGetAllUserIds(['user1']);
		$schedule = new \OCA\Budget\Db\PensionRecurringContribution();
		$schedule->setId(7);
		$this->pensionRecurService->method('findDueForAutoPost')->willReturn([$schedule]);
		$this->pensionRecurService->method('processAutoPost')->willReturn([
			'success' => false, 'disabled' => false, 'message' => 'Nothing due',
		]);
		$this->notificationManager->expects($this->never())->method('notify');
		$this->billMapper->method('findActive')->willReturn([]);

		$this->invokeRun();
	}

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
		if (isset($overrides['lastReminderDue'])) {
			$bill->setLastReminderDue($overrides['lastReminderDue']);
		}
		return $bill;
	}

	private function mockGetAllUserIds(array $userIds): void {
		$rows = array_map(fn ($id) => ['user_id' => $id], $userIds);
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

	private function invokeShouldSendReminder($bill, \DateTime $dueDate, bool $overdue = false, string $zone = 'UTC'): bool {
		$method = new \ReflectionMethod($this->job, 'shouldSendReminder');
		return $method->invoke($this->job, $bill, $dueDate, $overdue, new \DateTimeZone($zone));
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
