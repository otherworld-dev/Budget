<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\InterestRate;
use OCA\Budget\Db\InterestRateMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class InterestRateMapperTest extends TestCase {
    private InterestRateMapper $mapper;
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

        $this->mapper = new InterestRateMapper($this->db);
    }

    private function makeRateRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'account_id' => 10,
            'user_id' => 'user1',
            'rate' => 3.5,
            'compounding_frequency' => 'monthly',
            'effective_date' => '2026-01-01',
            'created_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_interest_rates', $this->mapper->getTableName());
    }

    // ===== findByAccount =====

    public function testFindByAccountReturnsRates(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeRateRow(['id' => 1, 'rate' => 3.5, 'effective_date' => '2025-01-01']),
                $this->makeRateRow(['id' => 2, 'rate' => 4.0, 'effective_date' => '2026-01-01']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $rates = $this->mapper->findByAccount(10, 'user1');

        $this->assertCount(2, $rates);
        $this->assertInstanceOf(InterestRate::class, $rates[0]);
        $this->assertEquals(3.5, $rates[0]->getRate());
        $this->assertEquals(4.0, $rates[1]->getRate());
    }

    // ===== findEffectiveRate =====

    public function testFindEffectiveRateReturnsRate(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeRateRow(['rate' => 4.25, 'effective_date' => '2026-01-01']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $rate = $this->mapper->findEffectiveRate(10, 'user1', '2026-05-15');

        $this->assertInstanceOf(InterestRate::class, $rate);
        $this->assertEquals(4.25, $rate->getRate());
    }

    public function testFindEffectiveRateReturnsNullWhenNoneExists(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $rate = $this->mapper->findEffectiveRate(10, 'user1', '2024-01-01');

        $this->assertNull($rate);
    }

    // ===== deleteByAccount =====

    public function testDeleteByAccountCallsExecuteStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $this->mapper->deleteByAccount(10, 'user1');
    }

    // ===== countByAccount =====

    public function testCountByAccountReturnsCount(): void {
        $this->result->method('fetchOne')->willReturn('3');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $count = $this->mapper->countByAccount(10, 'user1');

        $this->assertEquals(3, $count);
    }
}
