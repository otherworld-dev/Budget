<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\DigestJob;
use OCA\Budget\BackgroundJob\Queued\UserDigestJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * DigestJob only fans out: one UserDigestJob per user. The per-user work is
 * covered by UserDigestJobTest.
 */
class DigestJobTest extends TestCase {
	private DigestJob $job;
	private IDBConnection $db;
	private IJobList $jobList;
	/** @var array<int, array{string, array}> jobs added, in order */
	private array $added = [];
	/** @var array<string, true> user ids whose job is still queued */
	private array $pending = [];

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->jobList = $this->createMock(IJobList::class);
		$this->jobList->method('has')
			->willReturnCallback(fn (string $class, $argument) => isset($this->pending[$argument['userId']]));
		$this->jobList->method('add')
			->willReturnCallback(function (string $class, $argument) {
				$this->added[] = [$class, $argument];
			});

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			[IDBConnection::class, $this->db],
			[IJobList::class, $this->jobList],
			[LoggerInterface::class, $this->createMock(LoggerInterface::class)],
		]);
		\OC::$server = $container;

		$this->job = new DigestJob($this->createMock(ITimeFactory::class));
	}

	protected function tearDown(): void {
		\OC::$server = null;
	}

	/**
	 * The job enumerates two lists in order: users opted in to the digest,
	 * then everyone who owns an account.
	 */
	private function mockUserQueries(array $digestUsers, array $accountUsers): void {
		$resultSets = [
			array_map(static fn ($id) => ['user_id' => $id], $digestUsers),
			array_map(static fn ($id) => ['user_id' => $id], $accountUsers),
		];

		$this->db->method('getQueryBuilder')->willReturnCallback(function () use (&$resultSets) {
			$rows = array_shift($resultSets) ?? [];
			$result = $this->createMock(IResult::class);
			$result->method('fetch')->willReturnCallback(function () use (&$rows) {
				return array_shift($rows) ?? false;
			});
			$result->method('closeCursor');

			$qb = $this->createMock(IQueryBuilder::class);
			foreach (['selectDistinct', 'from', 'where', 'andWhere'] as $fluent) {
				$qb->method($fluent)->willReturnSelf();
			}
			$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
			$qb->method('createNamedParameter')->willReturn(':p');
			$qb->method('executeQuery')->willReturn($result);

			return $qb;
		});
	}

	private function invokeRun(): void {
		(new \ReflectionMethod($this->job, 'run'))->invoke($this->job, null);
	}

	public function testQueuesOneJobPerUserOfEitherList(): void {
		$this->mockUserQueries(['dora', 'alice'], ['bob', 'alice']);

		$this->invokeRun();

		$this->assertSame([
			[UserDigestJob::class, ['userId' => 'alice']],
			[UserDigestJob::class, ['userId' => 'bob']],
			[UserDigestJob::class, ['userId' => 'dora']],
		], $this->added);
	}

	/**
	 * A job still waiting from an earlier run keeps its place: re-adding it
	 * would move it to the back of the queue, and a backlog that never
	 * drained would push the same users back every day.
	 */
	public function testLeavesAUsersStillQueuedJobWhereItIs(): void {
		$this->mockUserQueries([], ['alice', 'bob']);
		$this->pending = ['alice' => true];

		$this->invokeRun();

		$this->assertSame([[UserDigestJob::class, ['userId' => 'bob']]], $this->added);
	}

	public function testDoesNothingPerUserItself(): void {
		$this->mockUserQueries(['alice'], ['alice']);

		$this->invokeRun();

		// Only the enumeration queries ran; the per-user work is queued
		$this->assertCount(1, $this->added);
	}
}
