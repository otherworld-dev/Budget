<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\DanglingLinks;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * References older versions left pointing at rows that no longer exist
 * (#399): payments still carrying a deleted bill's id, and transfer sides
 * still naming another side that a version before 2.41 deleted (the
 * reporter's July CSX withdrawal 911 names 912). Migration 125 lets go of
 * them.
 *
 * The database finds them, each reference joined to the row it names, so
 * that the upgrade reads only those rows and not every reference on the
 * server. These tests check what each query asks for, and that only the
 * rows it returns are changed; LegacyCleanupTest runs it against a real
 * database.
 */
class DanglingLinksTest extends TestCase {
	/** @var list<list<int>> the ids each read returns, in order */
	private array $found = [];

	/** @var list<array<string, mixed>> every read, as from/join/where */
	private array $reads = [];

	/** @var list<array<string, mixed>> every write, as table/set/where */
	private array $writes = [];

	/** @var list<mixed> every value bound as a parameter */
	private array $bound = [];

	/** @var array<int, string> composite expressions, by object id, as text */
	private array $composites = [];

	/** @var int[] the ids the last IN listed */
	private array $lastIn = [];

	private function danglingLinks(): DanglingLinks {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn () => $this->queryBuilder());
		return new DanglingLinks($db);
	}

	private function queryBuilder(): IQueryBuilder {
		$query = ['from' => null, 'join' => null, 'where' => [], 'update' => null, 'set' => []];
		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('expr')->willReturn($this->expressions());
		$qb->method('createNamedParameter')->willReturnCallback(function ($value) {
			$this->bound[] = $value;
			return ':p' . (count($this->bound) - 1);
		});
		$qb->method('select')->willReturnSelf();
		$qb->method('from')->willReturnCallback(function (string $table, ?string $alias = null) use (&$query, $qb) {
			$query['from'] = $table . ($alias !== null ? ' ' . $alias : '');
			return $qb;
		});
		$qb->method('leftJoin')->willReturnCallback(function (string $fromAlias, string $table, string $alias, $condition) use (&$query, $qb) {
			$query['join'] = "{$table} {$alias} ON {$condition}";
			return $qb;
		});
		foreach (['where', 'andWhere'] as $method) {
			$qb->method($method)->willReturnCallback(function (...$conditions) use (&$query, $qb) {
				foreach ($conditions as $condition) {
					$query['where'][] = $this->text($condition);
				}
				return $qb;
			});
		}
		$qb->method('update')->willReturnCallback(function (string $table) use (&$query, $qb) {
			$query['update'] = $table;
			return $qb;
		});
		$qb->method('set')->willReturnCallback(function (string $column, $value) use (&$query, $qb) {
			$query['set'][$column] = $this->valueOf($value);
			return $qb;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use (&$query) {
			$this->reads[] = ['from' => $query['from'], 'join' => $query['join'], 'where' => $query['where']];
			$rows = array_map(fn (int $id) => ['id' => $id], array_shift($this->found) ?? []);
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(function () use (&$rows) {
				return array_shift($rows) ?? false;
			});
			return $result;
		});
		$qb->method('executeStatement')->willReturnCallback(function () use (&$query) {
			$this->writes[] = ['table' => $query['update'], 'set' => $query['set'], 'where' => $query['where']];
			return count($this->lastIn);
		});
		return $qb;
	}

	/** An expression builder that writes each expression out as text */
	private function expressions(): IExpressionBuilder {
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(fn ($x, $y) => $this->text($x) . ' = ' . $this->text($y));
		$expr->method('neq')->willReturnCallback(fn ($x, $y) => $this->text($x) . ' <> ' . $this->text($y));
		$expr->method('isNull')->willReturnCallback(fn ($x) => $x . ' IS NULL');
		$expr->method('isNotNull')->willReturnCallback(fn ($x) => $x . ' IS NOT NULL');
		$expr->method('in')->willReturnCallback(function ($x, $y) {
			$this->lastIn = (array)$this->valueOf($y);
			return $x . ' IN ' . $this->text($y);
		});
		$expr->method('orX')->willReturnCallback(function (...$parts) {
			$composite = $this->createMock(ICompositeExpression::class);
			$this->composites[spl_object_id($composite)] = '(' . implode(' OR ', array_map(fn ($p) => $this->text($p), $parts)) . ')';
			return $composite;
		});
		return $expr;
	}

	/** A bound parameter's value, or the column or expression as given */
	private function valueOf(mixed $part): mixed {
		if (is_string($part) && preg_match('/^:p(\d+)$/', $part, $m)) {
			return $this->bound[(int)$m[1]];
		}
		return $part;
	}

	private function text(mixed $part): string {
		if (is_object($part)) {
			return $this->composites[spl_object_id($part)] ?? '?';
		}
		$value = $this->valueOf($part);
		return $value === $part ? (string)$part : (string)json_encode($value);
	}

	public function testOnlyReferencesWhoseRowIsGoneAreReadAndLetGo(): void {
		// Christian Uhrenschmuck24's bill (65) was deleted; 911's deposit too
		$this->found = [[1012, 1102], [911]];

		$cleared = $this->danglingLinks()->clear();

		$this->assertSame(3, $cleared);
		$this->assertSame([
			[
				'from' => 'budget_transactions t',
				'join' => 'budget_bills r ON r.id = t.bill_id',
				// A pending row keeps a deleted bill's id: without it the
				// scheduled job would book it on its date
				'where' => ['t.bill_id IS NOT NULL', 'r.id IS NULL', '(t.status IS NULL OR t.status <> "scheduled")'],
			],
			[
				'from' => 'budget_transactions t',
				'join' => 'budget_transactions r ON r.id = t.linked_transaction_id',
				'where' => ['t.linked_transaction_id IS NOT NULL', 'r.id IS NULL'],
			],
		], $this->reads);
		$this->assertSame([
			['table' => 'budget_transactions', 'set' => ['bill_id' => null], 'where' => ['id IN [1012,1102]']],
			['table' => 'budget_transactions', 'set' => ['linked_transaction_id' => null], 'where' => ['id IN [911]']],
		], $this->writes);
	}

	public function testNothingLeftDanglingChangesNothing(): void {
		$this->found = [[], []];

		$this->assertSame(0, $this->danglingLinks()->clear());
		$this->assertCount(2, $this->reads);
		$this->assertSame([], $this->writes);
	}

	public function testManyAreLetGoInChunks(): void {
		$this->found = [range(1, 1201), []];

		$this->assertSame(1201, $this->danglingLinks()->clear());
		$this->assertSame([500, 500, 201], array_map(
			fn (array $write) => count(json_decode(substr($write['where'][0], strlen('id IN ')), true)),
			$this->writes
		));
	}
}
