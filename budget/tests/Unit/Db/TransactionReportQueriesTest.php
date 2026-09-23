<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\TransactionReportQueries;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The QueryBuilder is fully mocked: these tests verify call structure and
 * result mapping, not the SQL itself.
 */
class TransactionReportQueriesTest extends TestCase {
	private TransactionReportQueries $mapper;
	private IDBConnection $db;
	/** @var IQueryBuilder&\PHPUnit\Framework\MockObject\MockObject */
	private $qb;
	private IExpressionBuilder $expr;
	private IFunctionBuilder $func;
	private IResult $result;
	/** Every SQL fragment passed to createFunction(), in call order. */
	private array $capturedFunctions = [];

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
		$this->capturedFunctions = [];
		$this->qb->method('createFunction')->willReturnCallback(function (string $sql) use ($mockFunction) {
			$this->capturedFunctions[] = $sql;
			return $mockFunction;
		});
		$this->func->method('sum')->willReturn($mockFunction);
		$this->func->method('count')->willReturn($mockFunction);

		foreach (['select', 'addSelect', 'selectAlias', 'from', 'where', 'andWhere',
			'orderBy', 'addOrderBy', 'innerJoin', 'leftJoin',
			'groupBy', 'addGroupBy', 'setMaxResults', 'setFirstResult'] as $method) {
			$this->qb->method($method)->willReturnSelf();
		}

