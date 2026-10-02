<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionReportQueries;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\UserClock;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Reports count a scheduled row once its date has arrived (#304) — on the
 * user's calendar, not the server's (#399 review, F90). On UTC a row dated
 * a Los Angeles user's tomorrow was counted every evening, and one dated
 * an Auckland user's today was left out until UTC midnight.
 *
 * The query builder is mocked: these check which date each query is bound
 * to, not the SQL around it.
 */
class ReportScopeTodayTest extends TestCase {
	private const TODAY = '2030-06-15';

	/** @var mixed[] every value bound with createNamedParameter() */
	private array $bound = [];

	private function db(): IDBConnection {
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('createNamedParameter')->willReturnCallback(function ($value) {
			$this->bound[] = $value;
			return ':p' . count($this->bound);
		});
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$func = $this->createMock(IFunctionBuilder::class);
		$sql = $this->createMock(IQueryFunction::class);
		foreach (['sum', 'count', 'max', 'min', 'lower'] as $method) {
			$func->method($method)->willReturn($sql);
		}
		$qb->method('func')->willReturn($func);
		$qb->method('createFunction')->willReturn($sql);
		foreach (['select', 'addSelect', 'selectAlias', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy',
			'innerJoin', 'leftJoin', 'groupBy', 'addGroupBy', 'setMaxResults', 'setFirstResult'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn([]);
		$result->method('fetch')->willReturn(false);
		$result->method('fetchOne')->willReturn(false);
		$qb->method('executeQuery')->willReturn($result);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	private function clock(): UserClock {
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->with('alice')->willReturn(self::TODAY);
		return $clock;
	}

	private function assertJudgedOnTheUsersDate(): void {
		$this->assertContains(self::TODAY, $this->bound);
		$this->assertNotContains(date('Y-m-d'), $this->bound);
	}

	public function testReportGroupingsUseTheUsersDate(): void {
		(new TransactionReportQueries($this->db(), $this->clock()))
			->getSpendingByVendor('alice', null, '2030-06-01', '2030-06-30');

		$this->assertJudgedOnTheUsersDate();
	}

	public function testCategoryAndTrendTotalsUseTheUsersDate(): void {
		$mapper = new TransactionMapper($this->db(), null, $this->clock());
		$mapper->getCategorySpendingByBucketBatch('alice', '2030-06-01', '2030-06-30');
		$mapper->getSpendingSummary('alice', '2030-06-01', '2030-06-30');

		$this->assertJudgedOnTheUsersDate();
	}

	public function testSplitTotalsUseTheUsersDate(): void {
		(new TransactionSplitMapper($this->db(), $this->clock()))
			->getCategoryTotalsByBucket('alice', '2030-06-01', '2030-06-30');

		$this->assertJudgedOnTheUsersDate();
	}

	public function testAccountTilesUseTheDateTheyAreGiven(): void {
		(new TransactionMapper($this->db()))->getAccountMetrics(1, '2030-06-01', '2030-06-30', self::TODAY);

		$this->assertJudgedOnTheUsersDate();
	}
}
