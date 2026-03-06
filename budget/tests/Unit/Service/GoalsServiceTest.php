<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\SavingsGoal;
use OCA\Budget\Db\SavingsGoalMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Service\GoalsService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class GoalsServiceTest extends TestCase {
	private SavingsGoalMapper $mapper;
	private TransactionTagMapper $transactionTagMapper;
	private GoalsService $service;

	protected function setUp(): void {
		$this->mapper = $this->createMock(SavingsGoalMapper::class);
		$this->transactionTagMapper = $this->createMock(TransactionTagMapper::class);
		$this->service = new GoalsService($this->mapper, $this->transactionTagMapper);
	}

	private function makeGoal(array $overrides = []): SavingsGoal {
		$g = new SavingsGoal();
		$g->setId($overrides['id'] ?? 1);
		$g->setUserId($overrides['userId'] ?? 'user1');
		$g->setName($overrides['name'] ?? 'Emergency Fund');
		$g->setTargetAmount($overrides['targetAmount'] ?? 5000.0);
		$g->setCurrentAmount($overrides['currentAmount'] ?? 1000.0);
		$g->setTargetMonths($overrides['targetMonths'] ?? null);
		$g->setDescription($overrides['description'] ?? null);
		$g->setTargetDate($overrides['targetDate'] ?? null);
		$g->setTagId($overrides['tagId'] ?? null);
		return $g;
	}

	// ── findAll ─────────────────────────────────────────────────────

	public function testFindAllReturnsGoals(): void {
		$goals = [$this->makeGoal(), $this->makeGoal(['id' => 2, 'name' => 'Vacation'])];
		$this->mapper->method('findAll')->with('user1')->willReturn($goals);

		$result = $this->service->findAll('user1');

		$this->assertCount(2, $result);
	}

	public function testFindAllEnrichesTagLinkedGoals(): void {
		$goals = [
			$this->makeGoal(['id' => 1, 'tagId' => 5, 'currentAmount' => 0]),
			$this->makeGoal(['id' => 2, 'name' => 'Manual', 'currentAmount' => 500]),
		];
		$this->mapper->method('findAll')->willReturn($goals);
		$this->transactionTagMapper->method('sumTransactionAmountsByTags')
			->with([5], 'user1')
			->willReturn([5 => 1234.56]);

		$result = $this->service->findAll('user1');

		$this->assertEquals(1234.56, $result[0]->getCurrentAmount());
		$this->assertEquals(500.0, $result[1]->getCurrentAmount()); // Not tag-linked
	}

	public function testFindAllSkipsBatchWhenNoTaggedGoals(): void {
		$this->mapper->method('findAll')->willReturn([$this->makeGoal()]);
		$this->transactionTagMapper->expects($this->never())
			->method('sumTransactionAmountsByTags');

		$this->service->findAll('user1');
	}

	// ── find ────────────────────────────────────────────────────────

	public function testFindReturnsGoal(): void {
		$goal = $this->makeGoal();
		$this->mapper->method('find')->with(1, 'user1')->willReturn($goal);

		$result = $this->service->find(1, 'user1');

		$this->assertSame('Emergency Fund', $result->getName());
	}

	public function testFindEnrichesTagLinkedGoal(): void {
		$goal = $this->makeGoal(['tagId' => 5]);
		$this->mapper->method('find')->willReturn($goal);
		$this->transactionTagMapper->method('sumTransactionAmountsByTag')
			->with(5, 'user1')
			->willReturn(750.0);

		$result = $this->service->find(1, 'user1');

		$this->assertEquals(750.0, $result->getCurrentAmount());
	}

	public function testFindThrowsNotFound(): void {
		$this->mapper->method('find')
			->willThrowException(new DoesNotExistException(''));

		$this->expectException(DoesNotExistException::class);
		$this->service->find(999, 'user1');
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateBasicGoal(): void {
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(function (SavingsGoal $g) {
				$this->assertSame('user1', $g->getUserId());
				$this->assertSame('Vacation', $g->getName());
				$this->assertEquals(3000.0, $g->getTargetAmount());
				$this->assertEquals(0.0, $g->getCurrentAmount());
				$g->setId(1);
				return $g;
			});

		$result = $this->service->create('user1', 'Vacation', 3000.0);
		$this->assertSame('Vacation', $result->getName());
	}

	public function testCreateWithTagIdSetsCurrentAmountToZero(): void {
		$this->mapper->method('insert')
			->willReturnCallback(function (SavingsGoal $g) {
				$this->assertEquals(0.0, $g->getCurrentAmount()); // Tag-linked → forced to 0
				$this->assertSame(5, $g->getTagId());
				$g->setId(1);
				return $g;
			});
		$this->transactionTagMapper->method('sumTransactionAmountsByTag')->willReturn(0.0);

		$this->service->create('user1', 'Tagged', 5000.0, null, 1000.0, null, null, 5);
	}

	public function testCreateWithAllParams(): void {
		$this->mapper->method('insert')
			->willReturnCallback(function (SavingsGoal $g) {
				$this->assertSame(12, $g->getTargetMonths());
				$this->assertSame('Beach trip', $g->getDescription());
				$this->assertSame('2026-12-01', $g->getTargetDate());
				$this->assertNotNull($g->getCreatedAt());
				$g->setId(1);
				return $g;
			});

		$this->service->create('user1', 'Vacation', 3000.0, 12, 500.0, 'Beach trip', '2026-12-01');
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateName(): void {
		$goal = $this->makeGoal();
		$this->mapper->method('find')->with(1, 'user1')->willReturn($goal);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->update(1, 'user1', 'New Name');

		$this->assertSame('New Name', $result->getName());
	}

	public function testUpdateOnlyChangesProvidedFields(): void {
		$goal = $this->makeGoal(['targetAmount' => 5000.0, 'currentAmount' => 1000.0]);
		$this->mapper->method('find')->willReturn($goal);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->update(1, 'user1', null, 8000.0);

		$this->assertEquals(8000.0, $result->getTargetAmount());
		$this->assertSame('Emergency Fund', $result->getName()); // Unchanged
	}

	public function testUpdateTagIdWhenFlagSet(): void {
		$goal = $this->makeGoal();
		$this->mapper->method('find')->willReturn($goal);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->update(1, 'user1', null, null, null, null, null, null, 5, true);

		$this->assertSame(5, $result->getTagId());
	}

	public function testUpdateTagIdNotSetWhenFlagFalse(): void {
		$goal = $this->makeGoal(['tagId' => 3]);
		$this->mapper->method('find')->willReturn($goal);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->update(1, 'user1', null, null, null, null, null, null, 5, false);

		$this->assertSame(3, $result->getTagId()); // Unchanged
	}

	public function testUpdateIgnoresCurrentAmountForTagLinkedGoal(): void {
		$goal = $this->makeGoal(['tagId' => 5, 'currentAmount' => 100.0]);
		$this->mapper->method('find')->willReturn($goal);
		$this->mapper->method('update')->willReturnArgument(0);
		$this->transactionTagMapper->method('sumTransactionAmountsByTag')->willReturn(100.0);

		$result = $this->service->update(1, 'user1', null, null, null, 9999.0);

		// currentAmount should NOT be updated for tag-linked goals
		$this->assertEquals(100.0, $result->getCurrentAmount());
	}

	public function testUpdateCurrentAmountForNonTagGoal(): void {
		$goal = $this->makeGoal(['tagId' => null, 'currentAmount' => 100.0]);
		$this->mapper->method('find')->willReturn($goal);
		$this->mapper->method('update')->willReturnArgument(0);

		$result = $this->service->update(1, 'user1', null, null, null, 2000.0);

		$this->assertEquals(2000.0, $result->getCurrentAmount());
	}

	// ── delete ──────────────────────────────────────────────────────

	public function testDeleteFindsAndDeletes(): void {
		$goal = $this->makeGoal();
		$this->mapper->method('find')->with(1, 'user1')->willReturn($goal);
		$this->mapper->expects($this->once())->method('delete')->with($goal);

		$this->service->delete(1, 'user1');
	}

	public function testDeleteThrowsNotFound(): void {
		$this->mapper->method('find')
			->willThrowException(new DoesNotExistException(''));

		$this->expectException(DoesNotExistException::class);
		$this->service->delete(999, 'user1');
	}

	// ── getProgress ─────────────────────────────────────────────────

	public function testGetProgressBasic(): void {
		$goal = $this->makeGoal(['targetAmount' => 5000.0, 'currentAmount' => 2500.0]);
		$this->mapper->method('find')->willReturn($goal);

		$progress = $this->service->getProgress(1, 'user1');

		$this->assertSame(1, $progress['goalId']);
		$this->assertEquals(50.0, $progress['percentage']);
		$this->assertEquals(2500.0, $progress['remaining']);
		$this->assertTrue($progress['onTrack']);
	}

	public function testGetProgressCompletedGoal(): void {
		$goal = $this->makeGoal(['targetAmount' => 5000.0, 'currentAmount' => 5000.0]);
		$this->mapper->method('find')->willReturn($goal);

		$progress = $this->service->getProgress(1, 'user1');

		$this->assertEquals(100.0, $progress['percentage']);
		$this->assertEquals(0.0, $progress['remaining']);
	}

	public function testGetProgressWithTargetMonths(): void {
		$goal = $this->makeGoal(['targetAmount' => 6000.0, 'currentAmount' => 0, 'targetMonths' => 12]);
		$this->mapper->method('find')->willReturn($goal);

		$progress = $this->service->getProgress(1, 'user1');

		$this->assertEquals(500.0, $progress['monthlyRequired']); // 6000 / 12
	}

	public function testGetProgressZeroTargetAmount(): void {
		$goal = $this->makeGoal(['targetAmount' => 0, 'currentAmount' => 0]);
		$this->mapper->method('find')->willReturn($goal);

		$progress = $this->service->getProgress(1, 'user1');

		$this->assertEquals(0.0, $progress['percentage']);
	}

	// ── getForecast ─────────────────────────────────────────────────

	public function testGetForecastOnTrack(): void {
		$goal = $this->makeGoal(['targetAmount' => 5000.0, 'currentAmount' => 4000.0]);
		$this->mapper->method('find')->willReturn($goal);

		$forecast = $this->service->getForecast(1, 'user1');

		$this->assertSame(1, $forecast['goalId']);
		$this->assertStringContainsString('On track', $forecast['currentProjection']);
		$this->assertEquals(85.0, $forecast['probabilityOfSuccess']);
		$this->assertContains('Great progress! Keep up the current savings rate', $forecast['recommendations']);
	}

	public function testGetForecastLowProgress(): void {
		$goal = $this->makeGoal(['targetAmount' => 10000.0, 'currentAmount' => 500.0]);
		$this->mapper->method('find')->willReturn($goal);

		$forecast = $this->service->getForecast(1, 'user1');

		$this->assertContains('Consider automating transfers to build momentum', $forecast['recommendations']);
	}
}
