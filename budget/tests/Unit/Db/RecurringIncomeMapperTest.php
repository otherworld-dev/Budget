<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class RecurringIncomeMapperTest extends TestCase {
    private RecurringIncomeMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;
    private IResult $result;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);
        $this->result = $this->createMock(IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('getSQL')->willReturn('');
        $this->qb->method('createNamedParameter')->willReturn(':param');

        foreach (['select', 'from', 'where', 'andWhere', 'orderBy',
                   'addOrderBy', 'delete', 'update', 'set'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new RecurringIncomeMapper($this->db);
    }

    private function makeIncomeRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'name' => 'Salary',
            'amount' => 5000.00,
            'frequency' => 'monthly',
            'expected_day' => 25,
            'expected_month' => null,
            'category_id' => 3,
            'account_id' => 1,
            'source' => 'Employer Inc',
            'auto_detect_pattern' => null,
            'is_active' => 1,
            'last_received_date' => '2026-02-25',
            'next_expected_date' => '2026-03-25',
            'notes' => null,
            'created_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    private function makeIncome(array $overrides = []): RecurringIncome {
        $income = new RecurringIncome();
        $defaults = [
            'id' => 1,
            'userId' => 'user1',
            'name' => 'Salary',
            'amount' => 5000.00,
            'frequency' => 'monthly',
            'isActive' => true,
        ];
        $data = array_merge($defaults, $overrides);

        $income->setId($data['id']);
        $income->setUserId($data['userId']);
        $income->setName($data['name']);
        $income->setAmount($data['amount']);
        $income->setFrequency($data['frequency']);
        $income->setIsActive($data['isActive']);
        return $income;
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_recurring_income', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsIncome(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeIncomeRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $income = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(RecurringIncome::class, $income);
        $this->assertEquals('Salary', $income->getName());
        $this->assertEquals(5000.00, $income->getAmount());
        $this->assertEquals('monthly', $income->getFrequency());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findAll =====

    public function testFindAllReturnsIncomes(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeIncomeRow(['id' => 1, 'name' => 'Salary']),
                $this->makeIncomeRow(['id' => 2, 'name' => 'Freelance']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $incomes = $this->mapper->findAll('user1');

        $this->assertCount(2, $incomes);
    }

    // ===== findActive =====

    public function testFindActiveReturnsActiveIncomes(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeIncomeRow(['is_active' => 1]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $incomes = $this->mapper->findActive('user1');

        $this->assertCount(1, $incomes);
    }

    // ===== findExpectedInRange =====

    public function testFindExpectedInRangeReturnsIncomes(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeIncomeRow(['next_expected_date' => '2026-03-25']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $incomes = $this->mapper->findExpectedInRange('user1', '2026-03-01', '2026-03-31');

        $this->assertCount(1, $incomes);
    }

    // ===== findByCategory =====

    public function testFindByCategoryReturnsIncomes(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeIncomeRow(['category_id' => 3]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $incomes = $this->mapper->findByCategory('user1', 3);

        $this->assertCount(1, $incomes);
    }

    // ===== findByFrequency =====

    public function testFindByFrequencyReturnsIncomes(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeIncomeRow(['frequency' => 'weekly']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $incomes = $this->mapper->findByFrequency('user1', 'weekly');

        $this->assertCount(1, $incomes);
    }

    // ===== findUpcoming =====

    public function testFindUpcomingReturnsIncomes(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeIncomeRow(),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $incomes = $this->mapper->findUpcoming('user1', 30);

        $this->assertCount(1, $incomes);
    }

    // ===== getMonthlyTotal =====

    public function testGetMonthlyTotalWithMonthlyIncome(): void {
        $mapper = $this->getMockBuilder(RecurringIncomeMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findActive'])
            ->getMock();

        $mapper->method('findActive')->willReturn([
            $this->makeIncome(['amount' => 5000.00, 'frequency' => 'monthly']),
        ]);

        $total = $mapper->getMonthlyTotal('user1');

        $this->assertEquals(5000.00, $total);
    }

    public function testGetMonthlyTotalWithWeeklyIncome(): void {
        $mapper = $this->getMockBuilder(RecurringIncomeMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findActive'])
            ->getMock();

        $mapper->method('findActive')->willReturn([
            $this->makeIncome(['amount' => 500.00, 'frequency' => 'weekly']),
        ]);

        $total = $mapper->getMonthlyTotal('user1');

        // 500 * 52 / 12 ≈ 2166.67
        $this->assertEqualsWithDelta(2166.67, $total, 0.01);
    }

    public function testGetMonthlyTotalWithBiweeklyIncome(): void {
        $mapper = $this->getMockBuilder(RecurringIncomeMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findActive'])
            ->getMock();

        $mapper->method('findActive')->willReturn([
            $this->makeIncome(['amount' => 2000.00, 'frequency' => 'biweekly']),
        ]);

        $total = $mapper->getMonthlyTotal('user1');

        // 2000 * 26 / 12 ≈ 4333.33
        $this->assertEqualsWithDelta(4333.33, $total, 0.01);
    }

    public function testGetMonthlyTotalWithDailyIncome(): void {
        $mapper = $this->getMockBuilder(RecurringIncomeMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findActive'])
            ->getMock();

        $mapper->method('findActive')->willReturn([
            $this->makeIncome(['amount' => 50.00, 'frequency' => 'daily']),
        ]);

        $total = $mapper->getMonthlyTotal('user1');

        // 50 * 30 = 1500
        $this->assertEquals(1500.00, $total);
    }

    public function testGetMonthlyTotalWithQuarterlyIncome(): void {
        $mapper = $this->getMockBuilder(RecurringIncomeMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findActive'])
            ->getMock();

        $mapper->method('findActive')->willReturn([
            $this->makeIncome(['amount' => 3000.00, 'frequency' => 'quarterly']),
        ]);

        $total = $mapper->getMonthlyTotal('user1');

        $this->assertEquals(1000.00, $total);
    }

    public function testGetMonthlyTotalWithYearlyIncome(): void {
        $mapper = $this->getMockBuilder(RecurringIncomeMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findActive'])
            ->getMock();

        $mapper->method('findActive')->willReturn([
            $this->makeIncome(['amount' => 12000.00, 'frequency' => 'yearly']),
        ]);

        $total = $mapper->getMonthlyTotal('user1');

        $this->assertEquals(1000.00, $total);
    }

    public function testGetMonthlyTotalMixedFrequencies(): void {
        $mapper = $this->getMockBuilder(RecurringIncomeMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findActive'])
            ->getMock();

        $mapper->method('findActive')->willReturn([
            $this->makeIncome(['amount' => 5000.00, 'frequency' => 'monthly']),
            $this->makeIncome(['amount' => 500.00, 'frequency' => 'weekly']),
            $this->makeIncome(['amount' => 12000.00, 'frequency' => 'yearly']),
        ]);

        $total = $mapper->getMonthlyTotal('user1');

        // 5000 + (500*52/12) + (12000/12) = 5000 + 2166.67 + 1000 = 8166.67
        $this->assertEqualsWithDelta(8166.67, $total, 0.01);
    }

    public function testGetMonthlyTotalNoIncomes(): void {
        $mapper = $this->getMockBuilder(RecurringIncomeMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findActive'])
            ->getMock();

        $mapper->method('findActive')->willReturn([]);

        $total = $mapper->getMonthlyTotal('user1');

        $this->assertEquals(0.0, $total);
    }

    public function testGetMonthlyTotalUnknownFrequencyUsesRawAmount(): void {
        $mapper = $this->getMockBuilder(RecurringIncomeMapper::class)
            ->setConstructorArgs([$this->db])
            ->onlyMethods(['findActive'])
            ->getMock();

        $mapper->method('findActive')->willReturn([
            $this->makeIncome(['amount' => 100.00, 'frequency' => 'custom']),
        ]);

        $total = $mapper->getMonthlyTotal('user1');

        $this->assertEquals(100.00, $total);
    }

    // ===== updateFields =====

    public function testUpdateFieldsExecutesStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $this->mapper->updateFields(1, 'user1', ['name' => 'Updated']);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(3);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(3, $count);
    }
}
