<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionReportQueries;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\BudgetCarryoverService;
use OCA\Budget\Service\RecurringBudgetService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Service\UserClock;
use OCA\Budget\Service\YearOverYearService;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;

/**
 * The budget month and the reporting year are the user's, not the server's.
 *
 * Nextcloud runs PHP in UTC, so until about 10:00 on the 1st a user in
 * Sydney is already in the new month while the server is still in the old
 * one: the budget tile, alerts and /api/v1/budget/status showed last month.
 * Each case sets the user's calendar years away from the server's, so no
 * server date can pass by accident.
 */
class UsersMonthTest extends TestCase {
	private const NOW = '2030-07-01 08:00:00';

	private function clock(): UserClock {
		$clock = $this->createMock(UserClock::class);
		$now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('Australia/Sydney'));
		$clock->method('now')->willReturn($now);
		$clock->method('today')->willReturn($now->format('Y-m-d'));
		return $clock;
	}

	private function settings(): SettingService {
		$settings = $this->createMock(SettingService::class);
		$settings->method('get')->willReturn(null);
		return $settings;
	}

	public function testTheCurrentBudgetMonthIsTheUsersMonth(): void {
		$service = new BudgetCarryoverService(
			$this->createMock(CategoryMapper::class),
			$this->createMock(BudgetSnapshotMapper::class),
			$this->createMock(TransactionMapper::class),
			$this->createMock(TransactionSplitMapper::class),
			$this->createMock(RecurringBudgetService::class),
			$this->settings(),
			$this->clock(),
		);

		$this->assertSame('2030-07', $service->currentBudgetMonth('alice'));
		$this->assertSame('2030-07-01', $service->today('alice'));
	}

	public function testBudgetStatusMeasuresTheUsersMonth(): void {
		$category = new Category();
		$category->setId(1);
		$category->setUserId('alice');
		$category->setName('Groceries');
		$category->setType('expense');
		$category->setBudgetAmount(200.0);
		$category->setBudgetPeriod('monthly');
		$category->setExcludedFromBudget(false);
		$categories = $this->createMock(CategoryMapper::class);
		$categories->method('findAll')->willReturn([$category]);

		$ranges = [];
		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('getCategorySpendingBatch')->willReturnCallback(
			function (array $ids, string $from, string $to) use (&$ranges) {
				$ranges[] = [$from, $to];
				return [1 => 20.0];
			}
		);
		$snapshotMonths = [];
		$snapshots = $this->createMock(BudgetSnapshotMapper::class);
		$snapshots->method('findEffectiveBatch')->willReturnCallback(function (string $userId, string $month) use (&$snapshotMonths) {
			$snapshotMonths[] = $month;
			return [];
		});
		$recurring = $this->createMock(RecurringBudgetService::class);
		$recurring->method('getMonthlyBudgetsByCategory')->willReturn([]);
		$carryover = $this->createMock(BudgetCarryoverService::class);
		$carryover->method('getCarryovers')->willReturn([]);

		$service = new BudgetAlertService(
			$categories,
			$snapshots,
			$transactions,
			$this->settings(),
			$recurring,
			$carryover,
			$this->createMock(INotificationManager::class),
			$this->createMock(AmountFormatter::class),
			null,
			$this->clock(),
		);

		$service->getBudgetStatus('alice');

		$this->assertSame([['2030-07-01', '2030-07-31']], $ranges);
		$this->assertSame(['2030-07'], $snapshotMonths);
	}

	public function testRecurringBudgetsDefaultToTheUsersMonth(): void {
		$bills = $this->createMock(BillService::class);
		$bills->method('findActive')->willReturn([]);
		$income = new RecurringIncome();
		$income->setAmount(3000.0);
		$income->setFrequency('monthly');
		$income->setCategoryId(4);
		// Starts within the user's month, years after the server's
		$income->setStartDate('2030-07-20');
		$incomes = $this->createMock(RecurringIncomeService::class);
		$incomes->method('findActive')->willReturn([$income]);

		$service = new RecurringBudgetService(
			$bills, $incomes, new FrequencyCalculator(), null, null, $this->settings(), $this->clock()
		);

		$this->assertSame([4 => 3000.0], $service->getMonthlyBudgetsByCategory('alice'));
	}

	public function testYearOverYearRunsToTheUsersToday(): void {
		$asked = [];
		$reports = $this->createMock(TransactionReportQueries::class);
		$reports->method('getCashFlowByMonth')->willReturnCallback(
			function (string $userId, ?int $accountId, string $start, string $end) use (&$asked) {
				$asked[] = [$start, $end];
				return [];
			}
		);
		$service = new YearOverYearService(
			$this->createMock(TransactionMapper::class),
			$this->createMock(CategoryMapper::class),
			$reports,
			$this->clock(),
		);

		$years = $service->compareYears('alice', 2, 5)['years'];

		$this->assertSame([2030, 2029], array_column($years, 'year'));
		$this->assertSame(['2030-01-01', '2030-07-01'], $asked[0]);

		$trends = $service->getMonthlyTrends('alice', 1, 5)['years'][0];
		$this->assertCount(7, $trends['months']);
	}
}
