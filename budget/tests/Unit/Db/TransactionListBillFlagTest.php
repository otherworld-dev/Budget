<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\QueryFilterBuilder;
use OCA\Budget\Db\TransactionMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The transactions list says when a row's bill is gone (#399).
 *
 * Payments of a bill deleted by an older version still carry its id, and
 * nothing in the list told such a row apart from one of a live bill. The row
 * menu offers Link to bill for the first and Unlink from bill for the
 * second, so the list joins the bill to find out. Fully mocked query
 * builder: this checks the join and the mapping, not the SQL.
 */
class TransactionListBillFlagTest extends TestCase {
	/** @var IQueryBuilder&\PHPUnit\Framework\MockObject\MockObject */
	private $qb;
	private TransactionMapper $mapper;
	/** @var list<string> tables left-joined, in call order */
	private array $leftJoins = [];
	/** @var list<string> columns added to the select */
	private array $selected = [];

	protected function setUp(): void {
		$db = $this->createMock(IDBConnection::class);
		$this->qb = $this->createMock(IQueryBuilder::class);
		$db->method('getQueryBuilder')->willReturn($this->qb);
		$this->qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$this->qb->method('func')->willReturn($func);
		$this->qb->method('createNamedParameter')->willReturn(':param');
		foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy', 'innerJoin',
			'setMaxResults', 'setFirstResult'] as $method) {
			$this->qb->method($method)->willReturnSelf();
		}
		$this->qb->method('leftJoin')->willReturnCallback(function ($from, $table) {
			$this->leftJoins[] = $table;
			return $this->qb;
		});
		$this->qb->method('addSelect')->willReturnCallback(function (...$columns) {
			array_push($this->selected, ...$columns);
			return $this->qb;
		});

		$this->mapper = new TransactionMapper($db, $this->createMock(QueryFilterBuilder::class));
	}

	private static function row(int $id, ?int $billId, ?int $liveBillId): array {
		return [
			'id' => $id, 'account_id' => 3, 'category_id' => null, 'date' => '2026-03-01',
			'description' => 'Alimente', 'vendor' => null, 'amount' => 1250.0, 'type' => 'debit',
			'reference' => null, 'notes' => null, 'import_id' => null, 'reconciled' => 0,
			'created_at' => '2026-03-01 08:00:00', 'updated_at' => '2026-03-01 08:00:00',
			'linked_transaction_id' => null, 'is_split' => 0, 'bill_id' => $billId, 'status' => 'cleared',
			'account_name' => 'Lohnkonto', 'account_currency' => 'CHF', 'category_name' => null,
			'live_bill_id' => $liveBillId,
		];
	}

	public function testAListRowSaysWhenItsBillIsGone(): void {
		$count = $this->createMock(IResult::class);
		$count->method('fetchOne')->willReturn('3');
		$rows = $this->createMock(IResult::class);
		$rows->method('fetchAll')->willReturn([
			self::row(410, 13, null),
			self::row(1300, 47, 47),
			self::row(1586, null, null),
		]);
		$this->qb->method('executeQuery')->willReturnOnConsecutiveCalls($count, $rows);

		$list = $this->mapper->findWithFilters('terje', [], 25, 0)['transactions'];

		$this->assertTrue($list[0]['billDeleted']);
		$this->assertSame(13, $list[0]['billId']);
		$this->assertFalse($list[1]['billDeleted']);
		$this->assertFalse($list[2]['billDeleted']);
		$this->assertContains('budget_bills', $this->leftJoins);
		$this->assertContains('lb.id as live_bill_id', $this->selected);
	}
}
