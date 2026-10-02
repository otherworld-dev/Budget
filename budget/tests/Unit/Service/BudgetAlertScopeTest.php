<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AmountFormatter;
use OCA\Budget\Service\BudgetAlertService;
use OCA\Budget\Service\BudgetCarryoverService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\RecurringBudgetService;
use OCA\Budget\Service\SettingService;
use OCP\Notification\IManager as INotificationManager;
use PHPUnit\Framework\TestCase;

/**
 * What the budget alerts, the Nextcloud dashboard widget and the digest add
 * up: spending categories only, over the accounts the user can see.
 */
class BudgetAlertScopeTest extends TestCase {
	/** @var array<int, array|null> account scope each spending query was asked with */
	private array $scopes = [];
	private BudgetAlertService $service;

	protected function setUp(): void {
		$categories = $this->createMock(CategoryMapper::class);
		$categories->method('findAll')->willReturn([
			$this->category(1, 'Groceries', 'expense', 165.0),
			$this->category(2, 'Salary', 'income', 3000.0),
		]);

		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('getCategorySpendingBatch')->willReturnCallback(
			function (array $ids, string $from, string $to, string $type, $accountId, $deducted, $userId, ?array $visible) {
				$this->scopes[] = $visible;
				// Net debits: the salary that arrived is -3000 of "spending"
				return array_intersect_key([1 => 25.0, 2 => -3000.0], array_flip($ids));
			}
		);

		$snapshots = $this->createMock(BudgetSnapshotMapper::class);
		$snapshots->method('findEffectiveBatch')->willReturn([]);
		$settings = $this->createMock(SettingService::class);
		$settings->method('get')->willReturn(null);
		$recurring = $this->createMock(RecurringBudgetService::class);
		$recurring->method('getMonthlyBudgetsByCategory')->willReturn([]);
		$carryover = $this->createMock(BudgetCarryoverService::class);
		$carryover->method('getCarryovers')->willReturn([]);
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getVisibleAccountIds')->with('alice')->willReturn([1, 2, 9]);

		$this->service = new BudgetAlertService(
			$categories,
			$snapshots,
			$transactions,
			$settings,
			$recurring,
			$carryover,
			$this->createMock(INotificationManager::class),
			$this->createMock(AmountFormatter::class),
			$shares,
		);
	}

	private function category(int $id, string $name, string $type, float $budget): Category {
		$category = new Category();
		$category->setId($id);
		$category->setUserId('alice');
		$category->setName($name);
		$category->setType($type);
		$category->setBudgetAmount($budget);
		$category->setBudgetPeriod('monthly');
		$category->setExcludedFromBudget(false);
		return $category;
	}

	/**
	 * An income target was measured as spending: the salary arriving made
	 * spent -3000, and the summary read "-£2,975.00 of £3,165.00 spent".
	 */
	public function testIncomeTargetsStayOutOfTheSpendingSummary(): void {
		$summary = $this->service->getSummary('alice');

		$this->assertSame(1, $summary['totalCategories']);
		$this->assertSame(165.0, $summary['totalBudget']);
		$this->assertSame(25.0, $summary['totalSpent']);
		$this->assertSame([1], array_column($this->service->getBudgetStatus('alice'), 'categoryId'));
	}

	/**
	 * Called with no scope (the background notifications, the dashboard
	 * widget, the digest), spending was measured over the user's own accounts
	 * only, while the Budget page counts the accounts shared with them too.
	 */
	public function testWithNoScopeGivenSpendingIsMeasuredOverEveryAccountTheUserCanSee(): void {
		$this->service->getAlerts('alice');
		$this->service->getSummary('alice');

		$this->assertNotEmpty($this->scopes);
		foreach ($this->scopes as $scope) {
			$this->assertSame([1, 2, 9], $scope);
		}
	}
}
