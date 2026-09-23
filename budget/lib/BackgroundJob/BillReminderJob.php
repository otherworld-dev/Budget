<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\BackgroundJob\Support\JobUsers;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\PensionRecurringContributionMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\PensionRecurringService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Background job to send bill reminders as Nextcloud notifications.
 *
 * Runs every 6 hours and checks for bills that:
 * 1. Have reminders enabled (reminder_days is set)
 * 2. Are due within the reminder window
 * 3. Haven't had a reminder sent for this due date yet
 */
class BillReminderJob extends TimedJob {
	public function __construct(ITimeFactory $time) {
		parent::__construct($time);

		// Run every 6 hours
		$this->setInterval(6 * 60 * 60);
		$this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$billMapper = Server::get(BillMapper::class);
		$billService = Server::get(BillService::class);
		$incomeMapper = Server::get(RecurringIncomeMapper::class);
		$incomeService = Server::get(RecurringIncomeService::class);
		$pensionRecurMapper = Server::get(PensionRecurringContributionMapper::class);
		$pensionRecurService = Server::get(PensionRecurringService::class);
		$notificationManager = Server::get(INotificationManager::class);
		$db = Server::get(IDBConnection::class);
		$logger = Server::get(LoggerInterface::class);
		$settingService = Server::get(SettingService::class);

		try {
			$userIds = $this->getAllUserIds($db);
			$notificationCount = 0;
			$autoPayCount = 0;
			$autoPayFailedCount = 0;
			$autoCreateIncomeCount = 0;
			$autoCreateIncomeFailedCount = 0;
			$pensionPostCount = 0;
			$pensionPostFailedCount = 0;
			$today = new \DateTime();
			$today->setTime(0, 0, 0);

			foreach ($userIds as $userId) {
				try {
					// Process auto-pay BEFORE reminders to avoid sending reminder for auto-paid bill
					$autoPay = $this->processAutoPayForUser($userId, $billMapper, $billService, $notificationManager, $settingService, $logger);
					$autoPayCount += $autoPay['success'];
					$autoPayFailedCount += $autoPay['failed'];

					// Process auto-create for recurring income
					$autoCreate = $this->processAutoCreateIncomeForUser($userId, $incomeMapper, $incomeService, $notificationManager, $settingService, $logger);
					$autoCreateIncomeCount += $autoCreate['success'];
					$autoCreateIncomeFailedCount += $autoCreate['failed'];

					// Process auto-post for recurring pension contributions (#251)
					$pensionPost = $this->processAutoPostPensionsForUser($userId, $pensionRecurMapper, $pensionRecurService, $logger);
					$pensionPostCount += $pensionPost['success'];
					$pensionPostFailedCount += $pensionPost['failed'];

					$bills = $billMapper->findActive($userId);

					foreach ($bills as $bill) {
						// Skip if no reminder configured
						if ($bill->getReminderDays() === null) {
							continue;
						}

						$nextDueDate = $bill->getNextDueDate();
						if (!$nextDueDate) {
							continue;
						}

						$dueDate = new \DateTime($nextDueDate);
						$dueDate->setTime(0, 0, 0);

						$daysUntilDue = (int)$today->diff($dueDate)->format('%R%a');

						// Check if we should send a reminder
						if ($daysUntilDue < 0) {
							// Bill is overdue - send overdue notification if not already sent
							if ($this->shouldSendReminder($bill, $dueDate)) {
								$this->sendOverdueNotification($notificationManager, $settingService, $userId, $bill, $daysUntilDue);
								$this->markReminderSent($billMapper, $bill);
								$notificationCount++;
							}
						} elseif ($daysUntilDue <= $bill->getReminderDays()) {
							// Within reminder window
							if ($this->shouldSendReminder($bill, $dueDate)) {
								$this->sendReminderNotification($notificationManager, $settingService, $userId, $bill, $daysUntilDue);
								$this->markReminderSent($billMapper, $bill);
								$notificationCount++;
							}
						}
					}
				} catch (\Exception $e) {
					$logger->warning(
						"Failed to process bill reminders for user {$userId}: " . $e->getMessage(),
						['app' => 'budget', 'userId' => $userId]
					);
				}
			}

			if ($notificationCount > 0 || $autoPayCount > 0 || $autoCreateIncomeCount > 0 || $pensionPostCount > 0) {
				$logger->info(
					"Bill reminder job completed: {$notificationCount} reminders sent, {$autoPayCount} bills auto-paid, {$autoPayFailedCount} auto-pay failures, {$autoCreateIncomeCount} income auto-created, {$autoCreateIncomeFailedCount} income auto-create failures, {$pensionPostCount} pension contributions posted, {$pensionPostFailedCount} pension post failures",
					['app' => 'budget']
				);
			}
		} catch (\Exception $e) {
			$logger->error(
				'Bill reminder job failed: ' . $e->getMessage(),
				['app' => 'budget', 'exception' => $e]
			);
		}
	}

