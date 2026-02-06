<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Service\BillService;
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
        $notificationManager = Server::get(INotificationManager::class);
        $db = Server::get(IDBConnection::class);
        $logger = Server::get(LoggerInterface::class);
        $settingService = Server::get(SettingService::class);

        try {
            $userIds = $this->getAllUserIds($db);
            $notificationCount = 0;
            $autoPayCount = 0;
            $autoPayFailedCount = 0;
            $today = new \DateTime();
            $today->setTime(0, 0, 0);

            foreach ($userIds as $userId) {
                try {
                    // Process auto-pay BEFORE reminders to avoid sending reminder for auto-paid bill
                    $autoPay = $this->processAutoPayForUser($userId, $billMapper, $billService, $notificationManager, $settingService);
                    $autoPayCount += $autoPay['success'];
                    $autoPayFailedCount += $autoPay['failed'];

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

            if ($notificationCount > 0 || $autoPayCount > 0) {
                $logger->info(
                    "Bill reminder job completed: {$notificationCount} reminders sent, {$autoPayCount} bills auto-paid, {$autoPayFailedCount} auto-pay failures",
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
        int $daysUntilDue
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
        int $daysOverdue
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
        $currency = 'USD';

        // Try to get user's currency setting
        try {
            $currency = $settingService->get($userId, 'currency') ?? 'USD';
        } catch (\Exception $e) {
            // Use default
        }

        $symbols = [
            'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'JPY' => '¥',
            'CAD' => 'CA$', 'AUD' => 'A$', 'CHF' => 'CHF', 'CNY' => '¥',
        ];

        $symbol = $symbols[$currency] ?? $currency . ' ';
        return $symbol . number_format($amount, 2);
    }

    /**
     * Get all unique user IDs from the bills table.
     *
     * @return string[]
     */
    private function getAllUserIds(IDBConnection $db): array {
        $qb = $db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from('budget_bills')
            ->where($qb->expr()->eq('is_active', $qb->createNamedParameter(true)));

        $result = $qb->executeQuery();
        $userIds = [];
        while ($row = $result->fetch()) {
            $userIds[] = $row['user_id'];
        }
        $result->closeCursor();

        return $userIds;
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
        SettingService $settingService
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
            error_log("Auto-pay processing failed for user {$userId}: " . $e->getMessage());
        }

        return ['success' => $successCount, 'failed' => $failedCount];
    }

    private function sendAutoPaySuccessNotification(
        INotificationManager $notificationManager,
        SettingService $settingService,
        string $userId,
        $bill
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
        string $reason
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
}
