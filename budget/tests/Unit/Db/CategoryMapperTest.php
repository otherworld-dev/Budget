<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class CategoryMapperTest extends TestCase {
    private CategoryMapper $mapper;
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

        foreach (['select', 'selectAlias', 'from', 'where', 'andWhere',
                   'orderBy', 'addOrderBy', 'delete'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new CategoryMapper($this->db);
    }

    private function makeCategoryRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'name' => 'Food',
            'type' => 'expense',
            'parent_id' => null,
            'icon' => '🍔',
            'color' => '#ff0000',
            'budget_amount' => 500.00,
            'budget_period' => 'monthly',
            'sort_order' => 0,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_categories', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsCategory(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeCategoryRow(),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $category = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(Category::class, $category);
        $this->assertEquals('Food', $category->getName());
        $this->assertEquals('expense', $category->getType());
        $this->assertNull($category->getParentId());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findAll =====

    public function testFindAllReturnsCategories(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeCategoryRow(['id' => 1, 'name' => 'Food']),
                $this->makeCategoryRow(['id' => 2, 'name' => 'Transport']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $categories = $this->mapper->findAll('user1');

        $this->assertCount(2, $categories);
        $this->assertEquals('Food', $categories[0]->getName());
        $this->assertEquals('Transport', $categories[1]->getName());
    }

    // ===== findByType =====

    public function testFindByTypeReturnsMatchingCategories(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeCategoryRow(['type' => 'income', 'name' => 'Salary']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $categories = $this->mapper->findByType('user1', 'income');

        $this->assertCount(1, $categories);
        $this->assertEquals('income', $categories[0]->getType());
    }

    // ===== findChildren =====

    public function testFindChildrenReturnsSubcategories(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeCategoryRow(['id' => 10, 'parent_id' => 1, 'name' => 'Groceries']),
                $this->makeCategoryRow(['id' => 11, 'parent_id' => 1, 'name' => 'Restaurant']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $children = $this->mapper->findChildren('user1', 1);

        $this->assertCount(2, $children);
        $this->assertEquals(1, $children[0]->getParentId());
        $this->assertEquals('Groceries', $children[0]->getName());
    }

    // ===== findRootCategories =====

    public function testFindRootCategoriesReturnsTopLevel(): void {
        $this->expr->expects($this->once())->method('isNull')->with('parent_id');

        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeCategoryRow(['id' => 1, 'parent_id' => null, 'name' => 'Food']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $roots = $this->mapper->findRootCategories('user1');

        $this->assertCount(1, $roots);
        $this->assertNull($roots[0]->getParentId());
    }

    // ===== getCategorySpending =====

    public function testGetCategorySpendingReturnsFloat(): void {
        $sumFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('sum')->willReturn($sumFunc);
        $this->result->method('fetchOne')->willReturn('450.75');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $spending = $this->mapper->getCategorySpending(1, '2026-01-01', '2026-01-31');

        $this->assertEquals(450.75, $spending);
    }

    public function testGetCategorySpendingReturnsZeroForNoTransactions(): void {
        $sumFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('sum')->willReturn($sumFunc);
        $this->result->method('fetchOne')->willReturn(null);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $spending = $this->mapper->getCategorySpending(1, '2026-01-01', '2026-01-31');

        $this->assertEquals(0.0, $spending);
    }

    // ===== findByIds =====

    public function testFindByIdsReturnsEmptyForEmptyInput(): void {
        // Should not execute any query
        $this->qb->expects($this->never())->method('executeQuery');

        $result = $this->mapper->findByIds([], 'user1');

        $this->assertEmpty($result);
    }

    public function testFindByIdsReturnsIndexedById(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeCategoryRow(['id' => 5, 'name' => 'Food']),
                $this->makeCategoryRow(['id' => 10, 'name' => 'Transport']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $result = $this->mapper->findByIds([5, 10], 'user1');

        $this->assertCount(2, $result);
        $this->assertArrayHasKey(5, $result);
        $this->assertArrayHasKey(10, $result);
        $this->assertEquals('Food', $result[5]->getName());
        $this->assertEquals('Transport', $result[10]->getName());
    }

    public function testFindByIdsSkipsMissingIds(): void {
        // Only ID 5 found, ID 10 not in DB
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeCategoryRow(['id' => 5, 'name' => 'Food']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $result = $this->mapper->findByIds([5, 10], 'user1');

        $this->assertCount(1, $result);
        $this->assertArrayHasKey(5, $result);
        $this->assertArrayNotHasKey(10, $result);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(8);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(8, $count);
    }
}
