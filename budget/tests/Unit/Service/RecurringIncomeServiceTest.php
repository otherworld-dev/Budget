<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCA\Budget\Service\Bill\FrequencyCalculator;
use OCA\Budget\Service\Income\RecurringIncomeDetector;
use OCA\Budget\Service\RecurringIncomeService;
use PHPUnit\Framework\TestCase;

class RecurringIncomeServiceTest extends TestCase {
    private RecurringIncomeService $service;
    private RecurringIncomeMapper $mapper;
    private FrequencyCalculator $frequencyCalculator;
    private RecurringIncomeDetector $recurringDetector;

    protected function setUp(): void {
        $this->mapper = $this->createMock(RecurringIncomeMapper::class);
        $this->frequencyCalculator = $this->createMock(FrequencyCalculator::class);
        $this->recurringDetector = $this->createMock(RecurringIncomeDetector::class);

        $this->service = new RecurringIncomeService(
            $this->mapper,
            $this->frequencyCalculator,
            $this->recurringDetector
        );
    }

    private function makeIncome(array $overrides = []): RecurringIncome {
        $income = new RecurringIncome();
        $defaults = [
            'id' => 1,
            'userId' => 'user1',
            'name' => 'Salary',
            'amount' => 3000.0,
            'frequency' => 'monthly',
            'expectedDay' => 25,
            'isActive' => true,
            'autoDetectPattern' => null,
            'lastReceivedDate' => null,
            'nextExpectedDate' => '2026-04-25',
        ];
        $data = array_merge($defaults, $overrides);

        $income->setId($data['id']);
        $income->setUserId($data['userId']);
        $income->setName($data['name']);
        $income->setAmount($data['amount']);
        $income->setFrequency($data['frequency']);
        $income->setExpectedDay($data['expectedDay']);
        $income->setIsActive($data['isActive']);
        $income->setAutoDetectPattern($data['autoDetectPattern']);
        $income->setLastReceivedDate($data['lastReceivedDate']);
        $income->setNextExpectedDate($data['nextExpectedDate']);

        return $income;
    }

    // ===== CRUD =====

    public function testFindDelegatesToMapper(): void {
        $income = $this->makeIncome();
        $this->mapper->method('find')->with(1, 'user1')->willReturn($income);

        $this->assertSame($income, $this->service->find(1, 'user1'));
    }

    public function testFindAllDelegatesToMapper(): void {
        $this->mapper->method('findAll')->with('user1')->willReturn([]);
        $this->assertEquals([], $this->service->findAll('user1'));
    }

    public function testCreateSetsFieldsAndCalculatesNextDate(): void {
        $this->frequencyCalculator->expects($this->once())->method('calculateNextDueDate')
            ->with('monthly', 25, null)
            ->willReturn('2026-04-25');

        $this->mapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (RecurringIncome $i) {
                $this->assertEquals('user1', $i->getUserId());
                $this->assertEquals('Salary', $i->getName());
                $this->assertEquals(3000.0, $i->getAmount());
                $this->assertEquals('monthly', $i->getFrequency());
                $this->assertEquals(25, $i->getExpectedDay());
                $this->assertEquals('2026-04-25', $i->getNextExpectedDate());
                $this->assertTrue($i->getIsActive());
                $i->setId(1);
                return $i;
            });

