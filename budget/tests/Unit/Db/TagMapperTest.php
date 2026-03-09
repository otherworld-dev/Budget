<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Tag;
use OCA\Budget\Db\TagMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class TagMapperTest extends TestCase {
    private TagMapper $mapper;
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
                   'addOrderBy', 'innerJoin', 'delete'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new TagMapper($this->db);
    }

    private function makeTagRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'tag_set_id' => 10,
            'name' => 'Essential',
            'color' => '#ff0000',
            'sort_order' => 0,
            'created_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_tags', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsTag(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeTagRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $tag = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(Tag::class, $tag);
        $this->assertEquals('Essential', $tag->getName());
        $this->assertEquals(10, $tag->getTagSetId());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findByTagSet =====

    public function testFindByTagSetReturnsTags(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTagRow(['id' => 1, 'name' => 'Essential']),
                $this->makeTagRow(['id' => 2, 'name' => 'Luxury']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $tags = $this->mapper->findByTagSet(10);

        $this->assertCount(2, $tags);
        $this->assertEquals('Essential', $tags[0]->getName());
        $this->assertEquals('Luxury', $tags[1]->getName());
    }

    // ===== findByTagSets =====

    public function testFindByTagSetsReturnsEmptyForEmptyInput(): void {
        $this->qb->expects($this->never())->method('executeQuery');

        $result = $this->mapper->findByTagSets([]);

        $this->assertEmpty($result);
    }

    public function testFindByTagSetsReturnsGroupedByTagSetId(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTagRow(['id' => 1, 'tag_set_id' => 10, 'name' => 'A']),
                $this->makeTagRow(['id' => 2, 'tag_set_id' => 10, 'name' => 'B']),
                $this->makeTagRow(['id' => 3, 'tag_set_id' => 20, 'name' => 'C']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $result = $this->mapper->findByTagSets([10, 20]);

        $this->assertArrayHasKey(10, $result);
        $this->assertArrayHasKey(20, $result);
        $this->assertCount(2, $result[10]);
        $this->assertCount(1, $result[20]);
        $this->assertEquals('A', $result[10][0]->getName());
        $this->assertEquals('C', $result[20][0]->getName());
    }

    // ===== findByIds =====

    public function testFindByIdsReturnsEmptyForEmptyInput(): void {
        $this->qb->expects($this->never())->method('executeQuery');

        $result = $this->mapper->findByIds([]);

        $this->assertEmpty($result);
    }

    public function testFindByIdsReturnsIndexedById(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTagRow(['id' => 5, 'name' => 'A']),
                $this->makeTagRow(['id' => 10, 'name' => 'B']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $result = $this->mapper->findByIds([5, 10]);

        $this->assertArrayHasKey(5, $result);
        $this->assertArrayHasKey(10, $result);
        $this->assertEquals('A', $result[5]->getName());
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
        $this->result->method('fetchAll')->willReturn(['1', '2']);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->qb->method('executeStatement')->willReturn(2);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(2, $count);
    }
}
