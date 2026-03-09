<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\TransactionTag;
use OCA\Budget\Db\TransactionTagMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class TransactionTagMapperTest extends TestCase {
    private TransactionTagMapper $mapper;
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

        $mockFunction = $this->createMock(IQueryFunction::class);
        $this->qb->method('createFunction')->willReturn($mockFunction);
        $this->func->method('count')->willReturn($mockFunction);

        foreach (['select', 'selectAlias', 'from', 'where', 'andWhere',
                   'orderBy', 'innerJoin', 'delete', 'groupBy',
                   'insert', 'setValue'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new TransactionTagMapper($this->db);
    }

    private function makeTagRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'transaction_id' => 100,
            'tag_id' => 5,
            'created_at' => '2026-01-15 10:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_transaction_tags', $this->mapper->getTableName());
    }

    // ===== findByTransaction =====

    public function testFindByTransactionReturnsTags(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTagRow(['tag_id' => 5]),
                $this->makeTagRow(['id' => 2, 'tag_id' => 10]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $tags = $this->mapper->findByTransaction(100);

        $this->assertCount(2, $tags);
        $this->assertInstanceOf(TransactionTag::class, $tags[0]);
        $this->assertEquals(5, $tags[0]->getTagId());
        $this->assertEquals(10, $tags[1]->getTagId());
    }

    public function testFindByTransactionReturnsEmptyWhenNone(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $tags = $this->mapper->findByTransaction(100);

        $this->assertEmpty($tags);
    }

    // ===== findTransactionIdsByTags =====

    public function testFindTransactionIdsByTagsReturnsEmptyForEmptyInput(): void {
        $this->qb->expects($this->never())->method('executeQuery');

        $result = $this->mapper->findTransactionIdsByTags([], 'user1');

        $this->assertEmpty($result);
    }

    public function testFindTransactionIdsByTagsReturnsIntArray(): void {
        $this->result->method('fetchAll')->willReturn(['100', '200', '300']);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $ids = $this->mapper->findTransactionIdsByTags([5, 10], 'user1');

        $this->assertEquals([100, 200, 300], $ids);
    }

    // ===== deleteByTransaction =====

    public function testDeleteByTransactionReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(3);

        $count = $this->mapper->deleteByTransaction(100);

        $this->assertEquals(3, $count);
    }

    // ===== deleteByTag =====

    public function testDeleteByTagReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(5);

        $count = $this->mapper->deleteByTag(5);

        $this->assertEquals(5, $count);
    }

    // ===== insertBatch =====

    public function testInsertBatchDoesNothingForEmptyArray(): void {
        $this->qb->expects($this->never())->method('executeStatement');

        $this->mapper->insertBatch([]);
    }

    // ===== getTagUsageStats =====

    public function testGetTagUsageStatsReturnsIndexedByTagId(): void {
        $this->result->method('fetchAll')->willReturn([
            ['tag_id' => '5', 'usage_count' => '15'],
            ['tag_id' => '10', 'usage_count' => '3'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $stats = $this->mapper->getTagUsageStats('user1');

        $this->assertArrayHasKey(5, $stats);
        $this->assertArrayHasKey(10, $stats);
        $this->assertEquals(15, $stats[5]);
        $this->assertEquals(3, $stats[10]);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsZeroWhenNoTags(): void {
        $this->result->method('fetchAll')->willReturn([]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(0, $count);
    }

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->result->method('fetchAll')->willReturn(['1', '2', '3']);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->qb->method('executeStatement')->willReturn(3);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(3, $count);
    }

    // ===== sumTransactionAmountsByTag =====

    public function testSumTransactionAmountsByTagReturnsFloat(): void {
        $this->result->method('fetchOne')->willReturn('450.75');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $sum = $this->mapper->sumTransactionAmountsByTag(5, 'user1');

        $this->assertEquals(450.75, $sum);
    }

    public function testSumTransactionAmountsByTagReturnsZeroForNull(): void {
        $this->result->method('fetchOne')->willReturn(null);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $sum = $this->mapper->sumTransactionAmountsByTag(5, 'user1');

        $this->assertEquals(0.0, $sum);
    }

    // ===== sumTransactionAmountsByTags =====

    public function testSumTransactionAmountsByTagsReturnsEmptyForEmptyInput(): void {
        $this->qb->expects($this->never())->method('executeQuery');

        $result = $this->mapper->sumTransactionAmountsByTags([], 'user1');

        $this->assertEmpty($result);
    }

    public function testSumTransactionAmountsByTagsReturnsIndexedByTagId(): void {
        $this->result->method('fetchAll')->willReturn([
            ['tag_id' => '5', 'amount_sum' => '300.00'],
            ['tag_id' => '10', 'amount_sum' => '150.50'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $sums = $this->mapper->sumTransactionAmountsByTags([5, 10], 'user1');

        $this->assertArrayHasKey(5, $sums);
        $this->assertArrayHasKey(10, $sums);
        $this->assertEquals(300.00, $sums[5]);
        $this->assertEquals(150.50, $sums[10]);
    }
}
