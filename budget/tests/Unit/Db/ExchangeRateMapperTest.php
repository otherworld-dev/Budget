<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\ExchangeRate;
use OCA\Budget\Db\ExchangeRateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ExchangeRateMapperTest extends TestCase {
	private ExchangeRateMapper $mapper;
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
		$this->qb->method('createFunction')->willReturn(':func');

		foreach (['select', 'from', 'where', 'andWhere', 'orderBy',
				   'insert', 'delete', 'update', 'set', 'setValue',
				   'setMaxResults'] as $method) {
			$this->qb->method($method)->willReturnSelf();
		}

		$this->mapper = new ExchangeRateMapper($this->db);
	}

	private function makeRow(array $overrides = []): array {
		return array_merge([
			'id' => 1,
			'currency' => 'USD',
			'rate_per_eur' => '1.0850',
			'date' => '2026-03-01',
			'source' => 'ecb',
			'created_at' => '2026-03-01 12:00:00',
		], $overrides);
	}

	// ===== getTableName =====

	public function testTableNameIsCorrect(): void {
		$this->assertEquals('budget_exchange_rates', $this->mapper->getTableName());
	}

	// ===== findByDate =====

	public function testFindByDateReturnsRate(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls($this->makeRow(), false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rate = $this->mapper->findByDate('USD', '2026-03-01');

		$this->assertInstanceOf(ExchangeRate::class, $rate);
		$this->assertEquals('USD', $rate->getCurrency());
		$this->assertEquals('1.0850', $rate->getRatePerEur());
	}

	public function testFindByDateReturnsNullWhenNotFound(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rate = $this->mapper->findByDate('USD', '2026-03-01');

		$this->assertNull($rate);
	}

	// ===== findAllByDate =====

	public function testFindAllByDateReturnsRates(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				$this->makeRow(['id' => 1, 'currency' => 'USD']),
				$this->makeRow(['id' => 2, 'currency' => 'GBP', 'rate_per_eur' => '0.8600']),
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rates = $this->mapper->findAllByDate('2026-03-01');

		$this->assertCount(2, $rates);
		$this->assertEquals('USD', $rates[0]->getCurrency());
		$this->assertEquals('GBP', $rates[1]->getCurrency());
	}

	public function testFindAllByDateReturnsEmptyForNoRates(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rates = $this->mapper->findAllByDate('2026-03-01');

		$this->assertEmpty($rates);
	}

	// ===== findLatest =====

	public function testFindLatestReturnsRate(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls($this->makeRow(), false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rate = $this->mapper->findLatest('USD');

		$this->assertInstanceOf(ExchangeRate::class, $rate);
		$this->assertEquals('USD', $rate->getCurrency());
	}

	public function testFindLatestReturnsNullWhenNotFound(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rate = $this->mapper->findLatest('USD');

		$this->assertNull($rate);
	}

	// ===== findClosest =====

	public function testFindClosestReturnsRateOnOrBeforeDate(): void {
		// First query (on or before) finds a result
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls($this->makeRow(['date' => '2026-02-28']), false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rate = $this->mapper->findClosest('USD', '2026-03-01');

		$this->assertInstanceOf(ExchangeRate::class, $rate);
		$this->assertEquals('2026-02-28', $rate->getDate());
	}

	public function testFindClosestReturnsNullWhenNoRateExists(): void {
		// Both queries return nothing
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rate = $this->mapper->findClosest('USD', '2026-03-01');

		$this->assertNull($rate);
	}

	// ===== findAllLatest =====

	public function testFindAllLatestReturnsEmptyWhenNoRates(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('fetchOne')->willReturn(null);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rates = $this->mapper->findAllLatest();

		$this->assertEmpty($rates);
	}

	// ===== deleteOlderThan =====

	public function testDeleteOlderThanReturnsDeletedCount(): void {
		$this->qb->method('executeStatement')->willReturn(10);

		$count = $this->mapper->deleteOlderThan(30);

		$this->assertEquals(10, $count);
	}

	public function testDeleteOlderThanReturnsZeroWhenNoneOld(): void {
		$this->qb->method('executeStatement')->willReturn(0);

		$count = $this->mapper->deleteOlderThan(30);

		$this->assertEquals(0, $count);
	}
}
