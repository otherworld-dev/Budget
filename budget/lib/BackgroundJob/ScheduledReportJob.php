<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\BackgroundJob\Support\JobUsers;
use OCA\Budget\Service\Report\ScheduledReportService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Daily job delivering the previous month's PDF report.
 *
 * Idempotent via `report_last_month`: the state only advances when at
 * least one enabled channel (Files / email) succeeded, so all-channel
 * failures retry the next day and nothing is ever delivered twice.
 * Separate from DigestJob — TCPDF and Files I/O are heavy and have
 * different failure semantics.
 */
class ScheduledReportJob extends TimedJob {

    public function __construct(ITimeFactory $time) {
        parent::__construct($time);

        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $db = Server::get(IDBConnection::class);
        $settingService = Server::get(SettingService::class);
        $reportService = Server::get(ScheduledReportService::class);
        $logger = Server::get(LoggerInterface::class);

        $targetMonth = date('Y-m', strtotime('first day of last month'));
        $delivered = 0;

        foreach ($this->getEligibleUserIds($db) as $userId) {
            try {
                if ($settingService->get($userId, 'report_last_month') === $targetMonth) {
                    continue;
                }

                $toFiles = $settingService->get($userId, 'report_files_enabled') === 'true';
                $toEmail = $settingService->get($userId, 'report_email_enabled') === 'true';
                if (!$toFiles && !$toEmail) {
                    continue;
                }

                if ($reportService->deliverMonthlyReport($userId, $targetMonth, $toFiles, $toEmail)) {
                    $settingService->set($userId, 'report_last_month', $targetMonth);
                    $delivered++;
                } else {
                    $logger->warning("Scheduled report for {$userId} failed on all channels — will retry tomorrow", ['app' => 'budget']);
                }
            } catch (\Exception $e) {
                $logger->warning("Scheduled report failed for {$userId}: " . $e->getMessage(), ['app' => 'budget']);
            }
        }

        $logger->info("Scheduled report job completed: {$delivered} reports delivered for {$targetMonth}", ['app' => 'budget']);
    }

    /**
     * Users who turned on delivery to Files or by email.
     *
     * @return string[]
     */
    private function getEligibleUserIds(IDBConnection $db): array {
        return (new JobUsers($db))->withSettingEnabled('report_files_enabled', 'report_email_enabled');
    }
}