	/**
	 * Check if we should send a reminder for this bill.
	 * Avoids sending duplicate reminders for the same due date.
	 */
	private function shouldSendReminder($bill, \DateTime $dueDate): bool {
		$lastReminderSent = $bill->getLastReminderSent();
		if (!$lastReminderSent) {
			return true;
		}

		// Only send one reminder per due date period
		$lastReminder = new \DateTime($lastReminderSent);
		$lastReminder->setTime(0, 0, 0);

		// If the last reminder was sent more than a week before the due date,
		// it was for a previous occurrence - send a new reminder
		$daysSinceReminder = (int)$lastReminder->diff($dueDate)->format('%R%a');
		return $daysSinceReminder > 7;
	}

	private function sendReminderNotification(
		INotificationManager $notificationManager,
		SettingService $settingService,
		string $userId,
		$bill,
		int $daysUntilDue,
	): void {
		$notification = $notificationManager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('bill', (string)$bill->getId())
			->setSubject('bill_reminder', [
				'billId' => $bill->getId(),
				'billName' => $bill->getName(),
				'amount' => $this->formatAmount($settingService, $userId, $bill->getAmount()),
				'daysUntilDue' => $daysUntilDue,
			]);

		$notificationManager->notify($notification);
	}

	private function sendOverdueNotification(
		INotificationManager $notificationManager,
		SettingService $settingService,
		string $userId,
		$bill,
		int $daysOverdue,
	): void {
		$notification = $notificationManager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('bill', (string)$bill->getId())
			->setSubject('bill_overdue', [
				'billId' => $bill->getId(),
				'billName' => $bill->getName(),
				'amount' => $this->formatAmount($settingService, $userId, $bill->getAmount()),
				'daysOverdue' => $daysOverdue,
			]);

		$notificationManager->notify($notification);
	}

	private function markReminderSent(BillMapper $billMapper, $bill): void {
		$bill->setLastReminderSent(date('Y-m-d H:i:s'));
		$billMapper->update($bill);
	}

	private function formatAmount(SettingService $settingService, string $userId, float $amount): string {
		// Shared implementation with the dashboard widgets and calendar feed
		return (new \OCA\Budget\Service\AmountFormatter($settingService))->formatForUser($userId, $amount);
	}

	/**
	 * Users with an active bill, active recurring income (for auto-create)
	 * or an auto-posting pension contribution schedule (#251).
	 *
	 * @return string[]
	 */
	private function getAllUserIds(IDBConnection $db): array {
		$users = new JobUsers($db);
		return JobUsers::union(
			$users->from('budget_bills', ['is_active' => true]),
			$users->from('budget_recurring_income', ['is_active' => true]),
			$users->from('budget_pen_recur', ['is_active' => true, 'auto_post_enabled' => true])
		);
	}

	/**
	 * Auto-post due recurring pension contributions for a user (#251).
	 *
	 * @return array{success: int, failed: int}
	 */
	private function processAutoPostPensionsForUser(
		string $userId,
		PensionRecurringContributionMapper $recurMapper,
		PensionRecurringService $recurService,
		LoggerInterface $logger,
	): array {
		$successCount = 0;
		$failedCount = 0;

		try {
			$due = $recurMapper->findDueForAutoPost($userId);
			foreach ($due as $schedule) {
				$result = $recurService->processAutoPost($schedule->getId(), $userId);
				if ($result['success']) {
					$successCount++;
				} else {
					$failedCount++;
					$logger->warning(
						"Pension auto-post failed for schedule {$schedule->getId()} (user {$userId}): " . ($result['message'] ?? 'unknown'),
						['app' => 'budget']
					);
				}
			}
		} catch (\Exception $e) {
			$logger->warning("Pension auto-post processing failed for user {$userId}: " . $e->getMessage(), ['app' => 'budget']);
		}

		return ['success' => $successCount, 'failed' => $failedCount];
	}

