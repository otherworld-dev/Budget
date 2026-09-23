<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\BudgetSnapshotMapper;
use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\TagSetMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Service\BudgetCarryoverService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\RecurringBudgetService;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * The Budget page's "Ready to assign" figure: income received in the budget
 * month minus the month's budgets across budgeted expense categories.
 */
class CategoryServiceReadyToAssignTest extends TestCase {
    private CategoryMapper $categoryMapper;
    private TransactionMapper $transactionMapper;
    private BudgetSnapshotMapper $snapshotMapper;
    /** @var Category[] */
    private array $categories = [];
    /** @var array<int, float> carryover the mocked envelope service reports */
    private array $carryovers = [];
    /** @var array<int, float> monthly recurring fallback per category */
    private array $recurring = [];
    private int $startDay = 1;
    private string $currentMonth = '2026-09';
    /** @var array<int, array> rows getSpendingSummary returns */
    private array $incomeRows = [];
    /** @var array<int, array> arguments getSpendingSummary was called with */
    private array $summaryCalls = [];

    protected function setUp(): void {
        $this->categoryMapper = $this->createMock(CategoryMapper::class);
        $this->categoryMapper->method('findAll')->willReturnCallback(fn() => $this->categories);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->transactionMapper->method('getSpendingSummary')->willReturnCallback(function (...$args) {
            $this->summaryCalls[] = $args;
            return $this->incomeRows;
        });
        $this->snapshotMapper = $this->createMock(BudgetSnapshotMapper::class);
        $this->snapshotMapper->method('findEffectiveBatch')->willReturn([]);
    }

    private function service(): CategoryService {
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $carryover = $this->createMock(BudgetCarryoverService::class);
        $carryover->method('getCarryovers')->willReturnCallback(fn() => $this->carryovers);
        $carryover->method('currentBudgetMonth')->willReturnCallback(fn() => $this->currentMonth);
        $carryover->method('budgetStartDay')->willReturnCallback(fn() => $this->startDay);

        $recurring = $this->createMock(RecurringBudgetService::class);
        $recurring->method('getMonthlyBudgetsByCategory')->willReturnCallback(fn() => $this->recurring);
        $recurring->method('convertMonthlyToPeriod')->willReturnCallback(fn(float $m) => $m);

        return new CategoryService(
            $this->categoryMapper,
            $this->transactionMapper,
            $this->snapshotMapper,
            $this->createMock(TagSetMapper::class),
            $this->createMock(TagMapper::class),
            $this->createMock(TransactionTagMapper::class),
            $l,
            $carryover,
            $recurring
        );
    }

    private function category(int $id, string $type, ?float $budget = null, array $extra = []): Category {
        $c = new Category();
        $c->setId($id);
        $c->setUserId('user1');
        $c->setName('Cat ' . $id);
        $c->setType($type);
        $c->setParentId($extra['parentId'] ?? null);
        $c->setBudgetAmount($budget);
        $c->setBudgetPeriod($extra['period'] ?? 'monthly');
        $c->setBudgetRollover($extra['rollover'] ?? false);
        $c->setExcludedFromBudget($extra['excludedFromBudget'] ?? false);
        $c->setExcludedFromReports($extra['excludedFromReports'] ?? false);
        return $c;
    }

    private function income(int $categoryId, float $total): array {
        return ['id' => $categoryId, 'name' => 'x', 'color' => null, 'icon' => null, 'total' => $total, 'count' => 1];
    }

    public function testIncomeMinusBudgetedExpenses(): void {
        $this->categories = [
            $this->category(1, 'income'),
            $this->category(2, 'expense', 1200.0),
            $this->category(3, 'expense', 300.50),
            $this->category(4, 'expense'),           // no budget
        ];
        $this->incomeRows = [$this->income(1, 3000.0)];

        $result = $this->service()->getReadyToAssign('user1', '2026-09');

        $this->assertSame(3000.0, $result['income']);
        $this->assertSame(1500.5, $result['budgeted']);
        $this->assertSame(1499.5, $result['amount']);
        $this->assertSame('2026-09-01', $result['startDate']);
        $this->assertSame('2026-09-30', $result['endDate']);
    }

    public function testNegativeWhenBudgetsExceedIncome(): void {
        $this->categories = [
            $this->category(1, 'income'),
            $this->category(2, 'expense', 2500.0),
        ];
        $this->incomeRows = [$this->income(1, 2000.0)];

        $result = $this->service()->getReadyToAssign('user1', '2026-09');

        $this->assertSame(-500.0, $result['amount']);
    }

    public function testOnlyIncomeCategoriesCountAsIncome(): void {
        // A refund credit in an expense category is not income to assign
        $this->categories = [
            $this->category(1, 'income'),
            $this->category(2, 'expense', 100.0),
        ];
        $this->incomeRows = [$this->income(1, 500.0), $this->income(2, 40.0), $this->income(99, 70.0)];

        $result = $this->service()->getReadyToAssign('user1', '2026-09');

        $this->assertSame(500.0, $result['income']);
        $this->assertSame(400.0, $result['amount']);
    }

