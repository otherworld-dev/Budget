<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\QueryFilterBuilder;
use OCA\Budget\Db\TransactionMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The two queries behind the runway. The QueryBuilder is mocked, so these
 * check call structure and result mapping, not what the SQL returns.
 */
class TransactionMapperRunwayTest extends TestCase {
	private TransactionMapper $mapper;
	private IDBConnection $db;
	private $qb;
	private IExpressionBuilder $expr;
	private IResult $result;
	/** Every [method, args] recorded on the fluent builder. */
	private array $calls = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(TransactionQueryBuilder::class);
		$this->expr = $this->createMock(IExpressionBuilder::class);
		$this->result = $this->createMock(IResult::class);

		$this->db->method('getQueryBuilder')->willReturn($this->qb);
		$this->qb->method('expr')->willReturn($this->expr);
		$this->qb->method('createNamedParameter')->willReturn(':param');
		$this->qb->method('createFunction')->willReturn($this->createMock(IQueryFunction::class));
		$this->qb->method('executeQuery')->willReturn($this->result);

		$this->calls = [];
		foreach (['select', 'addSelect', 'selectAlias', 'from', 'where', 'andWhere',
			'orderBy', 'leftJoin', 'setMaxResults'] as $method) {
			$this->qb->method($method)->willReturnCallback(function (...$args) use ($method) {
				$this->calls[] = [$method, $args];
				return $this->qb;
			});
		}

		$this->mapper = new TransactionMapper($this->db, $this->createMock(QueryFilterBuilder::class));
	}

	private function callsTo(string $method): array {
		return array_values(array_filter($this->calls, fn ($c) => $c[0] === $method));
	}

	public function testFindRunwayRowsWithNoAccountsSkipsTheQuery(): void {
		$this->db->expects($this->never())->method('getQueryBuilder');
		$this->assertSame([], $this->mapper->findRunwayRows([], '2026-01-01', '2026-12-31'));
	}

	public function testFindRunwayRowsJoinsThePartnerOnLinkedTransactionId(): void {
		$this->expr->expects($this->atLeastOnce())->method('eq')
			->willReturnCallback(function ($a, $b) {
				return $a . '=' . $b;
			});
		$this->result->method('fetchAll')->willReturn([]);

		$this->mapper->findRunwayRows([1, 2], null, null);

		$joins = $this->callsTo('leftJoin');
		$this->assertCount(1, $joins);
		$this->assertSame('t', $joins[0][1][0]);
		$this->assertSame('lt', $joins[0][1][2]);
		$this->assertSame('t.linked_transaction_id=lt.id', $joins[0][1][3]);
		$aliases = array_column(array_map(fn ($c) => $c[1], $this->callsTo('selectAlias')), 1);
		$this->assertContains('linked_account_id', $aliases);
	}

	public function testFindRunwayRowsAppliesDateBoundsOnlyWhenGiven(): void {
		$this->result->method('fetchAll')->willReturn([]);
		$this->expr->method('gte')->willReturnCallback(fn ($a) => "gte:$a");
		$this->expr->method('lte')->willReturnCallback(fn ($a) => "lte:$a");

		$this->mapper->findRunwayRows([1], null, null);
		$bare = count($this->callsTo('andWhere'));

		$this->calls = [];
		$this->mapper->findRunwayRows([1], '2026-01-01', null);
		$this->assertCount($bare + 1, $this->callsTo('andWhere'));
		$this->assertContains('gte:t.date', array_map(fn ($c) => $c[1][0], $this->callsTo('andWhere')));

		$this->calls = [];
		$this->mapper->findRunwayRows([1], '2026-01-01', '2026-12-31');
		$this->assertCount($bare + 2, $this->callsTo('andWhere'));
	}

	public function testFindRunwayRowsCastsRowsAndTreatsNullFlagsAsFalse(): void {
		$this->result->method('fetchAll')->willReturn([[
			'id' => '7', 'account_id' => '3', 'date' => '2026-02-01', 'amount' => '-12.50',
			'description' => 'Rent', 'category_id' => null, 'status' => 'scheduled',
			'excluded_from_forecast' => null, 'pension_contrib_id' => '4',
			'linked_transaction_id' => '9', 'linked_account_id' => '5',
			'bill_id' => null, 'is_split' => '1',
		]]);

		$rows = $this->mapper->findRunwayRows([3], null, null);

		$this->assertSame([[
			'id' => 7, 'account_id' => 3, 'date' => '2026-02-01', 'amount' => '-12.50',
			'description' => 'Rent', 'category_id' => null, 'status' => 'scheduled',
			'excluded_from_forecast' => false, 'pension_contrib_id' => 4,
			'linked_transaction_id' => 9, 'linked_account_id' => 5,
			'bill_id' => null, 'is_split' => true,
		]], $rows);
	}

	public function testFirstTransactionDateWithNoAccountsIsNull(): void {
		$this->db->expects($this->never())->method('getQueryBuilder');
		$this->assertNull($this->mapper->firstTransactionDate([]));
	}

	public function testFirstTransactionDateReturnsTheEarliestOrNull(): void {
		$this->result->method('fetchOne')->willReturnOnConsecutiveCalls('2025-03-04', false, null);

		$this->assertSame('2025-03-04', $this->mapper->firstTransactionDate([1]));
		$this->assertNull($this->mapper->firstTransactionDate([1]));
		$this->assertNull($this->mapper->firstTransactionDate([1]));
	}
}
