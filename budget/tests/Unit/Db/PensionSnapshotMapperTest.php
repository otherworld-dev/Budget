<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\PensionSnapshot;
use OCA\Budget\Db\PensionSnapshotMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class PensionSnapshotMapperTest extends TestCase {
	private PensionSnapshotMapper $mapper;
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
				   'insert', 'delete', 'update', 'set', 'setValue',
				   'setMaxResults'] as $method) {
			$this->qb->method($method)->willReturnSelf();
		}

		$this->mapper = new PensionSnapshotMapper($this->db);
	}

	private function makeRow(array $overrides = []): array {
		return array_merge([
			'id' => 1,
			'user_id' => 'user1',
			'pension_id' => 5,
			'balance' => 45000.00,
			'date' => '2026-03-01',
			'created_at' => '2026-03-01 00:00:00',
		], $overrides);
	}

	// ===== getTableName =====

	public function testTableNameIsCorrect(): void {
		$this->assertEquals('budget_pen_snaps', $this->mapper->getTableName());
	}

	// ===== find =====

	public function testFindReturnsSnapshot(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls($this->makeRow(), false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$snap = $this->mapper->find(1, 'user1');

		$this->assertInstanceOf(PensionSnapshot::class, $snap);
		$this->assertEquals(5, $snap->getPensionId());
		$this->assertEquals(45000.00, $snap->getBalance());
	}

	public function testFindThrowsWhenNotFound(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$this->expectException(DoesNotExistException::class);
		$this->mapper->find(999, 'user1');
	}

	// ===== findByPension =====

	public function testFindByPensionReturnsSnapshots(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				$this->makeRow(['id' => 1, 'date' => '2026-03-01', 'balance' => 46000.00]),
				$this->makeRow(['id' => 2, 'date' => '2026-02-01', 'balance' => 45000.00]),
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$snaps = $this->mapper->findByPension(5, 'user1');

		$this->assertCount(2, $snaps);
	}

	public function testFindByPensionReturnsEmptyWhenNone(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$snaps = $this->mapper->findByPension(5, 'user1');

		$this->assertEmpty($snaps);
	}

	// ===== findByPensionInRange =====

	public function testFindByPensionInRangeReturnsFiltered(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				$this->makeRow(['date' => '2026-01-15']),
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$snaps = $this->mapper->findByPensionInRange(5, 'user1', '2026-01-01', '2026-01-31');

		$this->assertCount(1, $snaps);
	}

	// ===== findLatest =====

	public function testFindLatestReturnsSnapshot(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls($this->makeRow(['date' => '2026-03-01']), false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$snap = $this->mapper->findLatest(5, 'user1');

		$this->assertInstanceOf(PensionSnapshot::class, $snap);
		$this->assertEquals('2026-03-01', $snap->getDate());
	}

	public function testFindLatestThrowsWhenNone(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$this->expectException(DoesNotExistException::class);
		$this->mapper->findLatest(5, 'user1');
	}

	// ===== deleteByPension =====

	public function testDeleteByPensionExecutes(): void {
		$this->qb->expects($this->once())->method('executeStatement')->willReturn(3);

		$this->mapper->deleteByPension(5, 'user1');

		$this->assertTrue(true);
	}

	// ===== deleteAll =====

	public function testDeleteAllReturnsAffectedRows(): void {
		$this->qb->method('executeStatement')->willReturn(10);

		$count = $this->mapper->deleteAll('user1');

		$this->assertEquals(10, $count);
	}

	public function testDeleteAllReturnsZero(): void {
		$this->qb->method('executeStatement')->willReturn(0);

		$count = $this->mapper->deleteAll('user1');

		$this->assertEquals(0, $count);
	}
}
