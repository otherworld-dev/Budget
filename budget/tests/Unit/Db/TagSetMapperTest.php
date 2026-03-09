<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\TagSet;
use OCA\Budget\Db\TagSetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class TagSetMapperTest extends TestCase {
    private TagSetMapper $mapper;
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

        $this->mapper = new TagSetMapper($this->db);
    }

    private function makeTagSetRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'category_id' => 5,
            'name' => 'Priority',
            'description' => 'Priority level',
            'sort_order' => 0,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_tag_sets', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsTagSet(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeTagSetRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $tagSet = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(TagSet::class, $tagSet);
        $this->assertEquals('Priority', $tagSet->getName());
        $this->assertEquals(5, $tagSet->getCategoryId());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findByCategory =====

    public function testFindByCategoryReturnsTagSets(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTagSetRow(['id' => 1, 'name' => 'Priority']),
                $this->makeTagSetRow(['id' => 2, 'name' => 'Location']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $tagSets = $this->mapper->findByCategory(5, 'user1');

        $this->assertCount(2, $tagSets);
        $this->assertEquals('Priority', $tagSets[0]->getName());
    }

    // ===== findAll =====

    public function testFindAllReturnsTagSets(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTagSetRow(['id' => 1]),
                $this->makeTagSetRow(['id' => 2]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $tagSets = $this->mapper->findAll('user1');

        $this->assertCount(2, $tagSets);
    }

    // ===== findByIds =====

    public function testFindByIdsReturnsEmptyForEmptyInput(): void {
        $this->qb->expects($this->never())->method('executeQuery');

        $result = $this->mapper->findByIds([], 'user1');

        $this->assertEmpty($result);
    }

    public function testFindByIdsReturnsIndexedById(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTagSetRow(['id' => 5, 'name' => 'Priority']),
                $this->makeTagSetRow(['id' => 10, 'name' => 'Location']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $result = $this->mapper->findByIds([5, 10], 'user1');

        $this->assertArrayHasKey(5, $result);
        $this->assertArrayHasKey(10, $result);
        $this->assertEquals('Priority', $result[5]->getName());
        $this->assertEquals('Location', $result[10]->getName());
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsZeroWhenNoTagSets(): void {
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
}
