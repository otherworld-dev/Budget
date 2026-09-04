<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Report;

use OCA\Budget\Service\Mail\BudgetMailService;
use OCA\Budget\Service\Report\ScheduledReportService;
use OCA\Budget\Service\ReportService;
use OCP\Files\IRootFolder;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ScheduledReportServiceTest extends TestCase {
    private ReportService $reportService;
    private BudgetMailService $mailService;
    private ScheduledReportService $service;

    protected function setUp(): void {
        $this->reportService = $this->createMock(ReportService::class);
        $this->mailService = $this->createMock(BudgetMailService::class);
        $this->mailService->method('getUserLanguage')->willReturn('de');

        // The user's translator: knows one month name, passes everything else through.
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(
            fn(string $text, array $params = []) => vsprintf(['August' => 'Aout'][$text] ?? $text, $params)
        );
        $factory = $this->createMock(IFactory::class);
        $factory->method('get')->willReturn($l);

        $this->service = new ScheduledReportService(
            $this->reportService,
            $this->createMock(IRootFolder::class),
            $this->mailService,
            $this->createMock(INotificationManager::class),
            $factory,
            $this->createMock(IURLGenerator::class),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function pdfExport(): array {
        return ['stream' => '%PDF-1.7', 'contentType' => 'application/pdf', 'filename' => 'Budget-Report-2026-08.pdf'];
    }

    /**
     * A background job has no session, so the report must be asked for in the
     * recipient's language explicitly — otherwise it comes out in the
     * server's (#377).
     */
    public function testThePdfIsExportedInTheRecipientsLanguage(): void {
        $this->reportService->expects($this->once())->method('exportReport')
            ->with('alice', 'summary', 'pdf', '2026-08-01', '2026-08-31', null, null, 'de')
            ->willReturn($this->pdfExport());
        $this->mailService->method('send')->willReturn(true);

        $this->assertTrue($this->service->deliverMonthlyReport('alice', '2026-08', false, true));
    }

    public function testTheEmailNamesTheMonthInTheRecipientsLanguage(): void {
        $this->reportService->method('exportReport')->willReturn($this->pdfExport());
        $this->mailService->expects($this->once())->method('send')
            ->with('alice', 'Your Budget report for Aout 2026', $this->anything(), $this->anything(), $this->anything())
            ->willReturn(true);

        $this->service->deliverMonthlyReport('alice', '2026-08', false, true);
    }
}
