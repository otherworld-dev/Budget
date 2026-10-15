<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Db\Project;
use OCA\Budget\Db\ProjectAllocation;
use OCA\Budget\Db\ProjectAllocationMapper;
use OCA\Budget\Db\ProjectMapper;
use OCA\Budget\Db\ShareItemMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AutoShareService;
use OCA\Budget\Service\ProjectService;
use OCA\Budget\Service\UserClock;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProjectServiceTest extends TestCase {
	private ProjectService $service;
	private ProjectMapper $projectMapper;
	private ProjectAllocationMapper $allocationMapper;
	private CategoryMapper $categoryMapper;
	private TransactionMapper $transactionMapper;
	private IDBConnection $db;
	private AutoShareService $autoShareService;
	private ShareItemMapper $shareItemMapper;

	/** @var Category[] */
	private array $categories = [];

	/** @var ProjectAllocation[] what findByProject() returns; seed per test (a re-stub cannot beat setUp's) */
	private array $savedAllocations = [];

	protected function setUp(): void {
		$this->projectMapper = $this->createMock(ProjectMapper::class);
		$this->allocationMapper = $this->createMock(ProjectAllocationMapper::class);
		$this->categoryMapper = $this->createMock(CategoryMapper::class);
		$this->transactionMapper = $this->createMock(TransactionMapper::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->autoShareService = $this->createMock(AutoShareService::class);
		$this->shareItemMapper = $this->createMock(ShareItemMapper::class);
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn('2026-09-19');

		// Renovation > Kitchen > Appliances, Renovation > Bathroom, plus an
		// income category and an unrelated expense one
		$this->categories = [
			$this->cat(1, 'Renovation', null),
			$this->cat(2, 'Kitchen', 1),
			$this->cat(3, 'Appliances', 2),
			$this->cat(4, 'Bathroom', 1),
			$this->cat(6, 'Salary', null, 'income'),
			$this->cat(7, 'Groceries', null),
		];
		$this->categoryMapper->method('findAll')->willReturnCallback(fn () => $this->categories);
		$this->allocationMapper->method('findByProject')->willReturnCallback(fn () => $this->savedAllocations);
		$this->projectMapper->method('insert')->willReturnCallback(function (Project $project) {
			$project->setId(10);
			return $project;
		});

		$this->service = new ProjectService(
			$this->projectMapper,
			$this->allocationMapper,
			$this->categoryMapper,
			$this->transactionMapper,
			$clock,
			$this->db,
			$this->autoShareService,
			$this->shareItemMapper
		);
	}

	private function cat(int $id, string $name, ?int $parentId, string $type = 'expense'): Category {
		$category = new Category();
		$category->setId($id);
		$category->setUserId('user1');
		$category->setName($name);
		$category->setType($type);
		$category->setParentId($parentId);
		return $category;
	}

	private function makeProject(string $start = '2026-03-01', ?string $end = null): Project {
		$project = new Project();
		$project->setId(10);
		$project->setUserId('user1');
		$project->setName('House renovation');
		$project->setCategoryId(1);
		$project->setTotalAmount(900.0);
		$project->setStartDate($start);
		$project->setEndDate($end);
		return $project;
	}

	private function allocation(int $categoryId, float $amount): ProjectAllocation {
		$allocation = new ProjectAllocation();
		$allocation->setUserId('user1');
		$allocation->setProjectId(10);
		$allocation->setCategoryId($categoryId);
		$allocation->setAmount($amount);
		return $allocation;
	}

	private function input(array $overrides = []): array {
		return $overrides + [
			'name' => 'House renovation',
			'categoryId' => 1,
			'totalAmount' => 900,
			'startDate' => '2026-03-01',
			'endDate' => null,
			'allocations' => [],
			'excludeFromBudget' => true,
		];
	}

	public function testCreateSavesTheProjectItsAmountsAndExcludesTheCategory(): void {
		$inserted = [];
		$this->allocationMapper->expects($this->once())->method('deleteByProject')->with(10);
		$this->allocationMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (ProjectAllocation $allocation) use (&$inserted) {
				$inserted[] = $allocation;
				return $allocation;
			});
		$this->categoryMapper->expects($this->once())->method('update')
			->willReturnCallback(function (Category $category) {
				$this->assertSame(1, $category->getId());
				$this->assertTrue($category->getExcludedFromBudget());
				return $category;
			});
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('commit');
		$this->autoShareService->expects($this->once())->method('autoShareNewEntity')->with('user1', 'project', 10);

		$result = $this->service->create('user1', $this->input(['allocations' => [['categoryId' => 2, 'amount' => '400']]]));

		$this->assertSame(10, $result['id']);
		$this->assertSame('Renovation', $result['categoryName']);
		$this->assertSame('active', $result['status']);
		$this->assertSame('user1', $inserted[0]->getUserId());
		$this->assertSame(2, $inserted[0]->getCategoryId());
		$this->assertSame(400.0, $inserted[0]->getAmount());
	}

	public function testCreateCanLeaveTheCategoryInMonthlyBudgets(): void {
		$this->categoryMapper->expects($this->never())->method('update');
		$this->service->create('user1', $this->input(['excludeFromBudget' => false]));
	}

	public function testCreateLeavesAnAlreadyExcludedCategoryAlone(): void {
		$this->categories[0]->setExcludedFromBudget(true);
		$this->categoryMapper->expects($this->never())->method('update');
		$this->service->create('user1', $this->input());
	}

	#[DataProvider('refusals')]
	public function testCreateRefuses(array $overrides, int $code): void {
		$this->projectMapper->expects($this->never())->method('insert');
		$this->db->expects($this->never())->method('beginTransaction');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionCode($code);
		$this->service->create('user1', $this->input($overrides));
	}

	public static function refusals(): array {
		return [
			'no name' => [['name' => '  '], ProjectService::ERR_NAME],
			'zero total' => [['totalAmount' => 0], ProjectService::ERR_TOTAL],
			'total not a number' => [['totalAmount' => 'abc'], ProjectService::ERR_TOTAL],
			'income category' => [['categoryId' => 6], ProjectService::ERR_CATEGORY],
			'a category that is not theirs' => [['categoryId' => 99], ProjectService::ERR_CATEGORY],
			'no start date' => [['startDate' => ''], ProjectService::ERR_START_DATE],
			'impossible start date' => [['startDate' => '2026-02-30'], ProjectService::ERR_START_DATE],
			'end before start' => [['endDate' => '2026-02-01'], ProjectService::ERR_END_DATE],
			'zero amount' => [['allocations' => [['categoryId' => 2, 'amount' => 0]]], ProjectService::ERR_ALLOC_AMOUNT],
			'amount on the project category' => [['allocations' => [['categoryId' => 1, 'amount' => 10]]], ProjectService::ERR_ALLOC_OUTSIDE],
			'amount outside the branch' => [['allocations' => [['categoryId' => 7, 'amount' => 10]]], ProjectService::ERR_ALLOC_OUTSIDE],
			'same subcategory twice' => [['allocations' => [['categoryId' => 2, 'amount' => 10], ['categoryId' => 2, 'amount' => 20]]], ProjectService::ERR_ALLOC_DUPLICATE],
			'a subcategory and its own subcategory' => [['allocations' => [['categoryId' => 2, 'amount' => 10], ['categoryId' => 3, 'amount' => 20]]], ProjectService::ERR_ALLOC_OVERLAP],
			'more than the total' => [['allocations' => [['categoryId' => 2, 'amount' => 600], ['categoryId' => 4, 'amount' => 300.01]]], ProjectService::ERR_ALLOC_OVER_TOTAL],
		];
	}

	public function testCreateRollsBackWhenAnAmountFailsToSave(): void {
		$this->allocationMapper->method('insert')->willThrowException(new \RuntimeException('db down'));
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->autoShareService->expects($this->never())->method('autoShareNewEntity');

		$this->expectException(\RuntimeException::class);
		$this->service->create('user1', $this->input(['allocations' => [['categoryId' => 2, 'amount' => 400]]]));
	}

	public function testFiguresCoverTheBranchFromTheStartToToday(): void {
		$this->projectMapper->method('find')->willReturn($this->makeProject());
		$this->transactionMapper->expects($this->once())->method('getCategorySpendingBatch')
			->willReturnCallback(function (array $ids, string $from, string $to, string $type) {
				$this->assertEqualsCanonicalizing([1, 2, 3, 4], $ids);
				$this->assertSame('2026-03-01', $from);
				$this->assertSame('2026-09-19', $to);
				$this->assertSame('debit', $type);
				return [2 => 100.0, 4 => 300.0];
			});

		$result = $this->service->get(10, 'user1');

		$this->assertSame(400.0, $result['spent']);
		$this->assertSame(500.0, $result['remaining']);
	}

	public function testAnUpcomingProjectQueriesNoSpending(): void {
		$this->projectMapper->method('find')->willReturn($this->makeProject('2026-12-01'));
		$this->transactionMapper->expects($this->never())->method('getCategorySpendingBatch');

		$result = $this->service->get(10, 'user1');

		$this->assertSame('upcoming', $result['status']);
		$this->assertSame(0.0, $result['spent']);
	}

	public function testUpdateReplacesTheAmountsAndNeverTouchesTheCategoryFlag(): void {
		$this->projectMapper->method('find')->willReturn($this->makeProject());
		$this->projectMapper->expects($this->once())->method('update')->willReturnArgument(0);
		$this->allocationMapper->expects($this->once())->method('deleteByProject')->with(10);
		$this->allocationMapper->expects($this->once())->method('insert')->willReturnArgument(0);
		$this->db->expects($this->once())->method('commit');
		$this->categoryMapper->expects($this->never())->method('update');

		$this->service->update(10, 'user1', $this->input([
			'totalAmount' => 1200,
			'allocations' => [['categoryId' => 4, 'amount' => 500]],
		]), true);
	}

	public function testAReadWriteRecipientCannotMoveTheProjectToAnotherCategory(): void {
		$project = $this->makeProject();
		$this->projectMapper->method('find')->with(10, 'owner')->willReturn($project);
		$this->projectMapper->expects($this->once())->method('update')
			->willReturnCallback(function (Project $saved) {
				$this->assertSame(1, $saved->getCategoryId());
				return $saved;
			});

		$this->service->update(10, 'owner', $this->input(['categoryId' => 7, 'name' => 'Renamed']), false);

		$this->assertSame('Renamed', $project->getName());
	}

	public function testUpdateKeepsWhateverWasNotSent(): void {
		$this->savedAllocations = [$this->allocation(2, 400.0)];
		$project = $this->makeProject('2026-03-01', '2026-12-31');
		$this->projectMapper->method('find')->willReturn($project);
		$this->projectMapper->method('update')->willReturnArgument(0);
		$this->allocationMapper->expects($this->once())->method('insert')
			->willReturnCallback(function (ProjectAllocation $allocation) {
				$this->assertSame(2, $allocation->getCategoryId());
				return $allocation;
			});

		$this->service->update(10, 'user1', ['name' => 'Renamed'], true);

		$this->assertSame('2026-12-31', $project->getEndDate());
		$this->assertSame(900.0, $project->getTotalAmount());
	}

	public function testUpdateClearsTheEndDateWhenSentAsNull(): void {
		$project = $this->makeProject('2026-03-01', '2026-12-31');
		$this->projectMapper->method('find')->willReturn($project);
		$this->projectMapper->method('update')->willReturnArgument(0);

		$this->service->update(10, 'user1', ['endDate' => null], true);

		$this->assertNull($project->getEndDate());
	}

	/**
	 * The amounts are replaced in one transaction, so a failure part way
	 * leaves the project and its old amounts as they were.
	 */
	public function testUpdateRollsBackWhenAnAmountFailsToSave(): void {
		$this->projectMapper->method('find')->willReturn($this->makeProject());
		$this->projectMapper->method('update')->willReturnArgument(0);
		$this->allocationMapper->method('insert')->willThrowException(new \RuntimeException('db down'));
		$this->db->expects($this->once())->method('beginTransaction');
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('db down');
		$this->service->update(10, 'user1', $this->input([
			'allocations' => [['categoryId' => 2, 'amount' => 400]],
		]), true);
	}

	public function testDeleteRemovesTheAmountsAndTheShares(): void {
		$project = $this->makeProject();
		$this->projectMapper->method('find')->willReturn($project);
		$this->allocationMapper->expects($this->once())->method('deleteByProject')->with(10);
		$this->projectMapper->expects($this->once())->method('delete')->with($project);
		$this->shareItemMapper->expects($this->once())->method('deleteByEntity')->with('project', 10);
		$this->db->expects($this->once())->method('commit');

		$this->service->delete(10, 'user1');
	}
}
