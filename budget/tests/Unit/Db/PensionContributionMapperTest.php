<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\PensionContribution;
use OCA\Budget\Db\PensionContributionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class PensionContributionMapperTest extends TestCase {
    private PensionContributionMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;
    private IResult $result;
    private IFunctionBuilder $func;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);
        $this->result = $this->createMock(IResult::class);
        $this->func = $this->createMock(IFunctionBuilder::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('func')->willReturn($this->func);
        $this->qb->method('getSQL')->willReturn('');
        $this->qb->method('createNamedParameter')->willReturn(':param');

        $sumFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('sum')->willReturn($sumFunc);

        foreach (['select', 'from', 'where', 'andWhere', 'orderBy',
                   'delete'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new PensionContributionMapper($this->db);
    }

    private function makeContribRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'pension_id' => 10,
            'amount' => 500.00,
            'date' => '2026-03-01',
            'note' => null,
            'created_at' => '2026-03-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_pen_contribs', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsContribution(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeContribRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $contrib = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(PensionContribution::class, $contrib);
        $this->assertEquals(500.00, $contrib->getAmount());
        $this->assertEquals(10, $contrib->getPensionId());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findByPension =====

    public function testFindByPensionReturnsContributions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeContribRow(['id' => 1, 'date' => '2026-03-01']),
                $this->makeContribRow(['id' => 2, 'date' => '2026-02-01']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $contribs = $this->mapper->findByPension(10, 'user1');

        $this->assertCount(2, $contribs);
    }

    // ===== findByPensionInRange =====

    public function testFindByPensionInRangeReturnsContributions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeContribRow(['date' => '2026-01-15']),
                $this->makeContribRow(['date' => '2026-02-15']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $contribs = $this->mapper->findByPensionInRange(10, 'user1', '2026-01-01', '2026-03-01');

        $this->assertCount(2, $contribs);
    }

    public function testFindByPensionInRangeReturnsEmpty(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $contribs = $this->mapper->findByPensionInRange(10, 'user1', '2099-01-01', '2099-12-31');

        $this->assertEmpty($contribs);
    }

    // ===== getTotalByPension =====

    public function testGetTotalByPensionReturnsSum(): void {
        $this->result->method('fetchOne')->willReturn('3500.00');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $total = $this->mapper->getTotalByPension(10, 'user1');

        $this->assertEquals(3500.00, $total);
    }

    public function testGetTotalByPensionReturnsZeroWhenNull(): void {
        $this->result->method('fetchOne')->willReturn(null);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $total = $this->mapper->getTotalByPension(10, 'user1');

        $this->assertEquals(0.0, $total);
    }

    // ===== deleteByPension =====

    public function testDeleteByPensionExecutesStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $this->mapper->deleteByPension(10, 'user1');
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(6);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(6, $count);
    }
}
