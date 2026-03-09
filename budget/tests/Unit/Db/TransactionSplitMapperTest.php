<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\TransactionSplit;
use OCA\Budget\Db\TransactionSplitMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class TransactionSplitMapperTest extends TestCase {
    private TransactionSplitMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;
    private IFunctionBuilder $func;
    private IResult $result;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);
        $this->func = $this->createMock(IFunctionBuilder::class);
        $this->result = $this->createMock(IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('func')->willReturn($this->func);
        $this->qb->method('getSQL')->willReturn('');
        $this->qb->method('createNamedParameter')->willReturn(':param');

        $sumFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('sum')->willReturn($sumFunc);

        foreach (['select', 'addSelect', 'selectAlias', 'from', 'where', 'andWhere',
                   'orderBy', 'leftJoin', 'innerJoin', 'delete', 'groupBy'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new TransactionSplitMapper($this->db);
    }

    private function makeSplitRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'transaction_id' => 100,
            'category_id' => 5,
            'amount' => '50.00',
            'description' => 'Groceries portion',
            'created_at' => '2026-01-15 10:00:00',
            'category_name' => 'Food',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_tx_splits', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsSplitWithCategory(): void {
        $this->result->method('fetch')->willReturn($this->makeSplitRow());
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $split = $this->mapper->find(1);

        $this->assertInstanceOf(TransactionSplit::class, $split);
        $this->assertEquals(100, $split->getTransactionId());
        $this->assertEquals(5, $split->getCategoryId());
        $this->assertEquals('50.00', $split->getAmount());
        $this->assertEquals('Groceries portion', $split->getDescription());
        $this->assertEquals('Food', $split->getCategoryName());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999);
    }

    public function testFindWithNullCategory(): void {
        $this->result->method('fetch')->willReturn(
            $this->makeSplitRow(['category_id' => null, 'category_name' => null])
        );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $split = $this->mapper->find(1);

        $this->assertNull($split->getCategoryId());
        $this->assertNull($split->getCategoryName());
    }

    // ===== findByTransaction =====

    public function testFindByTransactionReturnsSplits(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeSplitRow(['id' => 1, 'description' => 'Groceries']),
                $this->makeSplitRow(['id' => 2, 'description' => 'Household']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $splits = $this->mapper->findByTransaction(100);

        $this->assertCount(2, $splits);
        $this->assertEquals('Groceries', $splits[0]->getDescription());
        $this->assertEquals('Household', $splits[1]->getDescription());
    }

    public function testFindByTransactionReturnsEmptyForNoSplits(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $splits = $this->mapper->findByTransaction(100);

        $this->assertEmpty($splits);
    }

    // ===== deleteByTransaction =====

    public function testDeleteByTransactionExecutesStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $this->mapper->deleteByTransaction(100);
    }

    // ===== getCategoryTotals =====

    public function testGetCategoryTotalsReturnsEmptyForEmptyInput(): void {
        $this->qb->expects($this->never())->method('executeQuery');

        $result = $this->mapper->getCategoryTotals([]);

        $this->assertEmpty($result);
    }

    public function testGetCategoryTotalsReturnsIndexedByCategoryId(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['category_id' => '5', 'total' => '150.00'],
                ['category_id' => '10', 'total' => '75.00'],
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $totals = $this->mapper->getCategoryTotals([100, 200]);

        $this->assertArrayHasKey(5, $totals);
        $this->assertArrayHasKey(10, $totals);
        $this->assertEquals(150.00, $totals[5]);
        $this->assertEquals(75.00, $totals[10]);
    }

    public function testGetCategoryTotalsNullCategoryIdMappedToNull(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['category_id' => null, 'total' => '50.00'],
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $totals = $this->mapper->getCategoryTotals([100]);

        $this->assertArrayHasKey(null, $totals);
        $this->assertEquals(50.00, $totals[null]);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(5);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(5, $count);
    }
}
