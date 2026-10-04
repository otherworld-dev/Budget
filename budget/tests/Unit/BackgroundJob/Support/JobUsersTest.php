<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob\Support;

use OCA\Budget\BackgroundJob\Support\JobUsers;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class JobUsersTest extends TestCase {
	/** @var array<int, array{string, mixed, int}> createNamedParameter calls */
	private array $params = [];
	/** @var string[] tables read */
	private array $tables = [];
	/** @var array<int, mixed> expressions passed to where()/andWhere() */
	private array $conditions = [];
	/** @var string[] uids Nextcloud no longer knows */
	private array $deleted = [];
	/** @var string[] uids whose user backend throws when asked */
	private array $unreachable = [];
	/** @var string[] every userExists() call, in order */
	private array $lookups = [];

	private function userManager(): IUserManager {
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(function (string $uid): bool {
			$this->lookups[] = $uid;
			if (in_array($uid, $this->unreachable, true)) {
				throw new \RuntimeException('LDAP server unavailable');
			}
			return !in_array($uid, $this->deleted, true);
		});
		return $users;
	}

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

		return new JobUsers($db, $this->userManager());
	}

	/**
	 * A user deleted before 3.0, or whose purge failed, still had rows in
	 * every table, so every job kept running for them: auto-pay booked into
	 * accounts other users had shared with them, notifications and digests
	 * went to nobody (R8-3).
	 */
	public function testUsersNextcloudNoLongerKnowsAreSkipped(): void {
		$this->deleted = ['frank'];
		$users = $this->usersReturning(['alice', 'frank', 'bob']);

		$this->assertSame(['alice', 'bob'], $users->from('budget_bills', ['is_active' => true]));
	}

	public function testDeletedUsersAreSkippedByEveryEnumeration(): void {
		$this->deleted = ['frank'];
		$users = $this->usersReturning(['frank', 'dora'], ['frank'], ['frank', 'erin']);

		$this->assertSame(['dora'], $users->accountOwners());
		$this->assertSame([], $users->withSettingEnabled('digest_enabled'));
		$this->assertSame(['erin'], $users->from('budget_accounts', ['interest_enabled' => true]));
	}

	/**
	 * A user backend that can't answer (an LDAP server that is down) must
	 * not make the job act for the user: skipped for this run, picked up on
	 * the next. Nothing is ever deleted from here.
	 */
	public function testAUserTheBackendCannotAnswerForIsSkippedThisRun(): void {
		$this->unreachable = ['ldapuser'];
		$users = $this->usersReturning(['alice', 'ldapuser']);

		$this->assertSame(['alice'], $users->accountOwners());
	}

	public function testEachUserIsLookedUpOnce(): void {
		$users = $this->usersReturning(['carol', 'alice', 'carol', 'alice']);

		$this->assertSame(['alice', 'carol'], $users->accountOwners());
		$this->assertSame(['alice', 'carol'], $this->lookups);
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
