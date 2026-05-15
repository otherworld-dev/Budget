<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\BudgetSnapshot;
use OCA\Budget\Db\BudgetSnapshotMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class BudgetSnapshotMapperTest extends TestCase {
    private BudgetSnapshotMapper $mapper;
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

        foreach (['select', 'selectDistinct', 'selectAlias', 'from', 'where', 'andWhere', 'orderBy', 'delete', 'setMaxResults'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }
        $this->qb->method('createFunction')->willReturn(':func');

        $this->mapper = new BudgetSnapshotMapper($this->db);
    }

    private function makeSnapshotRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'category_id' => 5,
            'effective_from' => '2026-05',
            'amount' => 200.00,
            'period' => 'monthly',
            'created_at' => '2026-05-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_bgt_snapshots', $this->mapper->getTableName());
    }

    // ===== findByMonth =====

    public function testFindByMonthReturnsSnapshots(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeSnapshotRow(['id' => 1, 'category_id' => 5, 'amount' => 200.00]),
                $this->makeSnapshotRow(['id' => 2, 'category_id' => 8, 'amount' => 150.00]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $snapshots = $this->mapper->findByMonth('user1', '2026-05');

        $this->assertCount(2, $snapshots);
        $this->assertInstanceOf(BudgetSnapshot::class, $snapshots[0]);
        $this->assertEquals(5, $snapshots[0]->getCategoryId());
        $this->assertEquals(150.00, $snapshots[1]->getAmount());
    }

    // ===== hasSnapshot =====

    public function testHasSnapshotReturnsTrueWhenExists(): void {
        $this->result->method('fetchOne')->willReturn('2');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->assertTrue($this->mapper->hasSnapshot('user1', '2026-05'));
    }

    public function testHasSnapshotReturnsFalseWhenNone(): void {
        $this->result->method('fetchOne')->willReturn('0');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->assertFalse($this->mapper->hasSnapshot('user1', '2026-12'));
    }

    // ===== getSnapshotMonths =====

    public function testGetSnapshotMonthsReturnsStrings(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['effective_from' => '2026-05'],
                ['effective_from' => '2026-04'],
                ['effective_from' => '2026-03'],
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $months = $this->mapper->getSnapshotMonths('user1');

        $this->assertEquals(['2026-05', '2026-04', '2026-03'], $months);
    }

    public function testGetSnapshotMonthsReturnsEmptyWhenNone(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $months = $this->mapper->getSnapshotMonths('user1');

        $this->assertEmpty($months);
    }

    // ===== deleteByMonth =====

    public function testDeleteByMonthCallsExecuteStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $this->mapper->deleteByMonth('user1', '2026-05');
    }
}
