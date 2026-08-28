<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\QueryFilterBuilder;
use OCP\DB\QueryBuilder\ICompositeExpression;
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
    /** @var string[] Tables named via getTableName(), i.e. subquery targets */
    private array $tablesNamed = [];
    /** @var array{eq: string[], in: string[], andX: array[]} recorded by recordCategoryExpression() */
    private array $exprCalls = ['eq' => [], 'in' => [], 'andX' => []];

    protected function setUp(): void {
        $this->builder = new QueryFilterBuilder();
        $this->expr = $this->createMock(IExpressionBuilder::class);

        $this->qb = $this->createMock(QueryBuilderWithExtras::class);

        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('createNamedParameter')->willReturn(':param');
        $this->qb->method('escapeLikeParameter')->willReturnCallback(fn($v) => $v);
        $this->tablesNamed = [];
        $this->qb->method('getTableName')->willReturnCallback(function ($table) {
            $this->tablesNamed[] = $table;
            return '`*PREFIX*' . $table . '`';
        });

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

    /**
     * The category filter builds `own category OR (split part AND is a split)`,
     * so several columns pass through eq(). These record which, rather than
     * counting calls, which says nothing once the expression has branches.
     *
     * Results land in $this->exprCalls.
     */
    private function recordCategoryExpression(): void {
        $this->exprCalls = ['eq' => [], 'in' => [], 'andX' => []];
        $composite = $this->createMock(ICompositeExpression::class);

        $this->expr->method('eq')->willReturnCallback(function (...$args) {
            $this->exprCalls['eq'][] = (string)$args[0];
            return $args[0] . ' = ' . $args[1];
        });
        $this->expr->method('in')->willReturnCallback(function (...$args) {
            $this->exprCalls['in'][] = (string)$args[0];
            return $args[0] . ' IN (' . $args[1] . ')';
        });
        $this->expr->method('isNull')->willReturnCallback(fn(string $c) => $c . ' IS NULL');
        $this->expr->method('andX')->willReturnCallback(function (...$args) use ($composite) {
            $this->exprCalls['andX'][] = $args;
            return $composite;
        });
        $this->expr->method('orX')->willReturn($composite);
    }

    public function testCategoryFilterAppliesEq(): void {
        $this->recordCategoryExpression();

        $this->builder->applyTransactionFilters($this->qb, ['category' => 10], 't');

        $this->assertContains('t.category_id', $this->exprCalls['eq']);
    }

    public function testCategoryFilterCommaListAppliesIn(): void {
        // A chart drill-down from an aggregated top-level slice passes the
        // parent id plus its subcategory ids as a comma list (#317)
        $capturedIds = null;
        $this->qb->method('createNamedParameter')
            ->willReturnCallback(function ($value) use (&$capturedIds) {
                if (is_array($value)) {
                    $capturedIds = $value;
                }
                return ':param';
            });
        $this->recordCategoryExpression();

        $this->builder->applyTransactionFilters($this->qb, ['category' => '10,12,15'], 't');

        $this->assertContains('t.category_id', $this->exprCalls['in']);
        $this->assertNotContains('t.category_id', $this->exprCalls['eq'], 'a list is matched with IN, not eq');
        $this->assertSame([10, 12, 15], $capturedIds);
    }

    public function testCategoryFilterSingleIdStringAppliesEq(): void {
        $this->recordCategoryExpression();

        $this->builder->applyTransactionFilters($this->qb, ['category' => '10'], 't');

        $this->assertContains('t.category_id', $this->exprCalls['eq']);
        $this->assertNotContains('t.category_id', $this->exprCalls['in'], 'a single id is matched with eq, not IN');
    }

    public function testUncategorizedFilterUsesIsNull(): void {
        $nulled = [];
        $this->expr->method('isNull')
            ->willReturnCallback(function (string $column) use (&$nulled) {
                $nulled[] = $column;
                return $column . ' IS NULL';
            });

        $this->builder->applyTransactionFilters($this->qb, ['category' => 'uncategorized'], 't');

        $this->assertContains('t.category_id', $nulled);
    }

    /**
     * Splitting a transaction clears the parent's own category
     * (TransactionSplitService::splitTransaction) because the categories now
     * live on the split rows. A bare "category_id IS NULL" therefore matches
     * every split parent, which is how split transactions ended up listed
     * under the Uncategorized filter (#356). The guard is the partition
     * complement findUncategorized uses — eq(is_split, false) OR NOT
     * EXISTS(parts) — because plain true-or-NULL still let a NULL-flag row
     * that HAS parts through, and inline categorisation from this list is
     * exactly how rows carrying both a category and parts get minted (#360).
     */
    public function testUncategorizedFilterExcludesSplitParents(): void {
        $nulled = [];
        $this->expr->method('isNull')
            ->willReturnCallback(function (string $column) use (&$nulled) {
                $nulled[] = $column;
                return $column . ' IS NULL';
            });
        $this->expr->expects($this->once())
            ->method('eq')
            ->with('t.is_split', ':param')
            ->willReturn('t.is_split = :param');

        $orParts = [];
        $this->expr->method('orX')->willReturnCallback(function (...$parts) use (&$orParts) {
            $orParts[] = array_map('strval', $parts);
            return $this->createMock(ICompositeExpression::class);
        });

        $this->builder->applyTransactionFilters($this->qb, ['category' => 'uncategorized'], 't');

        $this->assertContains('t.category_id', $nulled);
        $this->assertNotContains('t.is_split', $nulled,
            'a NULL flag alone must not pass the filter — a NULL-flag row with parts is a split parent (#360)');

        $this->assertCount(1, $orParts);
        $this->assertSame('t.is_split = :param', $orParts[0][0]);
        $this->assertStringContainsString('NOT EXISTS', $orParts[0][1]);
        $this->assertStringContainsString('budget_tx_splits', $orParts[0][1]);
        $this->assertStringContainsString('bsx.transaction_id = t.id', $orParts[0][1]);
    }

    /**
     * The mirror image of #356: a split parent's category_id is NULL, so a
     * filter on a real category id could never match one and every split that
     * spent in that category was hidden from the list — while the spending
     * charts, which read the split rows, counted the same money (#359).
     */
    public function testCategoryFilterAlsoMatchesSplitParts(): void {
        $this->recordCategoryExpression();

        $this->builder->applyTransactionFilters($this->qb, ['category' => 10], 't');

        $this->assertCount(1, $this->exprCalls['andX'], 'the split leg is an AND of the EXISTS and the split flag');
        $split = (string)$this->exprCalls['andX'][0][0];
        $this->assertStringContainsString('EXISTS', $split);
        $this->assertStringContainsString('budget_tx_splits', $split);
        $this->assertStringContainsString('bsx.transaction_id = t.id', $split);
        $this->assertStringContainsString('bsx.category_id IN (', $split);
    }

    /**
     * The splits table alone must not put a transaction in a category: a row
     * explicitly marked unsplit is not a split, whatever rows happen to
     * reference it. Deleting a transaction used to leave its split rows behind,
     * so stale references are a real thing (#359).
     */
    public function testTheSplitLegIsGuardedByTheSplitFlag(): void {
        $this->recordCategoryExpression();

        $this->builder->applyTransactionFilters($this->qb, ['category' => 10], 't');

        $this->assertCount(1, $this->exprCalls['andX']);
        $this->assertCount(2, $this->exprCalls['andX'][0], 'EXISTS is ANDed with the split-flag test');
        $this->assertContains('t.is_split', $this->exprCalls['eq'], 'is_split = true is one leg');
    }

    /**
     * findWithFilters counts with COUNT(t.id) and pages the same predicate, so
     * a receipt with two parts in the filtered category has to stay one row.
     * A join to budget_tx_splits would duplicate it and inflate the total.
     */
    public function testCategoryFilterMatchesSplitsWithoutJoining(): void {
        $this->qb->expects($this->never())->method('innerJoin');

        $this->builder->applyTransactionFilters($this->qb, ['category' => 10], 't');
    }

    public function testCategoryFilterCommaListFeedsTheSplitMatchTheSameIds(): void {
        $captured = [];
        $this->qb->method('createNamedParameter')
            ->willReturnCallback(function ($value) use (&$captured) {
                if (is_array($value)) {
                    $captured[] = $value;
                }
                return ':param';
            });

        $this->builder->applyTransactionFilters($this->qb, ['category' => '10,12,15'], 't');

        $this->assertCount(2, $captured, 'own-category and split legs each bind the id list');
        $this->assertSame([10, 12, 15], $captured[0]);
        $this->assertSame([10, 12, 15], $captured[1]);
    }

    public function testUncategorizedFilterAddsNoSplitMatch(): void {
        // #356 excludes split parents from Uncategorized; matching them through
        // their parts here would put every one of them straight back. The
        // filter names budget_tx_splits only to EXCLUDE rows that have parts
        // (#360) — never to match a part's category the way a real category
        // filter does.
        $orParts = [];
        $this->expr->method('isNull')->willReturnCallback(fn(string $c) => $c . ' IS NULL');
        $this->expr->method('eq')->willReturnCallback(fn(...$args) => $args[0] . ' = ' . $args[1]);
        $this->expr->method('orX')->willReturnCallback(function (...$parts) use (&$orParts) {
            $orParts[] = array_map('strval', $parts);
            return $this->createMock(ICompositeExpression::class);
        });

        $this->builder->applyTransactionFilters($this->qb, ['category' => 'uncategorized'], 't');

        foreach ($orParts as $parts) {
            foreach ($parts as $part) {
                $this->assertStringNotContainsString('bsx.category_id', $part,
                    'the uncategorized filter must never match a transaction through a part\'s category');
            }
        }
    }

    public function testUnparseableCategoryFilterAddsNoSplitMatch(): void {
        $this->recordCategoryExpression();

        $this->builder->applyTransactionFilters($this->qb, ['category' => 'abc'], 't');

        $this->assertNotContains('budget_tx_splits', $this->tablesNamed);
    }

    public function testCategoryFilterNamesTheSplitTable(): void {
        $this->recordCategoryExpression();

        $this->builder->applyTransactionFilters($this->qb, ['category' => 10], 't');

        $this->assertContains('budget_tx_splits', $this->tablesNamed);
    }

    // ===== hasSplitPartsExpr =====

    /**
     * The one shared "does this row have parts?" expression — TransactionMapper
     * and ImportRuleService build their partition predicates from it, so its
     * shape is pinned here: a correlated EXISTS on the given alias (never a
     * join — callers count and sum the outer row), identifiers unquoted.
     */
    public function testHasSplitPartsExprIsACorrelatedExistsOnTheAlias(): void {
        $sql = QueryFilterBuilder::hasSplitPartsExpr($this->qb, 't');

        $this->assertStringStartsWith('EXISTS (SELECT 1 FROM ', $sql);
        $this->assertStringContainsString('budget_tx_splits', $sql);
        $this->assertStringContainsString('bsx.transaction_id = t.id', $sql);
    }

    // ===== parseCategoryIds =====

    /**
     * @dataProvider categoryFilterValues
     */
    public function testParseCategoryIds(mixed $input, array $expected): void {
        $this->assertSame($expected, QueryFilterBuilder::parseCategoryIds($input));
    }

    public static function categoryFilterValues(): array {
        return [
            'single int' => [10, [10]],
            'single string' => ['10', [10]],
            'comma list' => ['10,12,15', [10, 12, 15]],
            'comma list with spaces' => ['10, 12', [10, 12]],
            'uncategorized' => ['uncategorized', []],
            'empty string' => ['', []],
            'null' => [null, []],
            'non-numeric' => ['abc', []],
            'zero is not an id' => ['0', []],
        ];
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

    public function testSearchFilterUsesCaseInsensitiveLikeOnMultipleFields(): void {
        $this->expr->expects($this->exactly(4))
            ->method('iLike');

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
