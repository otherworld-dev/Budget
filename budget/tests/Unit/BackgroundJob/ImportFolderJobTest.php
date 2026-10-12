<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\BackgroundJob;

use OCA\Budget\BackgroundJob\ImportFolderJob;
use OCA\Budget\Service\ImportFolderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

class ImportFolderJobTest extends TestCase {
	private ImportFolderJob $job;
	private IDBConnection $db;
	/** @var ImportFolderService&\PHPUnit\Framework\MockObject\MockObject */
	private $service;
	/** @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject */
	private $logger;
	private string $table = '';

	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->service = $this->createMock(ImportFolderService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnMap([
			// JobUsers keeps only users Nextcloud still knows
			[IUserManager::class, $this->createConfiguredMock(IUserManager::class, ['userExists' => true])],
			[IDBConnection::class, $this->db],
			[ImportFolderService::class, $this->service],
			[LoggerInterface::class, $this->logger],
		]);
		\OC::$server = $container;

		$this->job = new ImportFolderJob($this->createMock(ITimeFactory::class));
	}

	protected function tearDown(): void {
		\OC::$server = null;
	}

	private function givenUsersWithFolders(array $userIds): void {
		$rows = array_map(static fn ($id) => ['user_id' => $id], $userIds);
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->willReturn($rows);
		$cursor = 0;
		$result->method('fetch')->willReturnCallback(function () use (&$cursor, $rows) {
			return $rows[$cursor++] ?? false;
		});

		$qb = $this->createMock(IQueryBuilder::class);
		$qb->method('selectDistinct')->willReturnSelf();
		$qb->method('from')->willReturnCallback(function (string $table) use ($qb) {
			$this->table = $table;
			return $qb;
		});
		$qb->method('where')->willReturnSelf();
		$qb->method('andWhere')->willReturnSelf();
		$qb->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$qb->method('executeQuery')->willReturn($result);
		$this->db->method('getQueryBuilder')->willReturn($qb);
	}

	private function invokeRun(): void {
		(new \ReflectionMethod($this->job, 'run'))->invoke($this->job, null);
	}

	public function testChecksEveryUserWhoHasAnImportFolder(): void {
		$this->givenUsersWithFolders(['alice', 'bob']);
		$checked = [];
		$this->service->method('check')->willReturnCallback(function (string $userId) use (&$checked) {
			$checked[] = $userId;
			return ['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 0];
		});

		$this->invokeRun();

		$this->assertSame(['alice', 'bob'], $checked);
		$this->assertSame('budget_import_folders', $this->table);
	}

	public function testOneUsersFailureDoesNotStopTheNext(): void {
		$this->givenUsersWithFolders(['alice', 'bob']);
		$checked = [];
		$this->service->method('check')->willReturnCallback(function (string $userId) use (&$checked) {
			if ($userId === 'alice') {
				throw new \RuntimeException('storage not available');
			}
			$checked[] = $userId;
			return ['imported' => 1, 'failed' => 0, 'waiting' => 0, 'review' => 0];
		});
		$this->logger->expects($this->once())->method('warning');

		$this->invokeRun();

		$this->assertSame(['bob'], $checked);
	}

	public function testRunsEveryFifteenMinutes(): void {
		$interval = new \ReflectionProperty(\OCP\BackgroundJob\TimedJob::class, 'interval');
		$this->assertSame(900, $interval->getValue($this->job));
	}

	public function testFilesHeldForReviewAreLogged(): void {
		$this->givenUsersWithFolders(['alice']);
		$this->service->method('check')->willReturn(['imported' => 0, 'failed' => 0, 'waiting' => 0, 'review' => 2]);
		$this->logger->expects($this->once())->method('info')->with($this->stringContains('2 held for review'));

		$this->invokeRun();
	}
}
