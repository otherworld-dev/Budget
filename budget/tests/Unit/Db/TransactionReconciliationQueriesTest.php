<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\TransactionReconciliationQueries;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Shape checks over a mocked query builder; the SQL itself is proven by
 * Integration\Db\ReconciliationTickScopeTest.
 */
class TransactionReconciliationQueriesTest extends TestCase {
	private TransactionReconciliationQueries $queries;
	/** @var IQueryBuilder&\PHPUnit\Framework\MockObject\MockObject */
	private $qb;
	/** @var string[] "column" of every eq/neq/isNull the queries build, prefixed with the method */
	private array $conditions = [];
	/** @var array<int, array{0: string, 1: mixed}> every set() call, in order */
	private array $sets = [];
	private int $statements = 0;

	protected function setUp(): void {
		$db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$db->method('getQueryBuilder')->willReturn($this->qb);
		$this->qb->method('expr')->willReturn($expr);
		$this->qb->method('createNamedParameter')->willReturnCallback(fn ($value) => is_scalar($value) ? (string)$value : ':param');
		foreach (['eq', 'neq', 'isNull', 'in'] as $method) {
			$expr->method($method)->willReturnCallback(function ($x, $y = null) use ($method) {
				$this->conditions[] = "{$method}:{$x}" . ($y !== null ? "={$y}" : '');
				return "{$method}:{$x}";
			});
		}
		foreach (['update', 'where', 'andWhere'] as $method) {
			$this->qb->method($method)->willReturnSelf();
		}
		$this->qb->method('set')->willReturnCallback(function ($column, $value) {
			$this->sets[] = [$column, $value];
			return $this->qb;
		});
		$this->qb->method('executeStatement')->willReturnCallback(function () {
			$this->statements++;
			return 1;
		});

		$this->queries = new TransactionReconciliationQueries($db);
	}

	public function testTickingLeavesScheduledRowsAlone(): void {
		$this->queries->tickIntoSession(7, [1, 2], 11);

		$this->assertContains('neq:status=scheduled', $this->conditions);
	}

	public function testFinishingReleasesScheduledRowsBeforeMarkingTheRestReconciled(): void {
		$this->queries->markSessionReconciled(11);

		$this->assertSame(2, $this->statements);
		$this->assertSame('recon_session_id', $this->sets[0][0], 'the release runs first');
		$this->assertContains('eq:status=scheduled', $this->conditions);
		$this->assertSame('reconciled', $this->sets[1][0]);
	}
}
