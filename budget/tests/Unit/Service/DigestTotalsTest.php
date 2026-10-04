<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\AnomalyDetectionService;
use OCA\Budget\Service\Bill\BillSuggestionService;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\GoalsService;
use OCA\Budget\Service\Mail\BudgetMailService;
use OCA\Budget\Service\Report\ReportAggregator;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Service\UserClock;
use OCP\L10N\IFactory;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;

/**
 * The digest's income and spending are the Cash Flow report's for the same
 * dates. It added up every account's credits and debits, so a transfer to
 * savings was income and spending at once, and euros were added to pounds.
 */
class DigestTotalsTest extends TestCase {
	public function testIncomeAndSpendingAreCashFlowsForThePeriod(): void {
		$budgetAlerts = $this->createMock(BudgetAlertService::class);
		$budgetAlerts->method('getSummary')->willReturn(['totalCategories' => 0]);
		$bills = $this->createMock(BillService::class);
		$bills->method('findUpcoming')->willReturn([]);
		$bills->method('enrichBillsWithCurrency')->willReturn([]);
		$goals = $this->createMock(GoalsService::class);
		$goals->method('findAll')->willReturn([]);

		// Gross per account: 100 of each side is a transfer to savings, and
		// 1500 is EUR 3 x 500 counted as pounds
		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('getAccountSummaries')->willReturn([
			1 => ['income' => 1500.0, 'expenses' => 290.0, 'count' => 5],
			2 => ['income' => 100.0, 'expenses' => 0.0, 'count' => 1],
		]);
		// Cash Flow: transfers out, the euros converted
		$aggregator = $this->createMock(ReportAggregator::class);
		$aggregator->expects($this->once())->method('getCashFlowReport')
			->with('alice', null, '2026-09-01', '2026-09-30')
			->willReturn(['totals' => ['income' => 1275.0, 'expenses' => 190.0, 'net' => 1085.0]]);

		$clock = $this->createMock(UserClock::class);
		$clock->method('now')->willReturn(new \DateTimeImmutable('2026-10-02 09:00'));

		$service = new \OCA\Budget\Service\DigestService(
			$budgetAlerts,
			$bills,
			$goals,
			$this->createMock(AnomalyDetectionService::class),
			$this->createMock(BillSuggestionService::class),
			$this->createMock(AmountFormatter::class),
			$this->createMock(SettingService::class),
			$this->createMock(BudgetMailService::class),
			$this->createMock(INotificationManager::class),
			$this->createMock(IFactory::class),
			$transactions,
			$clock,
			$aggregator
		);

		$digest = $service->buildDigest('alice', 'monthly');

		$this->assertSame(1275.0, $digest['income']);
		$this->assertSame(190.0, $digest['expenses']);
		$this->assertSame(1085.0, $digest['net']);
	}
}
