<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\QueryFilterBuilder;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Abstract stub that adds methods present on the real QueryBuilder but missing
 * from the OCP IQueryBuilder interface, so PHPUnit can mock them.
 */
abstract class QueryBuilderWithExtras implements IQueryBuilder {
    abstract public function escapeLikeParameter(string $parameter): string;
    abstract public function distinct(): static;
}

class QueryFilterBuilderTest extends TestCase {
    private QueryFilterBuilder $builder;
    private QueryBuilderWithExtras $qb;
    private IExpressionBuilder $expr;

    protected function setUp(): void {
        $this->builder = new QueryFilterBuilder();
        $this->expr = $this->createMock(IExpressionBuilder::class);

        $this->qb = $this->createMock(QueryBuilderWithExtras::class);

        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('createNamedParameter')->willReturn(':param');
        $this->qb->method('escapeLikeParameter')->willReturnCallback(fn($v) => $v);

        // Fluent methods
        $this->qb->method('andWhere')->willReturnSelf();
        $this->qb->method('innerJoin')->willReturnSelf();
        $this->qb->method('distinct')->willReturnSelf();
        $this->qb->method('orderBy')->willReturnSelf();
        $this->qb->method('addOrderBy')->willReturnSelf();
        $this->qb->method('setMaxResults')->willReturnSelf();
        $this->qb->method('setFirstResult')->willReturnSelf();
    }

    // ===== getSupportedFilters / getSupportedSortFields =====

    public function testGetSupportedFiltersReturnsExpectedKeys(): void {
        $filters = $this->builder->getSupportedFilters();

        $this->assertContains('accountId', $filters);
        $this->assertContains('category', $filters);
        $this->assertContains('type', $filters);
        $this->assertContains('dateFrom', $filters);
        $this->assertContains('dateTo', $filters);
        $this->assertContains('amountMin', $filters);
        $this->assertContains('amountMax', $filters);
        $this->assertContains('search', $filters);
        $this->assertContains('reconciled', $filters);
        $this->assertContains('status', $filters);
        $this->assertContains('vendor', $filters);
        $this->assertContains('tagIds', $filters);
    }

    public function testGetSupportedSortFieldsReturnsExpectedKeys(): void {
        $fields = $this->builder->getSupportedSortFields();

        $this->assertContains('date', $fields);
        $this->assertContains('description', $fields);
        $this->assertContains('amount', $fields);
        $this->assertContains('type', $fields);
        $this->assertContains('category', $fields);
        $this->assertContains('account', $fields);
        $this->assertContains('vendor', $fields);
        $this->assertContains('reconciled', $fields);
        $this->assertContains('status', $fields);
    }

    // ===== applyTransactionFilters =====

    public function testEmptyFiltersDoNotCallAndWhere(): void {
        $this->qb->expects($this->never())->method('andWhere');

        $this->builder->applyTransactionFilters($this->qb, [], 't');
    }

    public function testAccountIdFilterAppliesEq(): void {
        $this->expr->expects($this->once())
            ->method('eq')
            ->with('t.account_id', ':param');

        $this->qb->expects($this->once())->method('andWhere');

        $this->builder->applyTransactionFilters($this->qb, ['accountId' => 5], 't');
    }

    public function testCategoryFilterAppliesEq(): void {
        $this->expr->expects($this->once())
            ->method('eq')
            ->with('t.category_id', ':param');

        $this->builder->applyTransactionFilters($this->qb, ['category' => 10], 't');
    }

    public function testUncategorizedFilterUsesIsNull(): void {
        $this->expr->expects($this->once())
            ->method('isNull')
            ->with('t.category_id');

        $this->expr->expects($this->never())->method('eq');

        $this->builder->applyTransactionFilters($this->qb, ['category' => 'uncategorized'], 't');
    }

    public function testTypeFilterAppliesEq(): void {
        $this->expr->expects($this->once())
            ->method('eq')
            ->with('t.type', ':param');

        $this->builder->applyTransactionFilters($this->qb, ['type' => 'debit'], 't');
    }

    public function testDateRangeFiltersApplyGteAndLte(): void {
        $this->expr->expects($this->once())->method('gte')->with('t.date', ':param');
        $this->expr->expects($this->once())->method('lte')->with('t.date', ':param');

        $this->builder->applyTransactionFilters($this->qb, [
            'dateFrom' => '2026-01-01',
            'dateTo' => '2026-01-31',
        ], 't');
    }

