<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\QueryFilterBuilder;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Stub to add escapeLikeParameter (not on IQueryBuilder interface).
 */
abstract class TransactionQueryBuilder implements IQueryBuilder {
    abstract public function escapeLikeParameter(string $parameter): string;
}

class TransactionMapperTest extends TestCase {
    private TransactionMapper $mapper;
    private IDBConnection $db;
    /** @var TransactionQueryBuilder&\PHPUnit\Framework\MockObject\MockObject */
    private $qb;
    private IExpressionBuilder $expr;
    private IFunctionBuilder $func;
    private IResult $result;
    private QueryFilterBuilder $filterBuilder;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(TransactionQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);
        $this->func = $this->createMock(IFunctionBuilder::class);
        $this->result = $this->createMock(IResult::class);
        $this->filterBuilder = $this->createMock(QueryFilterBuilder::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('func')->willReturn($this->func);
        $this->qb->method('getSQL')->willReturn('');
        $this->qb->method('createNamedParameter')->willReturn(':param');
        $this->qb->method('escapeLikeParameter')->willReturnCallback(fn($v) => $v);

        $mockFunction = $this->createMock(IQueryFunction::class);
        $this->qb->method('createFunction')->willReturn($mockFunction);
        $this->func->method('sum')->willReturn($mockFunction);
        $this->func->method('count')->willReturn($mockFunction);

        // Fluent methods
        foreach (['select', 'addSelect', 'selectAlias', 'from', 'where', 'andWhere',
                   'orderBy', 'addOrderBy', 'innerJoin', 'leftJoin',
                   'delete', 'update', 'set', 'groupBy', 'addGroupBy',
                   'setMaxResults', 'setFirstResult'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new TransactionMapper($this->db, $this->filterBuilder);
    }

    private function makeTransactionRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'account_id' => 10,
            'category_id' => 5,
            'date' => '2026-01-15',
            'description' => 'Coffee Shop',
            'vendor' => 'Starbucks',
            'amount' => 4.50,
            'type' => 'debit',
            'reference' => null,
            'notes' => null,
            'import_id' => null,
            'reconciled' => 0,
            'created_at' => '2026-01-15 10:00:00',
            'updated_at' => '2026-01-15 10:00:00',
            'linked_transaction_id' => null,
            'is_split' => 0,
            'bill_id' => null,
            'status' => 'cleared',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_transactions', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsTransaction(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTransactionRow(),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $tx = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(Transaction::class, $tx);
        $this->assertEquals('Coffee Shop', $tx->getDescription());
        $this->assertEquals(4.50, $tx->getAmount());
        $this->assertEquals('debit', $tx->getType());
        $this->assertEquals(10, $tx->getAccountId());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findByAccount =====

    public function testFindByAccountReturnsTransactions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTransactionRow(['id' => 1]),
                $this->makeTransactionRow(['id' => 2, 'description' => 'Grocery']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $txs = $this->mapper->findByAccount(10, 'user1');

        $this->assertCount(2, $txs);
        $this->assertEquals('Coffee Shop', $txs[0]->getDescription());
        $this->assertEquals('Grocery', $txs[1]->getDescription());
    }

    // ===== findByDateRange =====

    public function testFindByDateRangeReturnsTransactions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTransactionRow(['date' => '2026-01-15']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $txs = $this->mapper->findByDateRange(10, '2026-01-01', '2026-01-31');

        $this->assertCount(1, $txs);
    }

    // ===== findAll =====

    public function testFindAllReturnsAllUserTransactions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTransactionRow(['id' => 1]),
                $this->makeTransactionRow(['id' => 2]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $txs = $this->mapper->findAll('user1');

        $this->assertCount(2, $txs);
    }

    // ===== findAllByUserAndDateRange =====

    public function testFindAllByUserAndDateRangeReturnsTransactions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTransactionRow(),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $txs = $this->mapper->findAllByUserAndDateRange('user1', '2026-01-01', '2026-01-31');

        $this->assertCount(1, $txs);
    }

    public function testFindAllByUserAndDateRangeWithAccountFilter(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTransactionRow(['account_id' => 10]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $txs = $this->mapper->findAllByUserAndDateRange('user1', '2026-01-01', '2026-01-31', 10);

        $this->assertCount(1, $txs);
    }

    // ===== findByCategory =====

    public function testFindByCategoryReturnsTransactions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTransactionRow(['category_id' => 5]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $txs = $this->mapper->findByCategory(5, 'user1');

        $this->assertCount(1, $txs);
        $this->assertEquals(5, $txs[0]->getCategoryId());
    }

    // ===== existsByImportId =====

    public function testExistsByImportIdReturnsTrueWhenExists(): void {
        $countFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('count')->willReturn($countFunc);
        $this->result->method('fetchOne')->willReturn('1');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $exists = $this->mapper->existsByImportId(10, 'import-abc');

        $this->assertTrue($exists);
    }

    public function testExistsByImportIdReturnsFalseWhenNotExists(): void {
        $countFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('count')->willReturn($countFunc);
        $this->result->method('fetchOne')->willReturn('0');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $exists = $this->mapper->existsByImportId(10, 'nonexistent');

        $this->assertFalse($exists);
    }

    // ===== findUncategorized =====

    public function testFindUncategorizedReturnsNullCategoryTransactions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTransactionRow(['category_id' => null]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $txs = $this->mapper->findUncategorized('user1');

        $this->assertCount(1, $txs);
        $this->assertNull($txs[0]->getCategoryId());
    }

    // ===== search =====

    public function testSearchReturnsMatchingTransactions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTransactionRow(['description' => 'Coffee at Starbucks']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $txs = $this->mapper->search('user1', 'coffee');

        $this->assertCount(1, $txs);
    }

    // ===== findWithFilters =====

    public function testFindWithFiltersReturnsTransactionsAndTotal(): void {
        // Count query result
        $countResult = $this->createMock(IResult::class);
        $countResult->method('fetchOne')->willReturn('5');
        $countResult->method('closeCursor');

        // Main query result
        $mainResult = $this->createMock(IResult::class);
        $mainResult->method('fetchAll')->willReturn([
            array_merge($this->makeTransactionRow(), [
                'excluded_from_forecast' => 1,
                'account_name' => 'Checking',
                'account_currency' => 'USD',
                'category_name' => 'Food',
            ]),
        ]);
        $mainResult->method('closeCursor');

        $this->qb->method('executeQuery')
            ->willReturnOnConsecutiveCalls($countResult, $mainResult);

        $result = $this->mapper->findWithFilters('user1', [], 25, 0);

        $this->assertArrayHasKey('transactions', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertEquals(5, $result['total']);
        $this->assertCount(1, $result['transactions']);

        // Check data transformation
        $tx = $result['transactions'][0];
        $this->assertEquals(1, $tx['id']);
        $this->assertEquals(10, $tx['accountId']);
        $this->assertEquals(5, $tx['categoryId']);
        $this->assertEquals('2026-01-15', $tx['date']);
        $this->assertEquals('Coffee Shop', $tx['description']);
        $this->assertEquals('Starbucks', $tx['vendor']);
        $this->assertEquals(4.50, $tx['amount']);
        $this->assertEquals('debit', $tx['type']);
        $this->assertFalse($tx['reconciled']);
        // Regression #326: the edit dialog is populated from list rows, so the
        // flag must survive the row mapping or edits silently reset it
        $this->assertTrue($tx['excludedFromForecast']);
        $this->assertEquals('Checking', $tx['accountName']);
        $this->assertEquals('USD', $tx['accountCurrency']);
        $this->assertEquals('Food', $tx['categoryName']);
    }

    public function testFindWithFiltersNullCategoryIdMappedToNull(): void {
        $countResult = $this->createMock(IResult::class);
        $countResult->method('fetchOne')->willReturn('1');
        $countResult->method('closeCursor');

        $mainResult = $this->createMock(IResult::class);
        $mainResult->method('fetchAll')->willReturn([
            array_merge($this->makeTransactionRow(['category_id' => null]), [
                'account_name' => 'Checking',
                'account_currency' => 'USD',
                'category_name' => null,
            ]),
        ]);
        $mainResult->method('closeCursor');

        $this->qb->method('executeQuery')
            ->willReturnOnConsecutiveCalls($countResult, $mainResult);

        $result = $this->mapper->findWithFilters('user1', [], 25, 0);

        $this->assertNull($result['transactions'][0]['categoryId']);
    }

    public function testFindWithFiltersDelegatesToFilterBuilder(): void {
        $countResult = $this->createMock(IResult::class);
        $countResult->method('fetchOne')->willReturn('0');
        $countResult->method('closeCursor');

        $mainResult = $this->createMock(IResult::class);
        $mainResult->method('fetchAll')->willReturn([]);
        $mainResult->method('closeCursor');

        $this->qb->method('executeQuery')
            ->willReturnOnConsecutiveCalls($countResult, $mainResult);

        $filters = ['accountId' => 10, 'type' => 'debit'];

        // Filter builder should be called twice: once for main query, once for count
        $this->filterBuilder->expects($this->exactly(2))
            ->method('applyTransactionFilters');
        $this->filterBuilder->expects($this->once())
            ->method('applySorting');
        $this->filterBuilder->expects($this->once())
            ->method('applyPagination');

        $this->mapper->findWithFilters('user1', $filters, 25, 0);
    }

    public function testFindWithFiltersDefaultStatusCleared(): void {
        $countResult = $this->createMock(IResult::class);
        $countResult->method('fetchOne')->willReturn('1');
        $countResult->method('closeCursor');

        $mainResult = $this->createMock(IResult::class);
        $mainResult->method('fetchAll')->willReturn([
            array_merge($this->makeTransactionRow(['status' => null]), [
                'account_name' => 'Checking',
                'account_currency' => null,
                'category_name' => null,
            ]),
        ]);
        $mainResult->method('closeCursor');

        $this->qb->method('executeQuery')
            ->willReturnOnConsecutiveCalls($countResult, $mainResult);

        $result = $this->mapper->findWithFilters('user1', [], 25, 0);

        // null status defaults to 'cleared'
        $this->assertEquals('cleared', $result['transactions'][0]['status']);
        // missing excluded_from_forecast defaults to false
        $this->assertFalse($result['transactions'][0]['excludedFromForecast']);
        // null account_currency defaults to 'USD'
        $this->assertEquals('USD', $result['transactions'][0]['accountCurrency']);
    }

    // ===== getSpendingByVendor =====

    public function testGetSpendingByVendorReturnsFormattedArray(): void {
        $this->result->method('fetchAll')->willReturn([
            ['vendor' => 'Starbucks', 'total' => '150.00', 'count' => '10'],
            ['vendor' => '', 'total' => '50.00', 'count' => '3'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $data = $this->mapper->getSpendingByVendor('user1', null, '2026-01-01', '2026-01-31');

        $this->assertCount(2, $data);
        $this->assertEquals('Starbucks', $data[0]['name']);
        $this->assertEquals(150.00, $data[0]['total']);
        $this->assertEquals(10, $data[0]['count']);
        // Empty vendor mapped to 'Unknown'
        $this->assertEquals('Unknown', $data[1]['name']);
    }

    // ===== getIncomeBySource =====

    public function testGetIncomeBySourceReturnsFormattedArray(): void {
        $this->result->method('fetchAll')->willReturn([
            ['vendor' => 'Employer Inc', 'total' => '5000.00', 'count' => '1'],
            ['vendor' => '', 'total' => '200.00', 'count' => '2'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $data = $this->mapper->getIncomeBySource('user1', null, '2026-01-01', '2026-01-31');

        $this->assertCount(2, $data);
        $this->assertEquals('Employer Inc', $data[0]['name']);
        $this->assertEquals(5000.00, $data[0]['total']);
        // Empty vendor mapped to 'Unknown Source'
        $this->assertEquals('Unknown Source', $data[1]['name']);
    }

    // ===== getCashFlowByMonth =====

    public function testGetCashFlowByMonthCalculatesNet(): void {
        $this->result->method('fetchAll')->willReturn([
            ['month' => '2026-01', 'income' => '3000.00', 'expenses' => '2000.00'],
            ['month' => '2026-02', 'income' => '3500.00', 'expenses' => '4000.00'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $data = $this->mapper->getCashFlowByMonth('user1', null, '2026-01-01', '2026-02-28');

        $this->assertCount(2, $data);
        $this->assertEquals('2026-01', $data[0]['month']);
        $this->assertEquals(3000.00, $data[0]['income']);
        $this->assertEquals(2000.00, $data[0]['expenses']);
        $this->assertEquals(1000.00, $data[0]['net']);

        // Negative net
        $this->assertEquals(-500.00, $data[1]['net']);
    }

    // ===== getAccountSummaries =====

    public function testGetAccountSummariesReturnsIndexedByAccountId(): void {
        $this->result->method('fetchAll')->willReturn([
            ['account_id' => '10', 'income' => '5000.00', 'expenses' => '3000.00', 'count' => '50'],
            ['account_id' => '20', 'income' => '1000.00', 'expenses' => '500.00', 'count' => '10'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $summaries = $this->mapper->getAccountSummaries('user1', '2026-01-01', '2026-01-31');

        $this->assertArrayHasKey(10, $summaries);
        $this->assertArrayHasKey(20, $summaries);
        $this->assertEquals(5000.00, $summaries[10]['income']);
        $this->assertEquals(3000.00, $summaries[10]['expenses']);
        $this->assertEquals(50, $summaries[10]['count']);
    }

    public function testGetAccountSummariesReturnsEmptyForNoData(): void {
        $this->result->method('fetchAll')->willReturn([]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $summaries = $this->mapper->getAccountSummaries('user1', '2026-01-01', '2026-01-31');

        $this->assertEmpty($summaries);
    }

    // ===== getTransferTotals =====

    public function testGetTransferTotalsReturnsFloats(): void {
        $this->result->method('fetch')->willReturn(
            ['income' => '500.00', 'expenses' => '500.00']
        );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $totals = $this->mapper->getTransferTotals('user1', '2026-01-01', '2026-01-31');

        $this->assertEquals(500.00, $totals['income']);
        $this->assertEquals(500.00, $totals['expenses']);
    }

    public function testGetTransferTotalsReturnsZeroForNullRow(): void {
        $this->result->method('fetch')->willReturn(
            ['income' => null, 'expenses' => null]
        );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $totals = $this->mapper->getTransferTotals('user1', '2026-01-01', '2026-01-31');

        $this->assertEquals(0.0, $totals['income']);
        $this->assertEquals(0.0, $totals['expenses']);
    }

    // ===== getTransferTotalsByAccount =====

    public function testGetTransferTotalsByAccountReturnsIndexedByAccountId(): void {
        $this->result->method('fetchAll')->willReturn([
            ['account_id' => '10', 'income' => '300.00', 'expenses' => '200.00'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $totals = $this->mapper->getTransferTotalsByAccount('user1', '2026-01-01', '2026-01-31');

        $this->assertArrayHasKey(10, $totals);
        $this->assertEquals(300.00, $totals[10]['income']);
        $this->assertEquals(200.00, $totals[10]['expenses']);
    }

    // ===== getCategorySpendingBatch =====

    public function testGetCategorySpendingBatchReturnsEmptyForEmptyInput(): void {
        $this->qb->expects($this->never())->method('executeQuery');

        $result = $this->mapper->getCategorySpendingBatch([], '2026-01-01', '2026-01-31');

        $this->assertEmpty($result);
    }

    public function testGetCategorySpendingBatchReturnsIndexedByCategoryId(): void {
        $this->result->method('fetchAll')->willReturn([
            ['category_id' => '5', 'total' => '450.00'],
            ['category_id' => '10', 'total' => '200.00'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $spending = $this->mapper->getCategorySpendingBatch([5, 10], '2026-01-01', '2026-01-31');

        $this->assertArrayHasKey(5, $spending);
        $this->assertArrayHasKey(10, $spending);
        $this->assertEquals(450.00, $spending[5]);
        $this->assertEquals(200.00, $spending[10]);
    }

    // ===== getCategoryTotalsByAccount =====

    public function testGetCategoryTotalsByAccountReturnsEmptyForEmptyInput(): void {
        $this->qb->expects($this->never())->method('executeQuery');

        $result = $this->mapper->getCategoryTotalsByAccount([], '2026-01-01', '2026-01-31');

        $this->assertEmpty($result);
    }

    public function testGetCategoryTotalsByAccountReturnsIndexedByAccountId(): void {
        $this->result->method('fetchAll')->willReturn([
            ['account_id' => '10', 'income' => '50.00', 'expenses' => '450.00'],
            ['account_id' => '20', 'income' => null, 'expenses' => '200.00'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $totals = $this->mapper->getCategoryTotalsByAccount([5, 10], '2026-01-01', '2026-01-31');

        $this->assertEquals(['income' => 50.00, 'expenses' => 450.00], $totals[10]);
        $this->assertEquals(['income' => 0.0, 'expenses' => 200.00], $totals[20]);
    }

    // ===== findPotentialMatches =====

    public function testFindPotentialMatchesIsStrictByDefault(): void {
        // Same-currency-same-amount only: no OR branch in the query
        $this->expr->expects($this->never())->method('orX');

        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $result = $this->mapper->findPotentialMatches('user1', 1, 10, 50.00, 'debit', '2026-01-15', 'EUR');

        $this->assertSame([], $result);
    }

    public function testFindPotentialMatchesCrossCurrencyAddsOrBranch(): void {
        // Cross-currency opt-in (#326): (same currency AND same amount) OR different currency
        $this->expr->expects($this->once())->method('orX');

        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeTransactionRow(['type' => 'credit']), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $result = $this->mapper->findPotentialMatches('user1', 1, 10, 50.00, 'debit', '2026-01-15', 'EUR', 3, true);

        $this->assertCount(1, $result);
        $this->assertInstanceOf(Transaction::class, $result[0]);
    }

    // ===== getSpendingByAccountAggregated =====

    public function testGetSpendingByAccountAggregatedReturnsFormattedArray(): void {
        $this->result->method('fetchAll')->willReturn([
            ['name' => 'Checking', 'total' => '1000.00', 'count' => '20'],
            ['name' => 'Credit Card', 'total' => '500.00', 'count' => '10'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $data = $this->mapper->getSpendingByAccountAggregated('user1', '2026-01-01', '2026-01-31');

        $this->assertCount(2, $data);
        $this->assertEquals('Checking', $data[0]['name']);
        $this->assertEquals(1000.00, $data[0]['total']);
        $this->assertEquals(20, $data[0]['count']);
        $this->assertEquals(50.00, $data[0]['average']); // 1000/20
    }

    public function testGetSpendingByAccountAggregatedZeroCountAverageIsZero(): void {
        $this->result->method('fetchAll')->willReturn([
            ['name' => 'Empty', 'total' => '0.00', 'count' => '0'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $data = $this->mapper->getSpendingByAccountAggregated('user1', '2026-01-01', '2026-01-31');

        $this->assertEquals(0, $data[0]['average']);
    }

    // ===== getNetChangeAll =====

    public function testGetNetChangeAllReturnsFloat(): void {
        $this->result->method('fetchOne')->willReturn('500.25');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $netChange = $this->mapper->getNetChangeAll(10);

        $this->assertEquals(500.25, $netChange);
    }

    public function testGetNetChangeAllReturnsZeroWhenNoTransactions(): void {
        $this->result->method('fetchOne')->willReturn('0');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $netChange = $this->mapper->getNetChangeAll(10);

        $this->assertEquals(0.0, $netChange);
    }

    // ===== getAccountMetrics (#285) =====

    public function testGetAccountMetricsMapsAggregates(): void {
        // First query (count + average) uses fetch(); the two range-sum queries use fetchOne()
        $this->result->method('fetch')->willReturn(['cnt' => 7, 'avg_amt' => '244.2857']);
        $this->result->method('fetchOne')->willReturnOnConsecutiveCalls('150', '60');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $metrics = $this->mapper->getAccountMetrics(10, '2026-06-01', '2026-06-30');

        $this->assertSame(7, $metrics['count']);
        $this->assertEqualsWithDelta(244.2857, $metrics['average'], 0.0001);
        $this->assertSame(150.0, $metrics['monthIncome']);
        $this->assertSame(60.0, $metrics['monthExpenses']);
    }

    public function testGetAccountMetricsHandlesEmptyAccount(): void {
        // No rows: count 0, AVG returns null, sums return null/false
        $this->result->method('fetch')->willReturn(['cnt' => 0, 'avg_amt' => null]);
        $this->result->method('fetchOne')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $metrics = $this->mapper->getAccountMetrics(10, '2026-06-01', '2026-06-30');

        $this->assertSame(0, $metrics['count']);
        $this->assertSame(0.0, $metrics['average']);
        $this->assertSame(0.0, $metrics['monthIncome']);
        $this->assertSame(0.0, $metrics['monthExpenses']);
    }

    // ===== getNetChangeAfterDate =====

    public function testGetNetChangeAfterDateReturnsFloat(): void {
        $this->result->method('fetchOne')->willReturn('250.75');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $netChange = $this->mapper->getNetChangeAfterDate(10, '2026-01-15');

        $this->assertEquals(250.75, $netChange);
    }

    public function testGetNetChangeAfterDateReturnsZeroForNull(): void {
        $this->result->method('fetchOne')->willReturn('0');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $netChange = $this->mapper->getNetChangeAfterDate(10, '2026-01-15');

        $this->assertEquals(0.0, $netChange);
    }

    // ===== getNetChangeAfterDateBatch =====

    public function testGetNetChangeAfterDateBatchReturnsIndexedByAccountId(): void {
        $this->result->method('fetchAll')->willReturn([
            ['account_id' => '10', 'net_change' => '100.00'],
            ['account_id' => '20', 'net_change' => '-50.00'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $changes = $this->mapper->getNetChangeAfterDateBatch('user1', '2026-01-15');

        $this->assertArrayHasKey(10, $changes);
        $this->assertArrayHasKey(20, $changes);
        $this->assertEquals(100.00, $changes[10]);
        $this->assertEquals(-50.00, $changes[20]);
    }

    // ===== getDailyBalanceChanges =====

    public function testGetDailyBalanceChangesReturnsIndexedByDate(): void {
        $this->result->method('fetchAll')->willReturn([
            ['date' => '2026-01-15', 'net_change' => '-4.50'],
            ['date' => '2026-01-16', 'net_change' => '100.00'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $changes = $this->mapper->getDailyBalanceChanges(10, '2026-01-15', '2026-01-16');

        $this->assertArrayHasKey('2026-01-15', $changes);
        $this->assertArrayHasKey('2026-01-16', $changes);
        $this->assertEquals(-4.50, $changes['2026-01-15']);
        $this->assertEquals(100.00, $changes['2026-01-16']);
    }

    // ===== linkTransactions =====

    public function testLinkTransactionsExecutesTwoStatements(): void {
        $this->qb->expects($this->exactly(2))->method('executeStatement');

        $this->mapper->linkTransactions(1, 2);
    }

    // ===== unlinkTransaction =====

    public function testUnlinkTransactionReturnsNullWhenNotLinked(): void {
        $this->result->method('fetchOne')->willReturn(null);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $linkedId = $this->mapper->unlinkTransaction(1);

        $this->assertNull($linkedId);
    }

    public function testUnlinkTransactionReturnsLinkedIdAndExecutesStatements(): void {
        $this->result->method('fetchOne')->willReturn('42');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);
        $this->qb->method('executeStatement')->willReturn(1);

        $linkedId = $this->mapper->unlinkTransaction(1);

        $this->assertEquals(42, $linkedId);
    }

    // ===== getCategorySpending (user-scoped version) =====

    public function testGetCategorySpendingReturnsFloat(): void {
        $this->result->method('fetch')->willReturn(['total' => '450.75']);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $spending = $this->mapper->getCategorySpending('user1', 5, '2026-01-01', '2026-01-31');

        $this->assertEquals(450.75, $spending);
    }

    public function testGetCategorySpendingReturnsZeroForNull(): void {
        $this->result->method('fetch')->willReturn(['total' => null]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $spending = $this->mapper->getCategorySpending('user1', 5, '2026-01-01', '2026-01-31');

        $this->assertEquals(0.0, $spending);
    }

    // ===== getSplitTransactionIds =====

    public function testGetSplitTransactionIdsReturnsIntArray(): void {
        $this->result->method('fetchAll')->willReturn([
            ['id' => '100'],
            ['id' => '200'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $ids = $this->mapper->getSplitTransactionIds('user1', '2026-01-01', '2026-01-31');

        $this->assertEquals([100, 200], $ids);
    }

    public function testGetSplitTransactionIdsReturnsEmptyArrayWhenNone(): void {
        $this->result->method('fetchAll')->willReturn([]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $ids = $this->mapper->getSplitTransactionIds('user1', '2026-01-01', '2026-01-31');

        $this->assertEmpty($ids);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        // First call: select IDs returns rows
        $this->result->method('fetchAll')->willReturn(
            array_map(fn($i) => ['id' => $i], range(1, 15))
        );
        $this->qb->method('executeQuery')->willReturn($this->result);
        // Second call: delete by IDs
        $this->qb->method('executeStatement')->willReturn(15);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(15, $count);
    }

    // ===== findScheduledDueForTransition =====

    public function testFindScheduledDueForTransitionReturnsTransactions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeTransactionRow(['status' => 'scheduled', 'date' => '2026-01-01']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $txs = $this->mapper->findScheduledDueForTransition();

        $this->assertCount(1, $txs);
    }

    // ===== getSpendingSummary =====

    public function testGetSpendingSummaryReturnsRawRows(): void {
        $this->result->method('fetchAll')->willReturn([
            ['id' => 5, 'name' => 'Food', 'color' => '#ff0000', 'icon' => null, 'total' => '450.00', 'count' => '10'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $summary = $this->mapper->getSpendingSummary('user1', '2026-01-01', '2026-01-31');

        $this->assertCount(1, $summary);
        $this->assertEquals('Food', $summary[0]['name']);
    }

    public function testGetSpendingSummaryAddsSplitSpending(): void {
        // First query = direct (non-split) spending; second = split allocations.
        $direct = $this->createMock(IResult::class);
        $direct->method('fetchAll')->willReturn([
            ['id' => 5, 'name' => 'Food', 'color' => '#f00', 'icon' => null, 'total' => '100.00', 'count' => '2'],
        ]);
        $direct->method('closeCursor');

        $split = $this->createMock(IResult::class);
        $split->method('fetchAll')->willReturn([
            ['id' => 5, 'name' => 'Food', 'color' => '#f00', 'icon' => null, 'total' => '50.00', 'count' => '1'],
            ['id' => 9, 'name' => 'Rent', 'color' => '#0f0', 'icon' => null, 'total' => '850.00', 'count' => '1'],
        ]);
        $split->method('closeCursor');

        $this->qb->method('executeQuery')->willReturnOnConsecutiveCalls($direct, $split);

        $summary = $this->mapper->getSpendingSummary('user1', '2026-01-01', '2026-01-31');

        $byId = [];
        foreach ($summary as $row) {
            $byId[(int)$row['id']] = $row;
        }

        // Food: 100 direct + 50 split = 150 (counts merged too)
        $this->assertEqualsWithDelta(150.0, (float)$byId[5]['total'], 0.001);
        $this->assertSame(3, (int)$byId[5]['count']);
        // A category that only has split spending still appears
        $this->assertArrayHasKey(9, $byId);
        $this->assertEqualsWithDelta(850.0, (float)$byId[9]['total'], 0.001);
        // Re-sorted by total descending — Rent (850) comes first
        $this->assertSame(9, (int)$summary[0]['id']);
    }

    // ===== getMonthlyTrendData =====

    public function testGetMonthlyTrendDataReturnsFormattedArray(): void {
        $this->result->method('fetchAll')->willReturn([
            ['month' => '2026-01', 'income' => '5000.00', 'expenses' => '3000.00'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $data = $this->mapper->getMonthlyTrendData('user1', null, '2026-01-01', '2026-01-31');

        $this->assertCount(1, $data);
        $this->assertEquals('2026-01', $data[0]['month']);
        $this->assertEquals(5000.00, $data[0]['income']);
        $this->assertEquals(3000.00, $data[0]['expenses']);
    }

    /**
     * The income-vs-expense trend (#219) must left-join budget_categories so it
     * can drop transactions in excluded-from-reports categories. Guards against
     * the exclusion wiring being silently removed (this bug regressed repeatedly
     * when exclusion lived in scattered PHP filters instead of the query).
     */
    public function testGetMonthlyTrendDataLeftJoinsCategoriesForExclusion(): void {
        $joinedTables = [];
        $this->qb->expects($this->atLeastOnce())
            ->method('leftJoin')
            ->willReturnCallback(function ($from, $table, $alias, $cond = null) use (&$joinedTables) {
                $joinedTables[] = $table;
                return $this->qb;
            });
        $this->result->method('fetchAll')->willReturn([]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->mapper->getMonthlyTrendData('user1', null, '2026-01-01', '2026-01-31');

        $this->assertContains('budget_categories', $joinedTables,
            'getMonthlyTrendData must left-join budget_categories to exclude flagged categories (#219)');
    }

    public function testGetMonthlyTrendDataByAccountLeftJoinsCategoriesForExclusion(): void {
        $joinedTables = [];
        $this->qb->expects($this->atLeastOnce())
            ->method('leftJoin')
            ->willReturnCallback(function ($from, $table, $alias, $cond = null) use (&$joinedTables) {
                $joinedTables[] = $table;
                return $this->qb;
            });
        $this->result->method('fetchAll')->willReturn([]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->mapper->getMonthlyTrendDataByAccount('user1', '2026-01-01', '2026-01-31');

        $this->assertContains('budget_categories', $joinedTables,
            'getMonthlyTrendDataByAccount must left-join budget_categories to exclude flagged categories (#219)');
    }

    // ===== getTagTrendByMonth =====

    public function testGetTagTrendByMonthReturnsEmptyForEmptyTagIds(): void {
        $this->qb->expects($this->never())->method('executeQuery');

        $result = $this->mapper->getTagTrendByMonth('user1', [], '2026-01-01', '2026-01-31');

        $this->assertEmpty($result);
    }

    public function testGetTagTrendByMonthReturnsFormattedArray(): void {
        $this->result->method('fetchAll')->willReturn([
            ['month' => '2026-01', 'tag_id' => '1', 'tag_name' => 'Groceries', 'color' => '#00ff00', 'total' => '200.00'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $data = $this->mapper->getTagTrendByMonth('user1', [1], '2026-01-01', '2026-01-31');

        $this->assertCount(1, $data);
        $this->assertEquals('2026-01', $data[0]['month']);
        $this->assertEquals(1, $data[0]['tagId']);
        $this->assertEquals('Groceries', $data[0]['tagName']);
        $this->assertEquals(200.00, $data[0]['total']);
    }

    // ===== getSpendingByTag =====

    public function testGetSpendingByTagReturnsFormattedArray(): void {
        $this->result->method('fetchAll')->willReturn([
            ['id' => '1', 'name' => 'Essential', 'color' => '#ff0000', 'total' => '300.00', 'count' => '15'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $data = $this->mapper->getSpendingByTag('user1', 1, '2026-01-01', '2026-01-31');

        $this->assertCount(1, $data);
        $this->assertEquals(1, $data[0]['tagId']);
        $this->assertEquals('Essential', $data[0]['name']);
        $this->assertEquals(300.00, $data[0]['total']);
        $this->assertEquals(15, $data[0]['count']);
    }

    // ===== getTagDimensionsForCategory =====

    public function testGetTagDimensionsForCategoryGroupsByTagSet(): void {
        $this->result->method('fetchAll')->willReturn([
            ['tag_set_id' => '1', 'tag_set_name' => 'Priority', 'tag_id' => '10', 'tag_name' => 'High', 'color' => '#ff0000', 'total' => '200.00', 'count' => '5'],
            ['tag_set_id' => '1', 'tag_set_name' => 'Priority', 'tag_id' => '11', 'tag_name' => 'Low', 'color' => '#00ff00', 'total' => '100.00', 'count' => '3'],
            ['tag_set_id' => '2', 'tag_set_name' => 'Type', 'tag_id' => '20', 'tag_name' => 'Essential', 'color' => '#0000ff', 'total' => '150.00', 'count' => '4'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $dimensions = $this->mapper->getTagDimensionsForCategory('user1', 5, '2026-01-01', '2026-01-31');

        $this->assertCount(2, $dimensions);

        // First dimension: Priority
        $this->assertEquals(1, $dimensions[0]['tagSetId']);
        $this->assertEquals('Priority', $dimensions[0]['tagSetName']);
        $this->assertCount(2, $dimensions[0]['tags']);
        $this->assertEquals('High', $dimensions[0]['tags'][0]['name']);
        $this->assertEquals('Low', $dimensions[0]['tags'][1]['name']);

        // Second dimension: Type
        $this->assertEquals(2, $dimensions[1]['tagSetId']);
        $this->assertCount(1, $dimensions[1]['tags']);
    }

    // ===== getSpendingByTagCombination =====

    public function testGetSpendingByTagCombinationGroupsByTagSet(): void {
        $this->result->method('fetchAll')->willReturn([
            // Transaction 1 has tags 10 and 20
            ['id' => '1', 'amount' => '100.00', 'tag_id' => '10', 'tag_name' => 'A'],
            ['id' => '1', 'amount' => '100.00', 'tag_id' => '20', 'tag_name' => 'B'],
            // Transaction 2 has tags 10 and 20 (same combo)
            ['id' => '2', 'amount' => '50.00', 'tag_id' => '10', 'tag_name' => 'A'],
            ['id' => '2', 'amount' => '50.00', 'tag_id' => '20', 'tag_name' => 'B'],
            // Transaction 3 has only tag 10 (filtered out by minCombinationSize)
            ['id' => '3', 'amount' => '75.00', 'tag_id' => '10', 'tag_name' => 'A'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $combos = $this->mapper->getSpendingByTagCombination('user1', '2026-01-01', '2026-01-31');

        $this->assertCount(1, $combos);
        $this->assertEquals([10, 20], $combos[0]['tagIds']);
        $this->assertEquals(['A', 'B'], $combos[0]['tagNames']);
        $this->assertEquals(150.00, $combos[0]['total']);
        $this->assertEquals(2, $combos[0]['count']);
    }

    public function testGetSpendingByTagCombinationRespectsLimit(): void {
        // Build 3 transactions with different tag combos
        $this->result->method('fetchAll')->willReturn([
            ['id' => '1', 'amount' => '100.00', 'tag_id' => '10', 'tag_name' => 'A'],
            ['id' => '1', 'amount' => '100.00', 'tag_id' => '20', 'tag_name' => 'B'],
            ['id' => '2', 'amount' => '200.00', 'tag_id' => '30', 'tag_name' => 'C'],
            ['id' => '2', 'amount' => '200.00', 'tag_id' => '40', 'tag_name' => 'D'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $combos = $this->mapper->getSpendingByTagCombination(
            'user1', '2026-01-01', '2026-01-31',
            null, null, 2, 1  // limit=1
        );

        $this->assertCount(1, $combos);
        // Should be sorted by total DESC, so combo C+D (200) first
        $this->assertEquals(200.00, $combos[0]['total']);
    }

    // ===== getTagCrossTabulation =====

    public function testGetTagCrossTabulationBuildsMatrix(): void {
        $this->result->method('fetchAll')->willReturn([
            // Transaction 1: tag from set 1 (id=10) and set 2 (id=20)
            ['id' => '1', 'amount' => '100.00', 'tag_id' => '10', 'tag_name' => 'High', 'tag_set_id' => '1', 'color' => '#ff0000'],
            ['id' => '1', 'amount' => '100.00', 'tag_id' => '20', 'tag_name' => 'Essential', 'tag_set_id' => '2', 'color' => '#0000ff'],
            // Transaction 2: same tag combo
            ['id' => '2', 'amount' => '50.00', 'tag_id' => '10', 'tag_name' => 'High', 'tag_set_id' => '1', 'color' => '#ff0000'],
            ['id' => '2', 'amount' => '50.00', 'tag_id' => '20', 'tag_name' => 'Essential', 'tag_set_id' => '2', 'color' => '#0000ff'],
        ]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $result = $this->mapper->getTagCrossTabulation('user1', 1, 2, '2026-01-01', '2026-01-31');

        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('columns', $result);
        $this->assertArrayHasKey('data', $result);

        $this->assertCount(1, $result['rows']);    // One tag from set 1
        $this->assertCount(1, $result['columns']); // One tag from set 2
        $this->assertCount(1, $result['data']);     // One cell in matrix

        $this->assertEquals(150.00, $result['data'][0]['total']);
        $this->assertEquals(2, $result['data'][0]['count']);
    }

    // ===== getCategoryNetByMonthBatch (#288) =====

    public function testGetCategoryNetByMonthBatchMapsSignedNet(): void {
        $this->result->method('fetch')->willReturnOnConsecutiveCalls(
            ['category_id' => 5, 'bucket' => '2026-01', 'net_total' => '-80.00'],
            ['category_id' => 1, 'bucket' => '2026-02', 'net_total' => '3000.00'],
            false
        );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $out = $this->mapper->getCategoryNetByMonthBatch('user1', '2026-01-01', '2026-02-28');

        $this->assertSame(-80.0, $out[5]['2026-01']);
        $this->assertSame(3000.0, $out[1]['2026-02']);
    }
}