        $result = $this->service->create('user1', 'Salary', 3000.0, 'monthly', 25);
        $this->assertEquals('Salary', $result->getName());
    }

    public function testDeleteRemovesIncome(): void {
        $income = $this->makeIncome();
        $this->mapper->method('find')->willReturn($income);
        $this->mapper->expects($this->once())->method('delete')->with($income);

        $this->service->delete(1, 'user1');
    }

    // ===== markReceived =====

    public function testMarkReceivedUpdatesDateAndAdvances(): void {
        $income = $this->makeIncome();
        $this->mapper->method('find')->willReturn($income);

        $this->frequencyCalculator->expects($this->once())->method('calculateNextDueDate')
            ->with('monthly', 25, null, '2026-03-25')
            ->willReturn('2026-04-25');

        $this->mapper->expects($this->once())->method('update')
            ->willReturnCallback(function (RecurringIncome $i) {
                $this->assertEquals('2026-03-25', $i->getLastReceivedDate());
                $this->assertEquals('2026-04-25', $i->getNextExpectedDate());
                return $i;
            });

        $this->service->markReceived(1, 'user1', '2026-03-25');
    }

    // ===== getMonthlySummary =====

    public function testGetMonthlySummaryCalculatesFrequencyBreakdown(): void {
        $monthly = $this->makeIncome(['id' => 1, 'amount' => 3000.0, 'frequency' => 'monthly']);
        $weekly = $this->makeIncome(['id' => 2, 'amount' => 200.0, 'frequency' => 'weekly']);

        $this->mapper->method('findActive')->willReturn([$monthly, $weekly]);

        $result = $this->service->getMonthlySummary('user1');

        $this->assertEquals(2, $result['activeCount']);
        // Monthly: 3000, Weekly: 200 * 52/12 ≈ 866.67
        $this->assertEqualsWithDelta(3866.67, $result['monthlyTotal'], 0.01);
        $this->assertEquals(1, $result['byFrequency']['monthly']['count']);
        $this->assertEquals(1, $result['byFrequency']['weekly']['count']);
    }

    public function testGetMonthlySummaryWithNoIncomes(): void {
        $this->mapper->method('findActive')->willReturn([]);

        $result = $this->service->getMonthlySummary('user1');

        $this->assertEquals(0, $result['activeCount']);
        $this->assertEquals(0.0, $result['monthlyTotal']);
    }

    // ===== getIncomeForMonth =====

    public function testGetIncomeForMonthSumsTotals(): void {
        $i1 = $this->makeIncome(['id' => 1, 'amount' => 3000.0]);
        $i2 = $this->makeIncome(['id' => 2, 'amount' => 500.0]);

        $this->mapper->method('findExpectedInRange')->willReturn([$i1, $i2]);

        $result = $this->service->getIncomeForMonth('user1', 2026, 3);

        $this->assertEquals(3500.0, $result['total']);
        $this->assertEquals(2, $result['count']);
    }

    // ===== matchTransactionToIncome =====

    public function testMatchTransactionToIncomeMatchesPattern(): void {
        $income = $this->makeIncome(['autoDetectPattern' => 'ACME CORP', 'amount' => 3000.0]);
        $this->mapper->method('findActive')->willReturn([$income]);

        $result = $this->service->matchTransactionToIncome('user1', 'Payment from ACME CORP LTD', 3100.0);

        $this->assertNotNull($result);
        $this->assertEquals(1, $result->getId());
    }

    public function testMatchTransactionToIncomeRejectsLargeVariance(): void {
        $income = $this->makeIncome(['autoDetectPattern' => 'ACME CORP', 'amount' => 3000.0]);
        $this->mapper->method('findActive')->willReturn([$income]);

        // 25% variance > 20% allowed
        $result = $this->service->matchTransactionToIncome('user1', 'Payment from ACME CORP', 3750.0);

        $this->assertNull($result);
    }

    public function testMatchTransactionToIncomeSkipsEmptyPatterns(): void {
        $income = $this->makeIncome(['autoDetectPattern' => null]);
        $this->mapper->method('findActive')->willReturn([$income]);

        $result = $this->service->matchTransactionToIncome('user1', 'Payment', 3000.0);

        $this->assertNull($result);
    }

    // ===== detectRecurringIncome =====

    public function testDetectRecurringIncomeDelegatesToDetector(): void {
        $detected = [['description' => 'Salary', 'amount' => 3000.0]];
        $this->recurringDetector->expects($this->once())->method('detectRecurringIncome')
            ->with('user1', 6, false)->willReturn($detected);

        $result = $this->service->detectRecurringIncome('user1');
        $this->assertSame($detected, $result);
    }

    // ===== createFromDetected =====

    public function testCreateFromDetectedCreatesMultiple(): void {
        $detected = [
            ['suggestedName' => 'Salary', 'amount' => 3000.0, 'frequency' => 'monthly', 'expectedDay' => 25],
            ['description' => 'Freelance', 'amount' => 500.0, 'frequency' => 'monthly'],
        ];

        $this->frequencyCalculator->method('calculateNextDueDate')->willReturn('2026-04-01');
        $this->mapper->expects($this->exactly(2))->method('insert')
            ->willReturnCallback(function (RecurringIncome $i) {
                static $id = 0;
                $i->setId(++$id);
                return $i;
            });

        $result = $this->service->createFromDetected('user1', $detected);

        $this->assertCount(2, $result);
        $this->assertEquals('Salary', $result[0]->getName());
        $this->assertEquals('Freelance', $result[1]->getName());
    }
}