		$this->mapper = new TransactionReportQueries($this->db);
	}

	/** One IResult mock yielding the given rows -- for methods that run more than one query. */
	private function resultOf(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$result->method('closeCursor');
		return $result;
	}

	// ===== getSpendingByVendor =====

	public function testGetSpendingByVendorReturnsFormattedArray(): void {
		// Report aggregates run a direct half, then a split half (#219)
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['vendor' => 'Starbucks', 'total' => '150.00', 'count' => '10'],
				['vendor' => '', 'total' => '50.00', 'count' => '3'],
			]),
			$this->resultOf([])
		);

		$data = $this->mapper->getSpendingByVendor('user1', null, '2026-01-01', '2026-01-31');

		$this->assertCount(2, $data);
		$this->assertEquals('Starbucks', $data[0]['name']);
		$this->assertEquals(150.00, $data[0]['total']);
		$this->assertEquals(10, $data[0]['count']);
		// Empty vendor mapped to 'Unknown'
		$this->assertEquals('Unknown', $data[1]['name']);
		// ... and flagged, so a consumer can label it in the user's language (#377)
		$this->assertTrue($data[1]['unknown']);
		$this->assertFalse($data[0]['unknown']);
	}

	// ===== getIncomeBySource =====

	public function testGetIncomeBySourceReturnsFormattedArray(): void {
		// Report aggregates run a direct half, then a split half (#219)
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['vendor' => 'Employer Inc', 'total' => '5000.00', 'count' => '1'],
				['vendor' => '', 'total' => '200.00', 'count' => '2'],
			]),
			$this->resultOf([])
		);

		$data = $this->mapper->getIncomeBySource('user1', null, '2026-01-01', '2026-01-31');

		$this->assertCount(2, $data);
		$this->assertEquals('Employer Inc', $data[0]['name']);
		$this->assertEquals(5000.00, $data[0]['total']);
		// Empty vendor mapped to 'Unknown Source'
		$this->assertEquals('Unknown Source', $data[1]['name']);
		// ... and flagged, so a consumer can label it in the user's language (#377)
		$this->assertTrue($data[1]['unknown']);
		$this->assertFalse($data[0]['unknown']);
	}

	// ===== getCashFlowByMonth =====

	public function testGetCashFlowByMonthCalculatesNet(): void {
		// Report aggregates run a direct half, then a split half (#219)
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['month' => '2026-01', 'income' => '3000.00', 'expenses' => '2000.00', 'count' => '4'],
				['month' => '2026-02', 'income' => '3500.00', 'expenses' => '4000.00', 'count' => '6'],
			]),
			$this->resultOf([])
		);

		$data = $this->mapper->getCashFlowByMonth('user1', null, '2026-01-01', '2026-02-28');

		$this->assertCount(2, $data);
		$this->assertEquals('2026-01', $data[0]['month']);
		$this->assertEquals(3000.00, $data[0]['income']);
		$this->assertEquals(2000.00, $data[0]['expenses']);
		$this->assertEquals(1000.00, $data[0]['net']);

		// Negative net
		$this->assertEquals(-500.00, $data[1]['net']);
	}

	// ===== getSpendingByAccountAggregated =====

	public function testGetSpendingByAccountAggregatedReturnsFormattedArray(): void {
		// Report aggregates run a direct half, then a split half (#219)
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['id' => '1', 'name' => 'Checking', 'total' => '1000.00', 'count' => '20'],
				['id' => '2', 'name' => 'Credit Card', 'total' => '500.00', 'count' => '10'],
			]),
			$this->resultOf([])
		);

		$data = $this->mapper->getSpendingByAccountAggregated('user1', '2026-01-01', '2026-01-31');

		$this->assertCount(2, $data);
		$this->assertEquals('Checking', $data[0]['name']);
		$this->assertEquals(1000.00, $data[0]['total']);
		$this->assertEquals(20, $data[0]['count']);
		$this->assertEquals(50.00, $data[0]['average']); // 1000/20
	}

	public function testGetSpendingByAccountAggregatedZeroCountAverageIsZero(): void {
		// Report aggregates run a direct half, then a split half (#219)
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['id' => '1', 'name' => 'Empty', 'total' => '0.00', 'count' => '0'],
			]),
			$this->resultOf([])
		);

		$data = $this->mapper->getSpendingByAccountAggregated('user1', '2026-01-01', '2026-01-31');

		$this->assertEquals(0, $data[0]['average']);
	}

	// ===== getMonthlyTrendData =====

	public function testGetMonthlyTrendDataReturnsFormattedArray(): void {
		// Report aggregates run a direct half, then a split half (#219)
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['month' => '2026-01', 'income' => '5000.00', 'expenses' => '3000.00', 'count' => '9'],
			]),
			$this->resultOf([])
		);

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
		// Report aggregates run a direct half, then a split half
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['month' => '2026-01', 'tag_id' => '1', 'tag_name' => 'Groceries', 'color' => '#00ff00', 'total' => '200.00'],
			]),
			$this->resultOf([])
		);

		$data = $this->mapper->getTagTrendByMonth('user1', [1], '2026-01-01', '2026-01-31');

		$this->assertCount(1, $data);
		$this->assertEquals('2026-01', $data[0]['month']);
		$this->assertEquals(1, $data[0]['tagId']);
		$this->assertEquals('Groceries', $data[0]['tagName']);
		$this->assertEquals(200.00, $data[0]['total']);
	}

	// ===== getSpendingByTag =====

	public function testGetSpendingByTagReturnsFormattedArray(): void {
		// Report aggregates run a direct half, then a split half (#219)
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['id' => '1', 'name' => 'Essential', 'color' => '#ff0000', 'total' => '300.00', 'count' => '15'],
			]),
			$this->resultOf([])
		);

		$data = $this->mapper->getSpendingByTag('user1', 1, '2026-01-01', '2026-01-31');

		$this->assertCount(1, $data);
		$this->assertEquals(1, $data[0]['tagId']);
		$this->assertEquals('Essential', $data[0]['name']);
		$this->assertEquals(300.00, $data[0]['total']);
		$this->assertEquals(15, $data[0]['count']);
	}

	// ===== getTagDimensionsForCategory =====

	public function testGetTagDimensionsForCategoryGroupsByTagSet(): void {
		// Report aggregates run a direct half, then a split half
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['tag_set_id' => '1', 'tag_set_name' => 'Priority', 'tag_id' => '10', 'tag_name' => 'High', 'color' => '#ff0000', 'total' => '200.00', 'count' => '5'],
				['tag_set_id' => '1', 'tag_set_name' => 'Priority', 'tag_id' => '11', 'tag_name' => 'Low', 'color' => '#00ff00', 'total' => '100.00', 'count' => '3'],
				['tag_set_id' => '2', 'tag_set_name' => 'Type', 'tag_id' => '20', 'tag_name' => 'Essential', 'color' => '#0000ff', 'total' => '150.00', 'count' => '4'],
			]),
			$this->resultOf([])
		);

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
		// Report aggregates run a direct half, then a split half
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				// Transaction 1 has tags 10 and 20
				['id' => '1', 'amount' => '100.00', 'tag_id' => '10', 'tag_name' => 'A', 'tag_set_id' => '1'],
				['id' => '1', 'amount' => '100.00', 'tag_id' => '20', 'tag_name' => 'B', 'tag_set_id' => '1'],
				// Transaction 2 has tags 10 and 20 (same combo)
				['id' => '2', 'amount' => '50.00', 'tag_id' => '10', 'tag_name' => 'A', 'tag_set_id' => '1'],
				['id' => '2', 'amount' => '50.00', 'tag_id' => '20', 'tag_name' => 'B', 'tag_set_id' => '1'],
				// Transaction 3 has only tag 10 (filtered out by minCombinationSize)
				['id' => '3', 'amount' => '75.00', 'tag_id' => '10', 'tag_name' => 'A', 'tag_set_id' => '1'],
			]),
			$this->resultOf([])
		);

		$combos = $this->mapper->getSpendingByTagCombination('user1', '2026-01-01', '2026-01-31');

		$this->assertCount(1, $combos);
		$this->assertEquals([10, 20], $combos[0]['tagIds']);
		$this->assertEquals(['A', 'B'], $combos[0]['tagNames']);
		$this->assertEquals(150.00, $combos[0]['total']);
		$this->assertEquals(2, $combos[0]['count']);
	}

	public function testGetSpendingByTagCombinationRespectsLimit(): void {
		// Build 3 transactions with different tag combos
		// Report aggregates run a direct half, then a split half
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['id' => '1', 'amount' => '100.00', 'tag_id' => '10', 'tag_name' => 'A', 'tag_set_id' => '1'],
				['id' => '1', 'amount' => '100.00', 'tag_id' => '20', 'tag_name' => 'B', 'tag_set_id' => '1'],
				['id' => '2', 'amount' => '200.00', 'tag_id' => '30', 'tag_name' => 'C', 'tag_set_id' => '1'],
				['id' => '2', 'amount' => '200.00', 'tag_id' => '40', 'tag_name' => 'D', 'tag_set_id' => '1'],
			]),
			$this->resultOf([])
		);

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
		// Report aggregates run a direct half, then a split half
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				// Transaction 1: tag from set 1 (id=10) and set 2 (id=20)
				['id' => '1', 'amount' => '100.00', 'tag_id' => '10', 'tag_name' => 'High', 'tag_set_id' => '1', 'color' => '#ff0000'],
				['id' => '1', 'amount' => '100.00', 'tag_id' => '20', 'tag_name' => 'Essential', 'tag_set_id' => '2', 'color' => '#0000ff'],
				// Transaction 2: same tag combo
				['id' => '2', 'amount' => '50.00', 'tag_id' => '10', 'tag_name' => 'High', 'tag_set_id' => '1', 'color' => '#ff0000'],
				['id' => '2', 'amount' => '50.00', 'tag_id' => '20', 'tag_name' => 'Essential', 'tag_set_id' => '2', 'color' => '#0000ff'],
			]),
			$this->resultOf([])
		);

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

	// ===== report aggregates drop linked transfers in the all-accounts view (#349) =====

	/** @var string[] every column passed to expr()->isNull() during the mapper call */
	private array $isNullColumns = [];

	private function trackIsNullColumns(): void {
		$this->isNullColumns = [];
		$this->expr->method('isNull')->willReturnCallback(function (string $column) {
			$this->isNullColumns[] = $column;
			return $column . ' IS NULL';
		});
		$this->result->method('fetchAll')->willReturn([]);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);
	}

	public function testGetIncomeByMonthAllAccountsExcludesLinkedTransfers(): void {
		$this->trackIsNullColumns();
		$this->mapper->getIncomeByMonth('user1', null, '2026-01-01', '2026-01-31');
		$this->assertContains('t.linked_transaction_id', $this->isNullColumns);
	}

	public function testGetIncomeBySourceAllAccountsExcludesLinkedTransfers(): void {
		$this->trackIsNullColumns();
		$this->mapper->getIncomeBySource('user1', null, '2026-01-01', '2026-01-31');
		$this->assertContains('t.linked_transaction_id', $this->isNullColumns);
	}

	public function testGetSpendingByMonthAllAccountsExcludesLinkedTransfers(): void {
		$this->trackIsNullColumns();
		$this->mapper->getSpendingByMonth('user1', null, '2026-01-01', '2026-01-31');
		$this->assertContains('t.linked_transaction_id', $this->isNullColumns);
	}

	public function testGetSpendingByVendorAllAccountsExcludesLinkedTransfers(): void {
		$this->trackIsNullColumns();
		$this->mapper->getSpendingByVendor('user1', null, '2026-01-01', '2026-01-31');
		$this->assertContains('t.linked_transaction_id', $this->isNullColumns);
	}

	public function testGetSpendingByAccountAggregatedExcludesLinkedTransfers(): void {
		$this->trackIsNullColumns();
		$this->mapper->getSpendingByAccountAggregated('user1', '2026-01-01', '2026-01-31');
		$this->assertContains('t.linked_transaction_id', $this->isNullColumns);
	}

	public function testGetIncomeByTagAllAccountsExcludesLinkedTransfers(): void {
		$this->trackIsNullColumns();
		$this->mapper->getIncomeByTag('user1', 3, '2026-01-01', '2026-01-31', null);
		$this->assertContains('t.linked_transaction_id', $this->isNullColumns);
	}

	public function testGetSpendingByTagAllAccountsExcludesLinkedTransfers(): void {
		$this->trackIsNullColumns();
		$this->mapper->getSpendingByTag('user1', 3, '2026-01-01', '2026-01-31', null);
		$this->assertContains('t.linked_transaction_id', $this->isNullColumns);
	}

	public function testSingleAccountReportAggregatesKeepTransferLegs(): void {
		$this->trackIsNullColumns();
		$calls = [
			'getIncomeByMonth' => fn () => $this->mapper->getIncomeByMonth('user1', 5, '2026-01-01', '2026-01-31'),
			'getIncomeBySource' => fn () => $this->mapper->getIncomeBySource('user1', 5, '2026-01-01', '2026-01-31'),
			'getSpendingByMonth' => fn () => $this->mapper->getSpendingByMonth('user1', 5, '2026-01-01', '2026-01-31'),
			'getSpendingByVendor' => fn () => $this->mapper->getSpendingByVendor('user1', 5, '2026-01-01', '2026-01-31'),
			'getIncomeByTag' => fn () => $this->mapper->getIncomeByTag('user1', 3, '2026-01-01', '2026-01-31', 5),
			'getSpendingByTag' => fn () => $this->mapper->getSpendingByTag('user1', 3, '2026-01-01', '2026-01-31', 5),
		];
		foreach ($calls as $method => $call) {
			$this->isNullColumns = [];
			$call();
			$this->assertNotContains('t.linked_transaction_id', $this->isNullColumns, $method);
		}
	}

	// ===== report aggregates: one scope for every grouping (#219) =====

	/**
	 * The report groupings every run a direct half and a split half. Each
	 * returns its own totals per group; merged, a month holding a direct
	 * purchase and a split receipt reports both, counted once each.
	 */
	public function testGetSpendingByMonthMergesTheSplitHalf(): void {
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['month' => '2026-02', 'total' => '10.10', 'count' => '1'],
				['month' => '2026-01', 'total' => '50.10', 'count' => '2'],
			]),
			$this->resultOf([
				['month' => '2026-01', 'total' => '60.00', 'count' => '1'],
				['month' => '2026-03', 'total' => '15.00', 'count' => '1'],
			])
		);

		$data = $this->mapper->getSpendingByMonth('user1', null, '2026-01-01', '2026-03-31');

		$this->assertSame([
			['month' => '2026-01', 'total' => 110.1, 'count' => 3],
			['month' => '2026-02', 'total' => 10.1, 'count' => 1],
			['month' => '2026-03', 'total' => 15.0, 'count' => 1],
		], $data);
	}

	/**
	 * The vendor list is cut to its limit AFTER the halves merge: a vendor
	 * whose money is spread over both halves must rank by its whole total.
	 */
	public function testGetSpendingByVendorLimitsAfterMergingTheHalves(): void {
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([
				['vendor' => 'Big', 'total' => '100.00', 'count' => '1'],
				['vendor' => 'Costco', 'total' => '60.00', 'count' => '1'],
			]),
			$this->resultOf([
				['vendor' => 'Costco', 'total' => '60.00', 'count' => '1'],
			])
		);

		$data = $this->mapper->getSpendingByVendor('user1', null, '2026-01-01', '2026-01-31', 1);

		$this->assertCount(1, $data);
		$this->assertSame('Costco', $data[0]['name']);
		$this->assertSame(120.0, $data[0]['total']);
		$this->assertSame(2, $data[0]['count']);
	}

	/**
	 * A netted SQLite sum comes back in exponent form ("1.4e-14"), which
	 * bcmath rejects; the merge must take it rather than throw.
	 */
	public function testReportMergeAcceptsExponentFormSums(): void {
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->resultOf([['month' => '2026-01', 'total' => '1.4210854715202e-14', 'count' => '1']]),
			$this->resultOf([['month' => '2026-01', 'total' => '5.00', 'count' => '1']])
		);

		$data = $this->mapper->getSpendingByMonth('user1', null, '2026-01-01', '2026-01-31');

		$this->assertSame(5.0, $data[0]['total']);
	}

	/**
	 * Every report grouping routes its category through the choke point, in
	 * BOTH halves: the direct half joins the transaction's own category, the
	 * split half each part's category — a filter on the parent row alone
	 * cannot see a part filed under an excluded category. The viewer's
	 * mutes join the same category row.
	 *
	 * @dataProvider reportGroupingProvider
	 */
	public function testReportGroupingsExcludeCategoriesInBothHalves(string $method, array $args): void {
		$joins = [];
		$this->qb->method('leftJoin')->willReturnCallback(function ($from, $table) use (&$joins) {
			$joins[] = "{$from}->{$table}";
			return $this->qb;
		});
		$splitJoins = [];
		$this->qb->method('innerJoin')->willReturnCallback(function ($from, $table) use (&$splitJoins) {
			if ($table === 'budget_tx_splits') {
				$splitJoins[] = $from;
			}
			return $this->qb;
		});
		$this->qb->method('executeQuery')->willReturnCallback(fn () => $this->resultOf([]));

		$this->mapper->{$method}(...$args);

		$this->assertContains('t->budget_categories', $joins, "{$method}: direct half joins its own category");
		$this->assertContains('s->budget_categories', $joins, "{$method}: split half joins each part's category");
		$this->assertContains('exc->budget_cat_mutes', $joins, "{$method}: the viewer's mutes apply");
		$this->assertSame(['t'], $splitJoins, "{$method}: exactly one half reads the split parts");
	}

	public static function reportGroupingProvider(): array {
		return [
			'spending by month' => ['getSpendingByMonth', ['user1', null, '2026-01-01', '2026-01-31']],
			'spending by vendor' => ['getSpendingByVendor', ['user1', null, '2026-01-01', '2026-01-31']],
			'income by month' => ['getIncomeByMonth', ['user1', null, '2026-01-01', '2026-01-31']],
			'income by source' => ['getIncomeBySource', ['user1', null, '2026-01-01', '2026-01-31']],
			'cash flow by month' => ['getCashFlowByMonth', ['user1', null, '2026-01-01', '2026-01-31']],
			'cash flow by account' => ['getCashFlowByMonthByAccount', ['user1', '2026-01-01', '2026-01-31']],
			'trend' => ['getMonthlyTrendData', ['user1', null, '2026-01-01', '2026-01-31']],
			'trend by account' => ['getMonthlyTrendDataByAccount', ['user1', '2026-01-01', '2026-01-31']],
			'spending by account' => ['getSpendingByAccountAggregated', ['user1', '2026-01-01', '2026-01-31']],
			'spending by tag' => ['getSpendingByTag', ['user1', 3, '2026-01-01', '2026-01-31']],
			'income by tag' => ['getIncomeByTag', ['user1', 3, '2026-01-01', '2026-01-31']],
		];
	}

	/**
	 * The split half of a tag grouping filtered to a category matches the
	 * PART's category, not the parent's (which a split nulls).
	 */
	public function testTagGroupingCategoryFilterReadsThePartCategoryInTheSplitHalf(): void {
		$eqColumns = [];
		$this->expr->method('eq')->willReturnCallback(function ($column) use (&$eqColumns) {
			$eqColumns[] = $column;
			return 'eq';
		});
		$this->qb->method('executeQuery')->willReturnCallback(fn () => $this->resultOf([]));

		$this->mapper->getSpendingByTag('user1', 3, '2026-01-01', '2026-01-31', null, 7);

		$this->assertContains('t.category_id', $eqColumns);
		$this->assertContains('s.category_id', $eqColumns);
	}

	/**
	 * The tag filter is a correlated EXISTS, never a join: joined, a
	 * transaction carrying two of the chosen tags was summed twice.
	 */
	public function testTagFilterUsesExistsNotAJoin(): void {
		$this->qb->method('getTableName')->willReturnCallback(fn (string $t) => '*PREFIX*' . $t);
		$tagJoins = 0;
		$countJoin = function ($from, $table) use (&$tagJoins) {
			if ($table === 'budget_transaction_tags') {
				$tagJoins++;
			}
			return $this->qb;
		};
		$this->qb->method('leftJoin')->willReturnCallback($countJoin);
		$this->qb->method('innerJoin')->willReturnCallback($countJoin);
		$where = [];
		$this->expr->method('orX')->willReturnCallback(function (...$parts) use (&$where) {
			foreach ($parts as $part) {
				if (is_string($part)) {
					$where[] = $part;
				}
			}
			return $this->createMock(ICompositeExpression::class);
		});
		$this->qb->method('executeQuery')->willReturnCallback(fn () => $this->resultOf([]));

		$this->mapper->getCashFlowByMonth('user1', null, '2026-01-01', '2026-01-31', [4, 9], true);

		$this->assertSame(0, $tagJoins);
		$sql = implode("\n", $where);
		$this->assertStringContainsString('EXISTS (SELECT 1 FROM *PREFIX*budget_transaction_tags btt WHERE btt.transaction_id = t.id AND btt.tag_id IN (4, 9))', $sql);
		$this->assertStringContainsString('NOT EXISTS (SELECT 1 FROM *PREFIX*budget_transaction_tags btu WHERE btu.transaction_id = t.id)', $sql);
	}
}
