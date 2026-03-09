<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\ManualExchangeRate;
use OCA\Budget\Db\ManualExchangeRateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ManualExchangeRateMapperTest extends TestCase {
	private ManualExchangeRateMapper $mapper;
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
				   'insert', 'delete', 'update', 'set', 'setValue'] as $method) {
			$this->qb->method($method)->willReturnSelf();
		}

		$this->mapper = new ManualExchangeRateMapper($this->db);
	}

	private function makeRow(array $overrides = []): array {
		return array_merge([
			'id' => 1,
			'user_id' => 'user1',
			'currency' => 'USD',
			'rate_per_eur' => '1.0850',
			'updated_at' => '2026-03-01 12:00:00',
		], $overrides);
	}

	// ===== getTableName =====

	public function testTableNameIsCorrect(): void {
		$this->assertEquals('budget_manual_rates', $this->mapper->getTableName());
	}

	// ===== findByUserAndCurrency =====

	public function testFindByUserAndCurrencyReturnsRate(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls($this->makeRow(), false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rate = $this->mapper->findByUserAndCurrency('user1', 'USD');

		$this->assertInstanceOf(ManualExchangeRate::class, $rate);
		$this->assertEquals('USD', $rate->getCurrency());
		$this->assertEquals('1.0850', $rate->getRatePerEur());
	}

	public function testFindByUserAndCurrencyReturnsNullWhenNotFound(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rate = $this->mapper->findByUserAndCurrency('user1', 'XYZ');

		$this->assertNull($rate);
	}

	// ===== findAllByUser =====

	public function testFindAllByUserReturnsRates(): void {
		$this->result->method('fetch')
			->willReturnOnConsecutiveCalls(
				$this->makeRow(['id' => 1, 'currency' => 'USD']),
				$this->makeRow(['id' => 2, 'currency' => 'GBP', 'rate_per_eur' => '0.8600']),
				false
			);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rates = $this->mapper->findAllByUser('user1');

		$this->assertCount(2, $rates);
		$this->assertEquals('USD', $rates[0]->getCurrency());
		$this->assertEquals('GBP', $rates[1]->getCurrency());
	}

	public function testFindAllByUserReturnsEmptyForNoRates(): void {
		$this->result->method('fetch')->willReturn(false);
		$this->result->method('closeCursor');
		$this->qb->method('executeQuery')->willReturn($this->result);

		$rates = $this->mapper->findAllByUser('user1');

		$this->assertEmpty($rates);
	}
}