    public function testAmountRangeFiltersApplyGteAndLte(): void {
        $this->expr->expects($this->once())->method('gte')->with('t.amount', ':param');
        $this->expr->expects($this->once())->method('lte')->with('t.amount', ':param');

        $this->builder->applyTransactionFilters($this->qb, [
            'amountMin' => '10.00',
            'amountMax' => '100.00',
        ], 't');
    }

    public function testSearchFilterUsesLikeOnMultipleFields(): void {
        $this->expr->expects($this->exactly(4))
            ->method('like');

        $this->expr->expects($this->once())->method('orX');

        $this->builder->applyTransactionFilters($this->qb, ['search' => 'coffee'], 't');
    }

    public function testReconciledTrueFilterUsesInt1(): void {
        $this->qb->expects($this->once())
            ->method('createNamedParameter')
            ->with(1, IQueryBuilder::PARAM_INT)
            ->willReturn(':param');

        $this->builder->applyTransactionFilters($this->qb, ['reconciled' => true], 't');
    }

    public function testReconciledFalseFilterUsesInt0(): void {
        $this->qb->expects($this->once())
            ->method('createNamedParameter')
            ->with(0, IQueryBuilder::PARAM_INT)
            ->willReturn(':param');

        $this->builder->applyTransactionFilters($this->qb, ['reconciled' => false], 't');
    }

    public function testStatusScheduledFilterUsesEq(): void {
        $this->expr->expects($this->once())
            ->method('eq')
            ->with('t.status', ':param');

        $this->builder->applyTransactionFilters($this->qb, ['status' => 'scheduled'], 't');
    }

    public function testStatusClearedFilterUsesOrX(): void {
        $this->expr->expects($this->once())->method('orX');
        $this->expr->expects($this->once())->method('isNull')->with('t.status');

        $this->builder->applyTransactionFilters($this->qb, ['status' => 'cleared'], 't');
    }

    public function testVendorFilterAppliesEq(): void {
        $this->expr->expects($this->once())
            ->method('eq')
            ->with('t.vendor', ':param');

        $this->builder->applyTransactionFilters($this->qb, ['vendor' => 'Amazon'], 't');
    }

    public function testTagIdsFilterJoinsAndUsesIn(): void {
        $this->qb->expects($this->once())->method('innerJoin');
        $this->qb->expects($this->once())->method('distinct');
        $this->expr->expects($this->once())->method('in');

        $this->builder->applyTransactionFilters($this->qb, ['tagIds' => [1, 2, 3]], 't');
    }

    public function testCustomAliasIsUsed(): void {
        $this->expr->expects($this->once())
            ->method('eq')
            ->with('tx.account_id', ':param');

        $this->builder->applyTransactionFilters($this->qb, ['accountId' => 1], 'tx');
    }

    // ===== applySorting =====

    public function testDefaultSortIsDateDesc(): void {
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('t.date', 'DESC');

        $this->qb->expects($this->once())
            ->method('addOrderBy')
            ->with('t.id', 'DESC');

        $this->builder->applySorting($this->qb, null, null, 't');
    }

    public function testSortByAmountAsc(): void {
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('t.amount', 'ASC');

        $this->builder->applySorting($this->qb, 'amount', 'asc', 't');
    }

    public function testSortFieldMappings(): void {
        // Test that 'category' maps to 'category_id'
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('t.category_id', 'DESC');

        $this->builder->applySorting($this->qb, 'category', 'desc', 't');
    }

    public function testSortFieldMappingAccount(): void {
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('t.account_id', 'DESC');

        $this->builder->applySorting($this->qb, 'account', null, 't');
    }

    public function testUnknownSortFieldFallsBackToDate(): void {
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('t.date', 'DESC');

        $this->builder->applySorting($this->qb, 'nonexistent', null, 't');
    }

    public function testSortWithCustomAlias(): void {
        $this->qb->expects($this->once())
            ->method('orderBy')
            ->with('tx.date', 'DESC');

        $this->qb->expects($this->once())
            ->method('addOrderBy')
            ->with('tx.id', 'DESC');

        $this->builder->applySorting($this->qb, 'date', 'desc', 'tx');
    }

    // ===== applyPagination =====

    public function testPaginationSetsLimitAndOffset(): void {
        $this->qb->expects($this->once())
            ->method('setMaxResults')
            ->with(25);

        $this->qb->expects($this->once())
            ->method('setFirstResult')
            ->with(50);

        $this->builder->applyPagination($this->qb, 25, 50);
    }

    public function testPaginationZeroOffset(): void {
        $this->qb->expects($this->once())
            ->method('setMaxResults')
            ->with(10);

        $this->qb->expects($this->once())
            ->method('setFirstResult')
            ->with(0);

        $this->builder->applyPagination($this->qb, 10, 0);
    }
}
