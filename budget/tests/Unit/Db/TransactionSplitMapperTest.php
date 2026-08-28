<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\TransactionSplit;
use OCA\Budget\Db\TransactionSplitMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
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
        $this->qb->method('createFunction')->willReturn($sumFunc);

        foreach (['select', 'addSelect', 'selectAlias', 'from', 'where', 'andWhere',
                   'orderBy', 'addOrderBy', 'leftJoin', 'innerJoin', 'delete', 'groupBy', 'addGroupBy',
                   'update', 'set'] as $method) {
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

    // ===== clearCategory (#360) =====

    public function testClearCategoryReturnsZeroForEmptyInput(): void {
        $this->qb->expects($this->never())->method('executeStatement');

        $result = $this->mapper->clearCategory([]);

        $this->assertSame(0, $result);
    }

    public function testClearCategoryUpdatesMatchingSplitParts(): void {
        $this->qb->expects($this->once())->method('update')->with('budget_tx_splits')->willReturnSelf();
        $this->qb->expects($this->once())->method('set')
            ->with('category_id', ':param')->willReturnSelf();
        $this->qb->expects($this->once())->method('executeStatement')->willReturn(3);

        $result = $this->mapper->clearCategory([5, 6]);

        $this->assertSame(3, $result);
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

    /**
     * A full year of split ids (YearOverYearService) or a whole forecast
     * window (PatternAnalyzer) can exceed 500 ids, and old SQLite builds cap
     * bound variables at 999 -- one unchunked IN() would risk blowing that
     * cap. 700 ids forces two chunks (500 + 200); a category present in both
     * chunks (5) must have its per-chunk totals summed, not overwritten.
     */
    public function testGetCategoryTotalsChunksIdsAtFiveHundredAndSumsAcrossChunks(): void {
        $ids = range(1, 700);

        $chunk1 = $this->createMock(IResult::class);
        $chunk1->method('fetch')->willReturnOnConsecutiveCalls(
            ['category_id' => '5', 'total' => '100.00'],
            ['category_id' => '10', 'total' => '50.00'],
            false
        );
        $chunk1->method('closeCursor');

        $chunk2 = $this->createMock(IResult::class);
        $chunk2->method('fetch')->willReturnOnConsecutiveCalls(
            ['category_id' => '5', 'total' => '25.00'],
            ['category_id' => '20', 'total' => '10.00'],
            false
        );
        $chunk2->method('closeCursor');

        $this->qb->expects($this->exactly(2))->method('executeQuery')
            ->willReturnOnConsecutiveCalls($chunk1, $chunk2);

        $totals = $this->mapper->getCategoryTotals($ids);

        $this->assertEquals(125.00, $totals[5]);
        $this->assertEquals(50.00, $totals[10]);
        $this->assertEquals(10.00, $totals[20]);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        // First call: select IDs returns rows
        $this->result->method('fetchAll')->willReturn(
            array_map(fn($i) => ['id' => $i], range(1, 5))
        );
        $this->qb->method('executeQuery')->willReturn($this->result);
        // Second call: delete by IDs
        $this->qb->method('executeStatement')->willReturn(5);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(5, $count);
    }

    // ===== getCategoryTotalsByBucket (#360) =====

    public function testGetCategoryTotalsByBucketReturnsIndexedByCategoryAndBucket(): void {
        $this->result->method('fetch')->willReturnOnConsecutiveCalls(
            ['category_id' => 5, 'bucket' => '2026-01', 'total' => '50.00'],
            false
        );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $totals = $this->mapper->getCategoryTotalsByBucket('user1', '2026-01-01', '2026-01-31');

        $this->assertSame(50.0, $totals[5]['2026-01']);
    }

    /**
     * This mapper has no splitParentPredicate() helper (that lives on
     * TransactionMapper), so the true-or-NULL is_split guard is inlined here.
     * Without it, a part whose parent is explicitly marked unsplit
     * (is_split = false) was counted anyway -- the policy is that such a
     * parent's own amount counts and any leftover split rows referencing it
     * are stray and must be ignored (#360; see
     * TransactionMapper::splitParentPredicate for the same policy stated on
     * the direct side).
     */
    public function testGetCategoryTotalsByBucketGuardsAgainstStrayPartsOnUnsplitParents(): void {
        $eqCalls = [];
        $this->expr->method('eq')->willReturnCallback(function (string $col, $val) use (&$eqCalls) {
            $eqCalls[] = $col;
            return "eq($col)";
        });
        $isNullCalls = [];
        $this->expr->method('isNull')->willReturnCallback(function (string $col) use (&$isNullCalls) {
            $isNullCalls[] = $col;
            return "isNull($col)";
        });
        $orXCalls = [];
        $orXResult = $this->createMock(ICompositeExpression::class);
        $this->expr->method('orX')->willReturnCallback(function (...$parts) use (&$orXCalls, $orXResult) {
            $orXCalls[] = $parts;
            return $orXResult;
        });

        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->mapper->getCategoryTotalsByBucket('user1', '2026-01-01', '2026-01-31');

        $guardFound = false;
        foreach ($orXCalls as $parts) {
            if (in_array('eq(t.is_split)', $parts, true) && in_array('isNull(t.is_split)', $parts, true)) {
                $guardFound = true;
                break;
            }
        }
        $this->assertTrue($guardFound, 'getCategoryTotalsByBucket must OR eq(is_split, true) with isNull(is_split)');
    }

    // ===== getCategoryNetByMonthBatch (#288) =====

    public function testGetCategoryNetByMonthBatchMapsSignedNet(): void {
        $this->result->method('fetch')->willReturnOnConsecutiveCalls(
            ['category_id' => 5, 'bucket' => '2026-01', 'net_total' => '-30.00'],
            false
        );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $out = $this->mapper->getCategoryNetByMonthBatch('user1', '2026-01-01', '2026-01-31');

        $this->assertSame(-30.0, $out[5]['2026-01']);
    }

    /**
     * Same inlined stray-part guard as getCategoryTotalsByBucket above (#360).
     */
    public function testGetCategoryNetByMonthBatchGuardsAgainstStrayPartsOnUnsplitParents(): void {
        $eqCalls = [];
        $this->expr->method('eq')->willReturnCallback(function (string $col, $val) use (&$eqCalls) {
            $eqCalls[] = $col;
            return "eq($col)";
        });
        $isNullCalls = [];
        $this->expr->method('isNull')->willReturnCallback(function (string $col) use (&$isNullCalls) {
            $isNullCalls[] = $col;
            return "isNull($col)";
        });
        $orXCalls = [];
        $orXResult = $this->createMock(ICompositeExpression::class);
        $this->expr->method('orX')->willReturnCallback(function (...$parts) use (&$orXCalls, $orXResult) {
            $orXCalls[] = $parts;
            return $orXResult;
        });

        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->mapper->getCategoryNetByMonthBatch('user1', '2026-01-01', '2026-01-31');

        $guardFound = false;
        foreach ($orXCalls as $parts) {
            if (in_array('eq(t.is_split)', $parts, true) && in_array('isNull(t.is_split)', $parts, true)) {
                $guardFound = true;
                break;
            }
        }
        $this->assertTrue($guardFound, 'getCategoryNetByMonthBatch must OR eq(is_split, true) with isNull(is_split)');
    }

    // ===== findByTransactionIds =====

    public function testFindByTransactionIdsGroupsPartsByTransaction(): void {
        $rows = [
            $this->makeSplitRow(['transaction_id' => 100, 'category_id' => 5, 'amount' => '50.00', 'category_name' => 'Food']),
            $this->makeSplitRow(['transaction_id' => 100, 'category_id' => 9, 'amount' => '20.00', 'category_name' => 'Household']),
            $this->makeSplitRow(['transaction_id' => 101, 'category_id' => 5, 'amount' => '5.00', 'category_name' => 'Food']),
        ];
        $rows[] = false;
        $this->result->method('fetch')->willReturnOnConsecutiveCalls(...$rows);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $grouped = $this->mapper->findByTransactionIds([100, 101]);

        $this->assertCount(2, $grouped[100]);
        $this->assertCount(1, $grouped[101]);
    }

    /**
     * Without the part's own category id, a caller filtering by category cannot
     * tell which part it matched, so it cannot show the share belonging to that
     * category (#359).
     */
    public function testFindByTransactionIdsReportsEachPartsCategoryId(): void {
        $this->result->method('fetch')->willReturnOnConsecutiveCalls(
            $this->makeSplitRow(['transaction_id' => 100, 'category_id' => 5, 'amount' => '50.00', 'category_name' => 'Food']),
            false
        );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $grouped = $this->mapper->findByTransactionIds([100]);

        $this->assertSame(5, $grouped[100][0]['categoryId']);
        $this->assertSame('Food', $grouped[100][0]['categoryName']);
        $this->assertSame(50.0, $grouped[100][0]['amount']);
    }

    public function testFindByTransactionIdsReportsAnUncategorisedPartAsNull(): void {
        $this->result->method('fetch')->willReturnOnConsecutiveCalls(
            $this->makeSplitRow(['transaction_id' => 100, 'category_id' => null, 'category_name' => null]),
            false
        );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $grouped = $this->mapper->findByTransactionIds([100]);

        $this->assertNull($grouped[100][0]['categoryId']);
        $this->assertNull($grouped[100][0]['categoryName']);
    }

    public function testFindByTransactionIdsShortCircuitsOnAnEmptyList(): void {
        $this->qb->expects($this->never())->method('executeQuery');

        $this->assertSame([], $this->mapper->findByTransactionIds([]));
    }

    /**
     * Same 500-id chunking as getCategoryTotals, for the same reason. Here
     * each transaction id can only fall in one chunk, so the per-chunk
     * groups never collide -- merging is just accumulating into one map as
     * chunks come back.
     */
    public function testFindByTransactionIdsChunksIdsAtFiveHundredAndMergesGroups(): void {
        $ids = range(1, 600);

        $chunk1 = $this->createMock(IResult::class);
        $chunk1->method('fetch')->willReturnOnConsecutiveCalls(
            $this->makeSplitRow(['transaction_id' => 100, 'category_id' => 5, 'amount' => '50.00', 'category_name' => 'Food']),
            false
        );
        $chunk1->method('closeCursor');

        $chunk2 = $this->createMock(IResult::class);
        $chunk2->method('fetch')->willReturnOnConsecutiveCalls(
            $this->makeSplitRow(['transaction_id' => 550, 'category_id' => 9, 'amount' => '20.00', 'category_name' => 'Household']),
            false
        );
        $chunk2->method('closeCursor');

        $this->qb->expects($this->exactly(2))->method('executeQuery')
            ->willReturnOnConsecutiveCalls($chunk1, $chunk2);

        $grouped = $this->mapper->findByTransactionIds($ids);

        $this->assertCount(1, $grouped[100]);
        $this->assertSame(5, $grouped[100][0]['categoryId']);
        $this->assertCount(1, $grouped[550]);
        $this->assertSame(9, $grouped[550][0]['categoryId']);
    }
}
