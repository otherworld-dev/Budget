<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Settlement;
use OCA\Budget\Db\SettlementMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class SettlementMapperTest extends TestCase {
	private SettlementMapper $mapper;
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
				   'orderBy', 'groupBy', 'insert', 'delete', 'update',
				   'set', 'setValue'] as $method) {
			$this->qb->method($method)->willReturnSelf();
		}

		$this->mapper = new SettlementMapper($this->db);
	}

	private function makeRow(array $overrides = []): array {
		return array_merge([
			'id' => 1,
			'user_id' => 'user1',
			'contact_id' => 3,
			'amount' => 50.00,
			'date' => '2026-03-01',
			'notes' => 'Dinner split',
			'created_at' => '2026-03-01 00:00:00',
		], $overrides);
	}

	// ===== getTableName =====

	public function testTableNameIsCorrect(): void {
		$this->assertEquals('budget_settlements', $this->mapper->getTableName());
	}

	// ===== find =====

	public function testFindReturnsSettlement(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls($this->makeRow(), false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$settlement = $this->mapper->find(1, 'user1');

		$this->assertInstanceOf(Settlement::class, $settlement);
		$this->assertEquals(3, $settlement->getContactId());
		$this->assertEquals(50.00, $settlement->getAmount());
		$this->assertEquals('Dinner split', $settlement->getNotes());
	}

	public function testFindThrowsWhenNotFound(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$this->expectException(DoesNotExistException::class);
		$this->mapper->find(999, 'user1');
	}

	// ===== findAll =====

	public function testFindAllReturnsSettlements(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				$this->makeRow(['id' => 1]),
				$this->makeRow(['id' => 2, 'amount' => 25.00]),
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$settlements = $this->mapper->findAll('user1');

		$this->assertCount(2, $settlements);
	}

	public function testFindAllReturnsEmptyForNoSettlements(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$settlements = $this->mapper->findAll('user1');

		$this->assertEmpty($settlements);
	}

	// ===== findByContact =====

	public function testFindByContactReturnsFiltered(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				$this->makeRow(['id' => 1, 'contact_id' => 3]),
				$this->makeRow(['id' => 2, 'contact_id' => 3, 'amount' => 30.00]),
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$settlements = $this->mapper->findByContact(3, 'user1');

		$this->assertCount(2, $settlements);
	}

	public function testFindByContactReturnsEmptyForNoMatches(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$settlements = $this->mapper->findByContact(99, 'user1');

		$this->assertEmpty($settlements);
	}

	// ===== getTotalsByContact =====

	public function testGetTotalsByContactReturnsTotals(): void {
		$sumFunc = $this->createMock(IQueryFunction::class);
		$this->func->method('sum')->willReturn($sumFunc);
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				['contact_id' => 3, 'total' => '150.00'],
				['contact_id' => 7, 'total' => '-25.50'],
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$totals = $this->mapper->getTotalsByContact('user1');

		$this->assertCount(2, $totals);
		$this->assertEquals(150.00, $totals[3]);
		$this->assertEquals(-25.50, $totals[7]);
	}

	public function testGetTotalsByContactReturnsEmptyForNoSettlements(): void {
		$sumFunc = $this->createMock(IQueryFunction::class);
		$this->func->method('sum')->willReturn($sumFunc);
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$totals = $this->mapper->getTotalsByContact('user1');

		$this->assertEmpty($totals);
	}

	// ===== deleteAll =====

	public function testDeleteAllReturnsAffectedRows(): void {
		$this->qb->method('executeStatement')->willReturn(4);

		$count = $this->mapper->deleteAll('user1');

		$this->assertEquals(4, $count);
	}

	public function testDeleteAllReturnsZero(): void {
		$this->qb->method('executeStatement')->willReturn(0);

		$count = $this->mapper->deleteAll('user1');

		$this->assertEquals(0, $count);
	}
}
