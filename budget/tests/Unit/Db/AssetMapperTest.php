<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Asset;
use OCA\Budget\Db\AssetMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class AssetMapperTest extends TestCase {
	private AssetMapper $mapper;
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

		foreach (['select', 'from', 'where', 'andWhere', 'orderBy',
				   'insert', 'delete', 'update', 'set', 'setValue'] as $method) {
			$this->qb->method($method)->willReturnSelf();
		}

		$this->mapper = new AssetMapper($this->db);
	}

	private function makeAssetRow(array $overrides = []): array {
		return array_merge([
			'id' => 1,
			'user_id' => 'user1',
			'name' => 'House',
			'type' => 'real_estate',
			'description' => 'Primary residence',
			'currency' => 'GBP',
			'current_value' => 250000.00,
			'purchase_price' => 200000.00,
			'purchase_date' => '2020-01-15',
			'annual_change_rate' => 3.5,
			'created_at' => '2026-01-01 00:00:00',
			'updated_at' => '2026-01-01 00:00:00',
		], $overrides);
	}

	// ===== getTableName =====

	public function testTableNameIsCorrect(): void {
		$this->assertEquals('budget_assets', $this->mapper->getTableName());
	}

	// ===== find =====

	public function testFindReturnsAsset(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				$this->makeAssetRow(),
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$asset = $this->mapper->find(1, 'user1');

		$this->assertInstanceOf(Asset::class, $asset);
		$this->assertEquals('House', $asset->getName());
		$this->assertEquals('real_estate', $asset->getType());
	}

	public function testFindThrowsWhenNotFound(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$this->expectException(DoesNotExistException::class);
		$this->mapper->find(999, 'user1');
	}

	// ===== findAll =====

	public function testFindAllReturnsAssets(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				$this->makeAssetRow(['id' => 1, 'name' => 'House']),
				$this->makeAssetRow(['id' => 2, 'name' => 'Car', 'type' => 'vehicle']),
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$assets = $this->mapper->findAll('user1');

		$this->assertCount(2, $assets);
		$this->assertEquals('House', $assets[0]->getName());
		$this->assertEquals('Car', $assets[1]->getName());
	}

	public function testFindAllReturnsEmptyForNoAssets(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$assets = $this->mapper->findAll('user1');

		$this->assertEmpty($assets);
	}

	// ===== findByType =====

	public function testFindByTypeReturnsFilteredAssets(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				$this->makeAssetRow(['id' => 1, 'name' => 'House', 'type' => 'real_estate']),
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$assets = $this->mapper->findByType('user1', 'real_estate');

		$this->assertCount(1, $assets);
		$this->assertEquals('real_estate', $assets[0]->getType());
	}

	public function testFindByTypeReturnsEmptyForNoMatches(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$assets = $this->mapper->findByType('user1', 'jewelry');

		$this->assertEmpty($assets);
	}

	// ===== getTotalValue =====

	public function testGetTotalValueReturnsSumForUser(): void {
		$sumFunc = $this->createMock(IQueryFunction::class);
		$this->func->method('sum')->willReturn($sumFunc);
		$this->result->method('fetchOne')->willReturn('500000.00');
		$this->result->method('closeCursor');
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('executeQuery')->willReturn($this->result);

		$total = $this->mapper->getTotalValue('user1');

		$this->assertEquals(500000.00, $total);
	}

	public function testGetTotalValueReturnsZeroForNoAssets(): void {
		$sumFunc = $this->createMock(IQueryFunction::class);
		$this->func->method('sum')->willReturn($sumFunc);
		$this->result->method('fetchOne')->willReturn(null);
		$this->result->method('closeCursor');
		$this->qb->method('select')->willReturnSelf();
		$this->qb->method('executeQuery')->willReturn($this->result);

		$total = $this->mapper->getTotalValue('user1');

		$this->assertEquals(0.0, $total);
	}

	// ===== deleteAll =====

	public function testDeleteAllReturnsAffectedRows(): void {
		$this->qb->method('executeStatement')->willReturn(5);

		$count = $this->mapper->deleteAll('user1');

		$this->assertEquals(5, $count);
	}

	public function testDeleteAllReturnsZeroForNoAssets(): void {
		$this->qb->method('executeStatement')->willReturn(0);

		$count = $this->mapper->deleteAll('user1');

		$this->assertEquals(0, $count);
	}
}
