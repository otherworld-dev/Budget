<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob\Support;

use OCA\Budget\BackgroundJob\Support\JobUsers;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class JobUsersTest extends TestCase {
	/** @var array<int, array{string, mixed, int}> createNamedParameter calls */
	private array $params = [];
	/** @var string[] tables read */
	private array $tables = [];
	/** @var array<int, mixed> expressions passed to where()/andWhere() */
	private array $conditions = [];

	private function usersReturning(array ...$resultSets): JobUsers {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(function () use (&$resultSets) {
			$rows = array_map(static fn ($id) => ['user_id' => $id], array_shift($resultSets) ?? []);
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(function () use (&$rows) {
				return array_shift($rows) ?? false;
			});

			$expr = $this->createMock(IExpressionBuilder::class);
			$expr->method('eq')->willReturnCallback(fn ($col, $param) => "{$col} = {$param}");
			$expr->method('in')->willReturnCallback(fn ($col, $param) => "{$col} IN {$param}");

			$qb = $this->createMock(IQueryBuilder::class);
			$qb->method('selectDistinct')->willReturnSelf();
			$qb->method('from')->willReturnCallback(function (string $table) use ($qb) {
				$this->tables[] = $table;
				return $qb;
			});
			$record = function ($condition) use ($qb) {
				$this->conditions[] = $condition;
				return $qb;
			};
			$qb->method('where')->willReturnCallback($record);
			$qb->method('andWhere')->willReturnCallback($record);
			$qb->method('expr')->willReturn($expr);
			$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = IQueryBuilder::PARAM_STR) {
				$this->params[] = [$value, $type];
				return ':p' . count($this->params);
			});
			$qb->method('executeQuery')->willReturn($result);
			return $qb;
		});

		return new JobUsers($db);
	}

	public function testFromReturnsDistinctSortedUserIds(): void {
		$users = $this->usersReturning(['carol', 'alice', 'bob', 'alice']);

		$this->assertSame(['alice', 'bob', 'carol'], $users->from('budget_accounts'));
		$this->assertSame(['budget_accounts'], $this->tables);
		$this->assertSame([], $this->conditions);
	}

	public function testFromNarrowsToRequiredColumnValuesWithMatchingTypes(): void {
		$users = $this->usersReturning(['alice']);

		$users->from('budget_pen_recur', ['is_active' => true, 'auto_post_enabled' => true]);

		$this->assertSame(['is_active = :p1', 'auto_post_enabled = :p2'], $this->conditions);
		$this->assertSame([[true, IQueryBuilder::PARAM_BOOL], [true, IQueryBuilder::PARAM_BOOL]], $this->params);
	}

	public function testAccountOwnersReadsTheAccountsTable(): void {
		$users = $this->usersReturning(['bob']);

		$this->assertSame(['bob'], $users->accountOwners());
		$this->assertSame(['budget_accounts'], $this->tables);
	}

	public function testWithSettingEnabledMatchesAnyOfTheKeysSetToTrue(): void {
		$users = $this->usersReturning(['dora']);

		$this->assertSame(['dora'], $users->withSettingEnabled('report_files_enabled', 'report_email_enabled'));
		$this->assertSame(['budget_settings'], $this->tables);
		$this->assertSame(['key IN :p1', 'value = :p2'], $this->conditions);
		$this->assertSame([
			[['report_files_enabled', 'report_email_enabled'], IQueryBuilder::PARAM_STR_ARRAY],
			['true', IQueryBuilder::PARAM_STR],
		], $this->params);
	}

	public function testUnionDeduplicatesAndSorts(): void {
		$this->assertSame(['a', 'b', 'c'], JobUsers::union(['c', 'a'], ['b', 'a'], []));
		$this->assertSame([], JobUsers::union());
	}
}