	/**
	 * Process auto-pay for all due bills for a user.
	 *
	 * @return array ['success' => int, 'failed' => int]
	 */
	private function processAutoPayForUser(
		string $userId,
		BillMapper $billMapper,
		BillService $billService,
		INotificationManager $notificationManager,
		SettingService $settingService,
		LoggerInterface $logger,
	): array {
		$successCount = 0;
		$failedCount = 0;

		try {
			$dueForAutoPay = $billMapper->findDueForAutoPay($userId);

			foreach ($dueForAutoPay as $bill) {
				$result = $billService->processAutoPay($bill->getId(), $userId);

				if ($result['success']) {
					$successCount++;
					$this->sendAutoPaySuccessNotification(
						$notificationManager,
						$settingService,
						$userId,
						$result['bill']
					);
				} else {
					$failedCount++;
					$this->sendAutoPayFailureNotification(
						$notificationManager,
						$settingService,
						$userId,
						$bill,
						$result['message']
					);
				}
			}
		} catch (\Exception $e) {
			// Log but don't fail entire job
			$logger->warning("Auto-pay processing failed for user {$userId}: " . $e->getMessage(), ['app' => 'budget']);
		}

		return ['success' => $successCount, 'failed' => $failedCount];
	}

	private function sendAutoPaySuccessNotification(
		INotificationManager $notificationManager,
		SettingService $settingService,
		string $userId,
		$bill,
	): void {
		$notification = $notificationManager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('bill', (string)$bill->getId())
			->setSubject('bill_auto_paid', [
				'billId' => $bill->getId(),
				'billName' => $bill->getName(),
				'amount' => $this->formatAmount($settingService, $userId, $bill->getAmount()),
				'nextDueDate' => $bill->getNextDueDate(),
			]);

		$notificationManager->notify($notification);
	}

	private function sendAutoPayFailureNotification(
		INotificationManager $notificationManager,
		SettingService $settingService,
		string $userId,
		$bill,
		string $reason,
	): void {
		$notification = $notificationManager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('bill', (string)$bill->getId())
			->setSubject('bill_auto_pay_failed', [
				'billId' => $bill->getId(),
				'billName' => $bill->getName(),
				'amount' => $this->formatAmount($settingService, $userId, $bill->getAmount()),
				'reason' => $reason,
			]);

		$notificationManager->notify($notification);
	}

	/**
	 * Process auto-create for all due recurring income for a user.
	 *
	 * @return array ['success' => int, 'failed' => int]
	 */
	private function processAutoCreateIncomeForUser(
		string $userId,
		RecurringIncomeMapper $incomeMapper,
		RecurringIncomeService $incomeService,
		INotificationManager $notificationManager,
		SettingService $settingService,
		LoggerInterface $logger,
	): array {
		$successCount = 0;
		$failedCount = 0;

		try {
			$dueIncome = $incomeMapper->findDueForAutoCreate($userId);

			foreach ($dueIncome as $income) {
				$result = $incomeService->processAutoCreate($income->getId(), $userId);

				if ($result['success']) {
					$successCount++;
					$this->sendAutoCreateIncomeSuccessNotification(
						$notificationManager,
						$settingService,
						$userId,
						$result['income']
					);
				} else {
					$failedCount++;
					$this->sendAutoCreateIncomeFailureNotification(
						$notificationManager,
						$settingService,
						$userId,
						$income,
						$result['message']
					);
				}
			}
		} catch (\Exception $e) {
			$logger->warning("Income auto-create processing failed for user {$userId}: " . $e->getMessage());
		}

		return ['success' => $successCount, 'failed' => $failedCount];
	}

	private function sendAutoCreateIncomeSuccessNotification(
		INotificationManager $notificationManager,
		SettingService $settingService,
		string $userId,
		$income,
	): void {
		$notification = $notificationManager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('recurring_income', (string)$income->getId())
			->setSubject('income_auto_created', [
				'incomeId' => $income->getId(),
				'incomeName' => $income->getName(),
				'amount' => $this->formatAmount($settingService, $userId, $income->getAmount()),
				'nextExpectedDate' => $income->getNextExpectedDate(),
			]);

		$notificationManager->notify($notification);
	}

	private function sendAutoCreateIncomeFailureNotification(
		INotificationManager $notificationManager,
		SettingService $settingService,
		string $userId,
		$income,
		string $reason,
	): void {
		$notification = $notificationManager->createNotification();

		$notification->setApp(Application::APP_ID)
			->setUser($userId)
			->setDateTime(new \DateTime())
			->setObject('recurring_income', (string)$income->getId())
			->setSubject('income_auto_create_failed', [
				'incomeId' => $income->getId(),
				'incomeName' => $income->getName(),
				'amount' => $this->formatAmount($settingService, $userId, $income->getAmount()),
				'reason' => $reason,
			]);

		$notificationManager->notify($notification);
	}
}
