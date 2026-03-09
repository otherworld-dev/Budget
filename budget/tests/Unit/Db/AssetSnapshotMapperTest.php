<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\AssetSnapshot;
use OCA\Budget\Db\AssetSnapshotMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class AssetSnapshotMapperTest extends TestCase {
	private AssetSnapshotMapper $mapper;
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

		$this->mapper = new AssetSnapshotMapper($this->db);
	}

	private function makeRow(array $overrides = []): array {
		return array_merge([
			'id' => 1,
			'user_id' => 'user1',
			'asset_id' => 10,
			'value' => 250000.00,
			'date' => '2026-01-01',
			'created_at' => '2026-01-01 00:00:00',
		], $overrides);
	}

	// ===== getTableName =====

	public function testTableNameIsCorrect(): void {
		$this->assertEquals('budget_asset_snaps', $this->mapper->getTableName());
	}

	// ===== find =====

	public function testFindReturnsSnapshot(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls($this->makeRow(), false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$snap = $this->mapper->find(1, 'user1');

		$this->assertInstanceOf(AssetSnapshot::class, $snap);
		$this->assertEquals(10, $snap->getAssetId());
		$this->assertEquals(250000.00, $snap->getValue());
	}

	public function testFindThrowsWhenNotFound(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$this->expectException(DoesNotExistException::class);
		$this->mapper->find(999, 'user1');
	}

	// ===== findByAsset =====

	public function testFindByAssetReturnsSnapshots(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				$this->makeRow(['id' => 1, 'date' => '2026-03-01']),
				$this->makeRow(['id' => 2, 'date' => '2026-02-01']),
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$snaps = $this->mapper->findByAsset(10, 'user1');

		$this->assertCount(2, $snaps);
	}

	public function testFindByAssetReturnsEmptyForNoSnapshots(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$snaps = $this->mapper->findByAsset(10, 'user1');

		$this->assertEmpty($snaps);
	}

	// ===== findByAssetInRange =====

	public function testFindByAssetInRangeReturnsFilteredSnapshots(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				$this->makeRow(['id' => 1, 'date' => '2026-01-15']),
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$snaps = $this->mapper->findByAssetInRange(10, 'user1', '2026-01-01', '2026-01-31');

		$this->assertCount(1, $snaps);
	}

	// ===== findLatest =====

	public function testFindLatestReturnsSnapshot(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls($this->makeRow(['date' => '2026-03-01']), false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$snap = $this->mapper->findLatest(10, 'user1');

		$this->assertInstanceOf(AssetSnapshot::class, $snap);
		$this->assertEquals('2026-03-01', $snap->getDate());
	}

	public function testFindLatestThrowsWhenNone(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$this->expectException(DoesNotExistException::class);
		$this->mapper->findLatest(10, 'user1');
	}

	// ===== deleteByAsset =====

	public function testDeleteByAssetExecutes(): void {
		$this->qb->expects($this->once())->method('executeStatement')->willReturn(3);

		$this->mapper->deleteByAsset(10, 'user1');

		// No return value, just verifying it executes without error
		$this->assertTrue(true);
	}

	// ===== deleteAll =====

	public function testDeleteAllReturnsAffectedRows(): void {
		$this->qb->method('executeStatement')->willReturn(5);

		$count = $this->mapper->deleteAll('user1');

		$this->assertEquals(5, $count);
	}

	public function testDeleteAllReturnsZero(): void {
		$this->qb->method('executeStatement')->willReturn(0);

		$count = $this->mapper->deleteAll('user1');

		$this->assertEquals(0, $count);
	}
}
