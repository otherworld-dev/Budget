<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\SavingsGoal;
use OCA\Budget\Db\SavingsGoalMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class SavingsGoalMapperTest extends TestCase {
    private SavingsGoalMapper $mapper;
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
                   'delete', 'update', 'set'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new SavingsGoalMapper($this->db);
    }

    private function makeGoalRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'name' => 'Emergency Fund',
            'target_amount' => 10000.00,
            'current_amount' => 5000.00,
            'target_months' => 12,
            'description' => null,
            'target_date' => '2026-12-31',
            'tag_id' => null,
            'created_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_savings_goals', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsGoal(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeGoalRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $goal = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(SavingsGoal::class, $goal);
        $this->assertEquals('Emergency Fund', $goal->getName());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findAll =====

    public function testFindAllReturnsGoals(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeGoalRow(['id' => 1, 'name' => 'Emergency Fund']),
                $this->makeGoalRow(['id' => 2, 'name' => 'Vacation']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $goals = $this->mapper->findAll('user1');

        $this->assertCount(2, $goals);
    }

    // ===== clearTagReference =====

    public function testClearTagReferenceReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(2);

        $count = $this->mapper->clearTagReference(5);

        $this->assertEquals(2, $count);
    }

    public function testClearTagReferenceReturnsZeroWhenNoMatch(): void {
        $this->qb->method('executeStatement')->willReturn(0);

        $count = $this->mapper->clearTagReference(999);

        $this->assertEquals(0, $count);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(3);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(3, $count);
    }
}
