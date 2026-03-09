<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Tag;
use OCA\Budget\Db\TagMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTag;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Service\TransactionTagService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class TransactionTagServiceTest extends TestCase {
    private TransactionTagService $service;
    private TransactionTagMapper $transactionTagMapper;
    private TagMapper $tagMapper;
    private TransactionMapper $transactionMapper;
    private IDBConnection $db;

    protected function setUp(): void {
        $this->transactionTagMapper = $this->createMock(TransactionTagMapper::class);
        $this->tagMapper = $this->createMock(TagMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $this->db = $this->createMock(IDBConnection::class);

        $this->service = new TransactionTagService(
            $this->transactionTagMapper,
            $this->tagMapper,
            $this->transactionMapper,
            $this->db
        );
    }

    private function makeTransaction(int $id, ?int $categoryId = 5): Transaction {
        $t = new Transaction();
        $t->setId($id);
        $t->setCategoryId($categoryId);
        return $t;
    }

    private function makeTag(int $id, int $tagSetId = 1): Tag {
        $tag = new Tag();
        $tag->setId($id);
        $tag->setTagSetId($tagSetId);
        $tag->setName("Tag $id");
        return $tag;
    }

    private function makeTransactionTag(int $transactionId, int $tagId): TransactionTag {
        $tt = new TransactionTag();
        $tt->setTransactionId($transactionId);
        $tt->setTagId($tagId);
        return $tt;
    }

    // ===== setTransactionTags =====

    public function testSetTransactionTagsWithEmptyTagIdsClears(): void {
        $this->transactionMapper->method('find')->willReturn($this->makeTransaction(100));
        $this->transactionTagMapper->expects($this->once())->method('deleteByTransaction')->with(100);

        $result = $this->service->setTransactionTags(100, 'user1', []);

        $this->assertEmpty($result);
    }

    public function testSetTransactionTagsCreatesNewTags(): void {
        $transaction = $this->makeTransaction(100);
        $this->transactionMapper->method('find')->willReturn($transaction);
        $this->transactionTagMapper->method('deleteByTransaction');
        $this->transactionTagMapper->method('insert')->willReturnArgument(0);

        // Mock tag validation
        $tag1 = $this->makeTag(10, 1);
        $tag2 = $this->makeTag(20, 1);
        $this->tagMapper->method('findByIds')->willReturn([10 => $tag1, 20 => $tag2]);

        // Mock DB query for tag set validation
        $qb = $this->createMock(IQueryBuilder::class);
        $expr = $this->createMock(IExpressionBuilder::class);
        $result = $this->createMock(IResult::class);
        $this->db->method('getQueryBuilder')->willReturn($qb);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturn(':param');
        foreach (['select', 'from', 'innerJoin', 'where', 'andWhere'] as $m) {
            $qb->method($m)->willReturnSelf();
        }
        $qb->method('executeQuery')->willReturn($result);
        $result->method('fetch')->willReturn(['id' => 1]); // Found = valid
        $result->method('closeCursor');

        $tags = $this->service->setTransactionTags(100, 'user1', [10, 20]);

        $this->assertCount(2, $tags);
    }

    public function testSetTransactionTagsThrowsWhenTagsDontExist(): void {
        $transaction = $this->makeTransaction(100);
        $this->transactionMapper->method('find')->willReturn($transaction);

        // Return only 1 tag when 2 were requested
        $this->tagMapper->method('findByIds')->willReturn([10 => $this->makeTag(10)]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('One or more tags do not exist');

        $this->service->setTransactionTags(100, 'user1', [10, 20]);
    }

    // ===== getTransactionTags =====

    public function testGetTransactionTagsReturnsTagEntities(): void {
        $this->transactionMapper->method('find');
        $this->transactionTagMapper->method('findByTransaction')->willReturn([
            $this->makeTransactionTag(100, 10),
            $this->makeTransactionTag(100, 20),
        ]);

        $tag1 = $this->makeTag(10);
        $tag2 = $this->makeTag(20);
        $this->tagMapper->method('findByIds')->willReturn([10 => $tag1, 20 => $tag2]);

        $result = $this->service->getTransactionTags(100, 'user1');

        $this->assertCount(2, $result);
    }

    public function testGetTransactionTagsReturnsEmptyWhenNoTags(): void {
        $this->transactionMapper->method('find');
        $this->transactionTagMapper->method('findByTransaction')->willReturn([]);

        $result = $this->service->getTransactionTags(100, 'user1');

        $this->assertEmpty($result);
    }

    // ===== clearTransactionTags =====

    public function testClearTransactionTagsDeletesAll(): void {
        $this->transactionMapper->expects($this->once())->method('find')->with(100, 'user1');
        $this->transactionTagMapper->expects($this->once())->method('deleteByTransaction')->with(100);

        $this->service->clearTransactionTags(100, 'user1');
    }

    // ===== getTagUsageStats =====

    public function testGetTagUsageStatsDelegatesToMapper(): void {
        $expected = [10 => 5, 20 => 3];
        $this->transactionTagMapper->method('getTagUsageStats')->willReturn($expected);

        $result = $this->service->getTagUsageStats('user1');

        $this->assertEquals($expected, $result);
    }
}
