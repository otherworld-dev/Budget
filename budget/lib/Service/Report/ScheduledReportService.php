<?php

declare(strict_types=1);

namespace OCA\Budget\Service\Report;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\Mail\BudgetMailService;
use OCA\Budget\Service\ReportService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Monthly scheduled PDF reports: the previous month's summary report,
 * saved into Budget/Reports/<year>/ in the user's Files and/or emailed —
 * each channel independently toggleable. The PDF is generated once and
 * each enabled channel attempted independently.
 */
class ScheduledReportService {

    private const REPORTS_FOLDER = 'Budget/Reports';

    public function __construct(
        private ReportService $reportService,
        private IRootFolder $rootFolder,
        private BudgetMailService $mailService,
        private INotificationManager $notificationManager,
        private IFactory $l10nFactory,
        private IURLGenerator $urlGenerator,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Generate and deliver the report for $month (YYYY-MM) through the
     * enabled channels. Returns true when at least one channel succeeded —
     * the caller's signal to advance the per-user schedule state.
     */
    public function deliverMonthlyReport(string $userId, string $month, bool $toFiles, bool $toEmail): bool {
        if (!$toFiles && !$toEmail) {
            return false;
        }

        $startDate = $month . '-01';
        $endDate = date('Y-m-t', strtotime($startDate));
        $filename = "Budget-Report-{$month}.pdf";

        $export = $this->reportService->exportReport($userId, 'summary', 'pdf', $startDate, $endDate);
        $pdfContent = $export['stream'];

        $anySuccess = false;

        if ($toFiles) {
            try {
                $node = $this->writeToFiles($userId, $month, $filename, $pdfContent);
                $this->sendReportReadyNotification($userId, $month, $node);
                $anySuccess = true;
            } catch (\Exception $e) {
                $this->logger->warning("Scheduled report Files write failed for {$userId}: " . $e->getMessage(), ['app' => Application::APP_ID]);
            }
        }

        if ($toEmail) {
            $l = $this->l10nFactory->get(Application::APP_ID, $this->mailService->getUserLanguage($userId));
            $monthLabel = date('F Y', strtotime($startDate));
            $sent = $this->mailService->send(
                $userId,
                $l->t('Your Budget report for %1$s', [$monthLabel]),
                $l->t('Your Budget report for %1$s', [$monthLabel]),
                [[
                    'heading' => null,
                    'lines' => [$l->t('The attached PDF summarizes your finances for %1$s.', [$monthLabel])],
                ]],
                ['name' => $filename, 'content' => $pdfContent]
            );
            $anySuccess = $anySuccess || $sent;
        }

        return $anySuccess;
    }

    private function writeToFiles(string $userId, string $month, string $filename, string $content): File {
        $userFolder = $this->rootFolder->getUserFolder($userId);

        $year = substr($month, 0, 4);
        $folder = $userFolder;
        foreach ([...explode('/', self::REPORTS_FOLDER), $year] as $segment) {
            $folder = $folder->nodeExists($segment) ? $folder->get($segment) : $folder->newFolder($segment);
        }

        $name = $this->uniqueName($folder, $filename);
        return $folder->newFile($name, $content);
    }

    private function sendReportReadyNotification(string $userId, string $month, File $node): void {
        $notification = $this->notificationManager->createNotification();
        $notification->setApp(Application::APP_ID)
            ->setUser($userId)
            ->setDateTime(new \DateTime())
            ->setObject('report', $month)
            ->setSubject('report_ready', [
                'month' => $month,
                'fileId' => (string) $node->getId(),
                'fileName' => $node->getName(),
            ]);
        $this->notificationManager->notify($notification);
    }

    private function uniqueName($folder, string $name): string {
        if (!$folder->nodeExists($name)) {
            return $name;
        }
        $dot = strrpos($name, '.');
        $base = $dot === false ? $name : substr($name, 0, $dot);
        $ext = $dot === false ? '' : substr($name, $dot);
        for ($i = 1; $i < 100; $i++) {
            $candidate = "{$base}-{$i}{$ext}";
            if (!$folder->nodeExists($candidate)) {
                return $candidate;
            }
        }
        return $base . '-' . time() . $ext;
    }
}
