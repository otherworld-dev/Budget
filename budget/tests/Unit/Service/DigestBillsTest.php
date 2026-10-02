<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Bill;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\AnomalyDetectionService;
use OCA\Budget\Service\Bill\BillSuggestionService;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\DigestService;
use OCA\Budget\Service\GoalsService;
use OCA\Budget\Service\Mail\BudgetMailService;
use OCA\Budget\Service\SettingService;
use OCP\IL10N;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

class DigestBillsTestService extends DigestService {
	public string $now = '2026-06-03';

	protected function getNow(): \DateTimeImmutable {
		return new \DateTimeImmutable($this->now);
	}
}

/**
 * The digest's bills. It cut the upcoming list to five before counting,
 * so "{bills} bills due soon" never went above 5, and months-old overdue
 * bills sorted first and pushed out the ones really due this week.
 */
class DigestBillsTest extends TestCase {
	private array $subject = [];
	private array $sections = [];
	private DigestBillsTestService $service;

	protected function setUp(): void {
		$bills = [];
		foreach (['2026-03-01', '2026-04-01'] as $i => $due) {
			$bills[] = $this->bill(100 + $i, "Old $i", $due);
		}
		for ($i = 1; $i <= 6; $i++) {
			$bills[] = $this->bill($i, "Due $i", sprintf('2026-06-%02d', 3 + $i));
		}

		$billService = $this->createMock(BillService::class);
		$billService->method('findUpcoming')->with('alice', 7)->willReturn($bills);
		$billService->method('enrichBillsWithCurrency')->willReturnArgument(0);

		$budgetAlertService = $this->createMock(BudgetAlertService::class);
		$budgetAlertService->method('getSummary')->willReturn(['totalCategories' => 0]);
		$goals = $this->createMock(GoalsService::class);
		$goals->method('findAll')->willReturn([]);
		$anomalies = $this->createMock(AnomalyDetectionService::class);
		$anomalies->method('detectForPeriod')->willReturn([]);
		$formatter = $this->createMock(AmountFormatter::class);
		$formatter->method('formatForUser')->willReturnCallback(fn ($u, float $a) => '$' . number_format($a, 2));
		$formatter->method('format')->willReturnCallback(fn (float $a) => '$' . number_format($a, 2));
		$settings = $this->createMock(SettingService::class);
		$settings->method('get')->willReturnMap([['alice', 'digest_email_enabled', 'true']]);

		$notification = $this->createMock(INotification::class);
		$notification->method('setSubject')->willReturnCallback(function (string $s, array $params) use ($notification) {
			$this->subject = $params;
			return $notification;
		});
		$notification->method($this->anything())->willReturnSelf();
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);

		$mail = $this->createMock(BudgetMailService::class);
		$mail->method('send')->willReturnCallback(function ($u, $s, $h, array $sections) {
			$this->sections = $sections;
			return true;
		});
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $p = []) => vsprintf($text, $p));
		$l->method('n')->willReturnCallback(fn (string $one, string $many, int $n, array $p = []) => vsprintf(str_replace('%n', (string)$n, $n === 1 ? $one : $many), $p));
		$l10n = $this->createMock(IFactory::class);
		$l10n->method('get')->willReturn($l);

		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('getAccountSummaries')->willReturn([]);

		$this->service = new DigestBillsTestService(
			$budgetAlertService,
			$billService,
			$goals,
			$anomalies,
			$this->createMock(BillSuggestionService::class),
			$formatter,
			$settings,
			$mail,
			$notifications,
			$l10n,
			$transactions
		);
		$this->service->now = '2026-06-03';
	}

	private function bill(int $id, string $name, string $due): Bill {
		$bill = new Bill();
		$bill->setId($id);
		$bill->setName($name);
		$bill->setAmount(10.0);
		$bill->setNextDueDate($due);
		$bill->setCurrency('GBP');
		return $bill;
	}

	public function testTheDigestCountsEveryBillDueAndReportsOverdueOnesApart(): void {
		$digest = $this->service->sendDigest('alice', 'weekly');

		$this->assertSame(6, $digest['upcomingBillCount']);
		$this->assertSame(['Due 1', 'Due 2', 'Due 3', 'Due 4', 'Due 5'], array_column($digest['upcomingBills'], 'name'));
		$this->assertSame(2, $digest['overdueBillCount']);
		$this->assertSame(['Old 0', 'Old 1'], array_column($digest['overdueBills'], 'name'));
		$this->assertSame('6', $this->subject['billCount']);
		$this->assertSame('2', $this->subject['overdueCount']);
	}

	public function testTheEmailSaysHowManyMoreBillsAreDue(): void {
		$this->service->sendDigest('alice', 'weekly');

		$byHeading = array_column($this->sections, 'lines', 'heading');
		$this->assertCount(6, $byHeading['Upcoming bills']);
		$this->assertSame('and 1 more', end($byHeading['Upcoming bills']));
		$this->assertCount(2, $byHeading['Overdue bills']);
	}
}
