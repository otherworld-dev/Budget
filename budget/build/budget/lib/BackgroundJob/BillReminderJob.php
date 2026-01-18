<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\BillMapper;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Notification\IManager as INotificationManager;
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
    private BillMapper $billMapper;
    private INotificationManager $notificationManager;
    private IDBConnection $db;
    private LoggerInterface $logger;
    private ?SettingService $settingService;

    public function __construct(
        ITimeFactory $time,
        BillMapper $billMapper,
        INotificationManager $notificationManager,
        IDBConnection $db,
        LoggerInterface $logger,
        ?SettingService $settingService = null
    ) {
        parent::__construct($time);
        $this->billMapper = $billMapper;
        $this->notificationManager = $notificationManager;
        $this->db = $db;
        $this->logger = $logger;
        $this->settingService = $settingService;

        // Run every 6 hours
        $this->setInterval(6 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        try {
            $userIds = $this->getAllUserIds();
            $notificationCount = 0;
            $today = new \DateTime();
            $today->setTime(0, 0, 0);

            foreach ($userIds as $userId) {
                try {
                    $bills = $this->billMapper->findActive($userId);

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
                                $this->sendOverdueNotification($userId, $bill, $daysUntilDue);
                                $this->markReminderSent($bill);
                                $notificationCount++;
                            }
                        } elseif ($daysUntilDue <= $bill->getReminderDays()) {
                            // Within reminder window
                            if ($this->shouldSendReminder($bill, $dueDate)) {
                                $this->sendReminderNotification($userId, $bill, $daysUntilDue);
                                $this->markReminderSent($bill);
                                $notificationCount++;
                            }
                        }
                    }
                } catch (\Exception $e) {
                    $this->logger->warning(
                        "Failed to process bill reminders for user {$userId}: " . $e->getMessage(),
                        ['app' => 'budget', 'userId' => $userId]
                    );
                }
            }

            if ($notificationCount > 0) {
                $this->logger->info(
                    "Bill reminder job completed: {$notificationCount} notifications sent",
                    ['app' => 'budget']
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
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

    private function sendReminderNotification(string $userId, $bill, int $daysUntilDue): void {
        $notification = $this->notificationManager->createNotification();

        $notification->setApp(Application::APP_ID)
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('bill', (string)$bill->getId())
            ->setSubject('bill_reminder', [
                'billId' => $bill->getId(),
                'billName' => $bill->getName(),
                'amount' => $this->formatAmount($userId, $bill->getAmount()),
                'daysUntilDue' => $daysUntilDue,
            ]);

        $this->notificationManager->notify($notification);
    }

    private function sendOverdueNotification(string $userId, $bill, int $daysOverdue): void {
        $notification = $this->notificationManager->createNotification();

        $notification->setApp(Application::APP_ID)
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('bill', (string)$bill->getId())
            ->setSubject('bill_overdue', [
                'billId' => $bill->getId(),
                'billName' => $bill->getName(),
                'amount' => $this->formatAmount($userId, $bill->getAmount()),
                'daysOverdue' => $daysOverdue,
            ]);

        $this->notificationManager->notify($notification);
    }

    private function markReminderSent($bill): void {
        $bill->setLastReminderSent(date('Y-m-d H:i:s'));
        $this->billMapper->update($bill);
    }

    private function formatAmount(string $userId, float $amount): string {
        $currency = 'USD';

        // Try to get user's currency setting
        if ($this->settingService !== null) {
            try {
                $currency = $this->settingService->get($userId, 'currency') ?? 'USD';
            } catch (\Exception $e) {
                // Use default
            }
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
    private function getAllUserIds(): array {
        $qb = $this->db->getQueryBuilder();
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
}
