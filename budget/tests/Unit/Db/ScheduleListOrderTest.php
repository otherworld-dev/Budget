<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\BillMapper;
use OCA\Budget\Db\RecurringIncomeMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Finished bills and income sort last (#399).
 *
 * The Bills and Income lists come ordered by next date, and a paid one-time
 * bill or a received one-time income has none. SQLite and MySQL put NULL
 * first in ascending order and PostgreSQL last, so on most instances the
 * finished ones topped the list, above the overdue ones. The lists now sort
 * a missing next date last on every database, then by the date, then by
 * name.
 *
 * The query builder is mocked, as in the other mapper tests, to read the
 * sort keys each list asks for; the keys are then run on SQLite itself,
 * the database that put NULL first.
 */
class ScheduleListOrderTest extends TestCase {
	/** @var list<array{0: string, 1: string|null}> the sort keys, in order */
	private array $order = [];

	private function db(): IDBConnection {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->createMock(IExpressionBuilder::class);
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturn(':param');
		$qb->method('executeQuery')->willReturn($result);
		foreach (['select', 'from', 'where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnSelf();
		}
		$qb->method('getColumnName')->willReturnCallback(fn (string $column) => '"' . $column . '"');
		$qb->method('createFunction')->willReturnCallback(fn (string $call) => new class($call) implements IQueryFunction {
			public function __construct(
				private string $call,
			) {
			}
			public function __toString(): string {
				return $this->call;
			}
		});
		$record = function ($sort, $direction = null) use ($qb) {
			$this->order[] = [(string)$sort, $direction];
			return $qb;
		};
		$qb->method('orderBy')->willReturnCallback(function ($sort, $direction = null) use ($record) {
			$this->order = [];
			return $record($sort, $direction);
		});
		$qb->method('addOrderBy')->willReturnCallback($record);

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}

	/** @return string[] the names, in the order the keys put them */
	private function sortedOnSqlite(string $column): array {
		$pdo = new \PDO('sqlite::memory:');
		$pdo->exec("CREATE TABLE t (name TEXT, {$column} TEXT)");
		$rows = [
			['Completed refund', null],
			['Swisscom (overdue)', '2026-10-03'],
			['Abo', null],
			['Monatslohn', '2026-10-25'],
		];
		$insert = $pdo->prepare("INSERT INTO t (name, {$column}) VALUES (?, ?)");
		foreach ($rows as $row) {
			$insert->execute($row);
		}
		$keys = implode(', ', array_map(fn (array $key) => $key[0] . ' ' . ($key[1] ?? 'ASC'), $this->order));
		return $pdo->query("SELECT name FROM t ORDER BY {$keys}")->fetchAll(\PDO::FETCH_COLUMN);
	}

	public static function lists(): array {
		$bills = fn (IDBConnection $db) => new BillMapper($db);
		$income = fn (IDBConnection $db) => new RecurringIncomeMapper($db);
		return [
			'Bills: every bill' => [$bills, fn (BillMapper $m) => $m->findAll('terje'), 'next_due_date'],
			'Bills: the page\'s list' => [$bills, fn (BillMapper $m) => $m->findByType('terje', false, null, true), 'next_due_date'],
			'Bills: transfers' => [$bills, fn (BillMapper $m) => $m->findByType('terje', true), 'next_due_date'],
			'Bills: active' => [$bills, fn (BillMapper $m) => $m->findActive('terje'), 'next_due_date'],
			'Income: every income' => [$income, fn (RecurringIncomeMapper $m) => $m->findAll('terje'), 'next_expected_date'],
			'Income: active' => [$income, fn (RecurringIncomeMapper $m) => $m->findActive('terje'), 'next_expected_date'],
		];
	}

	/**
	 * @dataProvider lists
	 */
	public function testAMissingNextDateSortsLast(\Closure $mapper, \Closure $find, string $column): void {
		$find($mapper($this->db()));

		$this->assertSame(['Swisscom (overdue)', 'Monatslohn', 'Abo', 'Completed refund'], $this->sortedOnSqlite($column));
	}

	/**
	 * @dataProvider lists
	 */
	public function testThenByDateThenByName(\Closure $mapper, \Closure $find, string $column): void {
		$find($mapper($this->db()));

		$this->assertSame([$column, 'ASC'], [trim($this->order[1][0], '"'), $this->order[1][1]]);
		$this->assertSame(['name', 'ASC'], [trim($this->order[2][0], '"'), $this->order[2][1]]);
	}
}
