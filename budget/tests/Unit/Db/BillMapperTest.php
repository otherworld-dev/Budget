<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Bill;
use OCA\Budget\Db\BillMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class BillMapperTest extends TestCase {
    private BillMapper $mapper;
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

        $this->mapper = new BillMapper($this->db);
    }

    private function makeBillRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'name' => 'Rent',
            'amount' => 1200.00,
            'frequency' => 'monthly',
            'due_day' => 1,
            'due_month' => null,
            'category_id' => 5,
            'account_id' => 1,
            'auto_detect_pattern' => null,
            'is_active' => 1,
            'last_paid_date' => '2026-02-01',
            'next_due_date' => '2026-03-01',
            'notes' => null,
            'created_at' => '2026-01-01 00:00:00',
            'reminder_days' => 3,
            'last_reminder_sent' => null,
            'custom_recurrence_pattern' => null,
            'auto_pay_enabled' => 0,
            'auto_pay_failed' => 0,
            'is_transfer' => 0,
            'destination_account_id' => null,
            'transfer_description_pattern' => null,
            'tag_ids' => null,
            'end_date' => null,
            'remaining_payments' => null,
        ], $overrides);
    }

    private function makeBill(array $overrides = []): Bill {
        $bill = new Bill();
        $defaults = [
            'id' => 1,
            'userId' => 'user1',
            'name' => 'Rent',
            'amount' => 1200.00,
            'frequency' => 'monthly',
            'isActive' => true,
        ];
        $data = array_merge($defaults, $overrides);

        $bill->setId($data['id']);
        $bill->setUserId($data['userId']);
        $bill->setName($data['name']);
        $bill->setAmount($data['amount']);
        $bill->setFrequency($data['frequency']);
        $bill->setIsActive($data['isActive']);
        return $bill;
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_bills', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsBill(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeBillRow(),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $bill = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(Bill::class, $bill);
        $this->assertEquals('Rent', $bill->getName());
        $this->assertEquals(1200.00, $bill->getAmount());
        $this->assertEquals('monthly', $bill->getFrequency());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findAll =====

    public function testFindAllReturnsBills(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeBillRow(['id' => 1, 'name' => 'Rent']),
                $this->makeBillRow(['id' => 2, 'name' => 'Internet']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $bills = $this->mapper->findAll('user1');

        $this->assertCount(2, $bills);
        $this->assertEquals('Rent', $bills[0]->getName());
        $this->assertEquals('Internet', $bills[1]->getName());
    }

    // ===== findActive =====

    public function testFindActiveReturnsActiveBills(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeBillRow(['id' => 1, 'is_active' => 1]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $bills = $this->mapper->findActive('user1');

        $this->assertCount(1, $bills);
    }

    // ===== findDueInRange =====

    public function testFindDueInRangeReturnsBillsInRange(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeBillRow(['next_due_date' => '2026-03-15']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $bills = $this->mapper->findDueInRange('user1', '2026-03-01', '2026-03-31');

        $this->assertCount(1, $bills);
        $this->assertEquals('2026-03-15', $bills[0]->getNextDueDate());
    }

    // ===== findByCategory =====

    public function testFindByCategoryReturnsBills(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeBillRow(['category_id' => 5]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $bills = $this->mapper->findByCategory('user1', 5);

        $this->assertCount(1, $bills);
        $this->assertEquals(5, $bills[0]->getCategoryId());
    }

    // ===== findByFrequency =====

    public function testFindByFrequencyReturnsBills(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeBillRow(['frequency' => 'weekly', 'amount' => 50.00]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $bills = $this->mapper->findByFrequency('user1', 'weekly');

        $this->assertCount(1, $bills);
        $this->assertEquals('weekly', $bills[0]->getFrequency());
    }

    // ===== findByType =====

    public function testFindByTypeReturnsTransfers(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeBillRow(['is_transfer' => 1]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $bills = $this->mapper->findByType('user1', true, null);

        $this->assertCount(1, $bills);
    }

    public function testFindByTypeWithNullFiltersReturnsAll(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeBillRow(['id' => 1]),
                $this->makeBillRow(['id' => 2]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $bills = $this->mapper->findByType('user1', null, null);

        $this->assertCount(2, $bills);
    }

    // ===== findOverdue =====

    public function testFindOverdueReturnsPastDueBills(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeBillRow(['next_due_date' => '2026-01-01']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $bills = $this->mapper->findOverdue('user1');

        $this->assertCount(1, $bills);
    }

    // ===== updateFields =====

    public function testUpdateFieldsExecutesStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $this->mapper->updateFields(1, 'user1', ['name' => 'Updated Bill']);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(3);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(3, $count);
    }
}