    public function testCategoriesOutOfBudgetingAreNotBudgeted(): void {
        // A parent out of budgeting takes its children with it (BudgetScope)
        $this->categories = [
            $this->category(1, 'income'),
            $this->category(2, 'expense', 400.0, ['excludedFromBudget' => true]),
            $this->category(3, 'expense', 150.0, ['parentId' => 2]),
            $this->category(4, 'expense', 100.0),
        ];
        $this->incomeRows = [$this->income(1, 1000.0)];

        $result = $this->service()->getReadyToAssign('user1', '2026-09');

        $this->assertSame(100.0, $result['budgeted']);
        $this->assertSame(900.0, $result['amount']);
    }

    public function testIncomeOutOfBudgetingStillCounts(): void {
        $this->categories = [
            $this->category(1, 'income', null, ['excludedFromBudget' => true]),
            $this->category(2, 'expense', 100.0),
        ];
        $this->incomeRows = [$this->income(1, 800.0)];

        $result = $this->service()->getReadyToAssign('user1', '2026-09');

        $this->assertSame(800.0, $result['income']);
    }

    public function testReportExcludedBranchesDropOutOnBothSides(): void {
        $this->categories = [
            $this->category(1, 'income', null, ['excludedFromReports' => true]),
            $this->category(2, 'income', null, ['parentId' => 1]),
            $this->category(3, 'income'),
            $this->category(4, 'expense', 200.0, ['excludedFromReports' => true]),
            $this->category(5, 'expense', 50.0, ['parentId' => 4]),
            $this->category(6, 'expense', 75.0),
        ];
        // The SQL layer drops category 1 itself; its child still arrives
        $this->incomeRows = [$this->income(2, 300.0), $this->income(3, 1000.0)];

        $result = $this->service()->getReadyToAssign('user1', '2026-09');

        $this->assertSame(1000.0, $result['income']);
        $this->assertSame(75.0, $result['budgeted']);
    }

    public function testOtherPeriodsCountAsTheirMonthlyShare(): void {
        $this->categories = [
            $this->category(1, 'expense', 1200.0, ['period' => 'yearly']),
            $this->category(2, 'expense', 300.0, ['period' => 'quarterly']),
            $this->category(3, 'expense', 12.0, ['period' => 'weekly']),
        ];

        $result = $this->service()->getReadyToAssign('user1', '2026-09');

        // 100 + 100 + 12*52/12 = 252
        $this->assertSame(252.0, $result['budgeted']);
        $this->assertSame(-252.0, $result['amount']);
    }

    public function testRolloverCarryoverIsNotAssignedAgain(): void {
        $this->categories = [
            $this->category(1, 'income'),
            $this->category(2, 'expense', 300.0, ['rollover' => true]),
            $this->category(3, 'expense', 200.0, ['rollover' => true]),
        ];
        $this->incomeRows = [$this->income(1, 1000.0)];

        $withoutCarry = $this->service()->getReadyToAssign('user1', '2026-09');

        // Unspent money carried in (and an overspend carried in) changes what
        // each envelope holds, never what this month's income was assigned
        $this->carryovers = [2 => 120.0, 3 => -45.0];
        $withCarry = $this->service()->getReadyToAssign('user1', '2026-09');

        $this->assertSame(500.0, $withoutCarry['budgeted']);
        $this->assertSame(500.0, $withCarry['budgeted']);
        $this->assertSame(500.0, $withCarry['amount']);
    }

    public function testRecurringFallbackCountsAsBudgeted(): void {
        $this->categories = [
            $this->category(1, 'income'),
            $this->category(2, 'expense'),
        ];
        $this->recurring = [2 => 45.0];
        $this->incomeRows = [$this->income(1, 100.0)];

        $result = $this->service()->getReadyToAssign('user1', '2026-09');

        $this->assertSame(45.0, $result['budgeted']);
        $this->assertSame(55.0, $result['amount']);
    }

    public function testIncomeFollowsTheStartDayAndAccountScope(): void {
        $this->startDay = 25;
        $this->categories = [$this->category(1, 'income')];
        $this->incomeRows = [$this->income(1, 10.0)];

        $result = $this->service()->getReadyToAssign('user1', '2026-06', [7, 8]);

        // Start day 25: "June" is 25 May - 24 June
        $this->assertSame('2026-05-25', $result['startDate']);
        $this->assertSame('2026-06-24', $result['endDate']);
        $this->assertCount(1, $this->summaryCalls);
        $args = $this->summaryCalls[0];
        $this->assertSame('user1', $args[0]);
        $this->assertSame('2026-05-25', $args[1]);
        $this->assertSame('2026-06-24', $args[2]);
        // (…, accountId, tagIds, includeUntagged, excludeTransfers, visibleAccountIds, transactionType, netOpposite)
        $this->assertSame([7, 8], $args[7]);
        $this->assertSame('credit', $args[8]);
        $this->assertTrue($args[9]);
    }

    public function testUsesPassedEffectiveBudgets(): void {
        $this->categories = [$this->category(2, 'expense', 999.0)];

        $result = $this->service()->getReadyToAssign('user1', '2026-09', null, [
            2 => ['amount' => 40.0, 'period' => 'monthly', 'rollover' => false, 'carried' => 0.0, 'available' => 40.0],
        ]);

        $this->assertSame(40.0, $result['budgeted']);
    }
}
