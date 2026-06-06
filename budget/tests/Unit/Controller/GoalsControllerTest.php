<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\GoalsController;
use OCA\Budget\Db\SavingsGoal;
use OCA\Budget\Service\GoalsService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class GoalsControllerTest extends TestCase {
	private GoalsController $controller;
	private GoalsService $service;
	private IRequest $request;

	/** Owner returned by resolveOwner per goal id (defaults to 'user1'). */
	private array $ownerMap = [];
	/** Goal ids that resolveOwner should treat as inaccessible (returns null). */
	private array $inaccessibleIds = [];
	/** Goal ids that canWrite should return false for. */
	private array $readOnlyWriteIds = [];
	/** Goal ids that requireWriteAccess should reject with ReadOnlyShareException. */
	private array $readOnlyIds = [];
	/** Ids returned by getSharedSavingsGoalIds. */
	private array $sharedIds = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(GoalsService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
			}
			return $text;
		});
		$validationService = new ValidationService($l);
		$logger = $this->createMock(LoggerInterface::class);

		$granularShareService = $this->createMock(GranularShareService::class);
		$granularShareService->method('canAccess')->willReturn(true);
		$granularShareService->method('resolveOwner')->willReturnCallback(
			fn($u, $t, $id) => in_array($id, $this->inaccessibleIds, true)
				? null
				: ($this->ownerMap[$id] ?? 'user1')
		);
		$granularShareService->method('canWrite')->willReturnCallback(
			fn($u, $t, $id) => !in_array($id, $this->readOnlyWriteIds, true)
		);
		$granularShareService->method('requireWriteAccess')->willReturnCallback(function ($u, $t, $id): void {
			if (in_array($id, $this->readOnlyIds, true)) {
				throw new \OCA\Budget\Exception\ReadOnlyShareException();
			}
		});
		$granularShareService->method('getSharedSavingsGoalIds')->willReturnCallback(
			fn($u) => $this->sharedIds
		);

		$this->controller = new GoalsController(
			$this->request,
			$this->service,
			$validationService,
			$granularShareService,
			$l,
			'user1',
			$logger
		);
	}

	private function makeGoal(array $overrides = []): SavingsGoal {
		$g = new SavingsGoal();
		$g->setId($overrides['id'] ?? 1);
		$g->setUserId($overrides['userId'] ?? 'user1');
		$g->setName($overrides['name'] ?? 'Emergency Fund');
		$g->setTargetAmount($overrides['targetAmount'] ?? 5000.0);
		$g->setCurrentAmount($overrides['currentAmount'] ?? 1000.0);
		return $g;
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsGoals(): void {
		$goals = [$this->makeGoal(), $this->makeGoal(['id' => 2, 'name' => 'Vacation'])];
		$this->service->method('findAll')->with('user1')->willReturn($goals);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $response->getData());
	}

	public function testIndexHandlesException(): void {
		$this->service->method('findAll')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsGoal(): void {
		$goal = $this->makeGoal();
		$this->service->method('find')->with(1, 'user1')->willReturn($goal);

		$response = $this->controller->show(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testShowReturns404WhenNotFound(): void {
		$this->service->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateValidGoal(): void {
		$goal = $this->makeGoal();
		$this->service->method('create')->willReturn($goal);

		$response = $this->controller->create('Emergency Fund', 5000.0);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateWithAllParams(): void {
		$goal = $this->makeGoal();
		$this->service->expects($this->once())
			->method('create')
			->with('user1', 'Vacation', 3000.0, 12, 500.0, 'Beach trip', '2026-06-01', 5)
			->willReturn($goal);

		$response = $this->controller->create('Vacation', 3000.0, 500.0, 12, 'Beach trip', '2026-06-01', 5);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateRejectsEmptyName(): void {
		$response = $this->controller->create('', 5000.0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateRejectsZeroTargetAmount(): void {
		$response = $this->controller->create('Goal', 0.0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('greater than zero', $response->getData()['error']);
	}

	public function testCreateRejectsNegativeTargetAmount(): void {
		$response = $this->controller->create('Goal', -100.0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateRejectsNegativeCurrentAmount(): void {
		$response = $this->controller->create('Goal', 5000.0, -50.0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('cannot be negative', $response->getData()['error']);
	}

	public function testCreateRejectsZeroTargetMonths(): void {
		$response = $this->controller->create('Goal', 5000.0, 0.0, 0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Target months', $response->getData()['error']);
	}

	public function testCreateRejectsInvalidTargetDate(): void {
		$response = $this->controller->create('Goal', 5000.0, 0.0, null, null, 'not-a-date');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('YYYY-MM-DD', $response->getData()['error']);
	}

	public function testCreateRejectsInvalidTagId(): void {
		$response = $this->controller->create('Goal', 5000.0, 0.0, null, null, null, -1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid tag', $response->getData()['error']);
	}

	public function testCreateAcceptsValidTargetDate(): void {
		$goal = $this->makeGoal();
		$this->service->method('create')->willReturn($goal);

		$response = $this->controller->create('Goal', 5000.0, 0.0, null, null, '2026-12-31');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateHandlesServiceException(): void {
		$this->service->method('create')
			->willThrowException(new \RuntimeException('duplicate'));

		$response = $this->controller->create('Goal', 5000.0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateName(): void {
		$goal = $this->makeGoal();
		$this->request->method('getParams')->willReturn([]);
		$this->service->method('update')->willReturn($goal);

		$response = $this->controller->update(1, 'New Name');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateRejectsNegativeTargetAmount(): void {
		$response = $this->controller->update(1, null, -100.0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateRejectsZeroTargetMonths(): void {
		$response = $this->controller->update(1, null, null, 0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateRejectsNegativeCurrentAmount(): void {
		$response = $this->controller->update(1, null, null, null, -1.0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateRejectsInvalidDate(): void {
		$response = $this->controller->update(1, null, null, null, null, null, 'bad-date');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateRejectsInvalidTagId(): void {
		$response = $this->controller->update(1, null, null, null, null, null, null, -5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdatePassesTagIdFlag(): void {
		$goal = $this->makeGoal();
		$this->request->method('getParams')->willReturn(['tagId' => null]);
		$this->service->expects($this->once())
			->method('update')
			->with(1, 'user1', null, null, null, null, null, null, null, true)
			->willReturn($goal);

		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroySuccess(): void {
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertStringContainsString('deleted', $response->getData()['message']);
	}

	public function testDestroyHandlesException(): void {
		$this->service->method('delete')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── progress ────────────────────────────────────────────────────

	public function testProgressReturnsData(): void {
		$progress = ['percentage' => 45.0, 'remaining' => 2750.0];
		$this->service->method('getProgress')->with(1, 'user1')->willReturn($progress);

		$response = $this->controller->progress(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($progress, $response->getData());
	}

	public function testProgressHandlesException(): void {
		$this->service->method('getProgress')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->progress(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── forecast ────────────────────────────────────────────────────

	public function testForecastReturnsData(): void {
		$forecast = ['estimatedDate' => '2026-12-01', 'monthlyNeeded' => 250.0];
		$this->service->method('getForecast')->with(1, 'user1')->willReturn($forecast);

		$response = $this->controller->forecast(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($forecast, $response->getData());
	}

	public function testForecastHandlesException(): void {
		$this->service->method('getForecast')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->forecast(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── sharing: read access ─────────────────────────────────────────

	public function testIndexMergesSharedGoals(): void {
		$this->service->method('findAll')->with('user1')->willReturn([$this->makeGoal()]);
		$this->sharedIds = [20];
		$this->readOnlyWriteIds = []; // goal 20 is writable
		$this->service->method('findShared')->with([20])->willReturn([
			['id' => 20, 'name' => 'Shared Goal', '_shared' => true],
		]);

		$response = $this->controller->index();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $data);
		$this->assertTrue($data[1]['_shared']);
		$this->assertTrue($data[1]['_canWrite']);
	}

	public function testShowSharedGoalAddsFlags(): void {
		$this->ownerMap = [5 => 'owner2'];
		$this->readOnlyWriteIds = [5]; // read-only share
		$goal = $this->makeGoal(['id' => 5, 'userId' => 'owner2']);
		$this->service->method('find')->with(5, 'owner2')->willReturn($goal);

		$response = $this->controller->show(5);
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($data['_shared']);
		$this->assertFalse($data['_canWrite']);
	}

	public function testShowInaccessibleGoalReturns404(): void {
		$this->inaccessibleIds = [7];
		$this->service->expects($this->never())->method('find');

		$response = $this->controller->show(7);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testProgressSharedGoalUsesOwner(): void {
		$this->ownerMap = [3 => 'owner2'];
		$this->service->method('getProgress')->with(3, 'owner2')->willReturn(['percentage' => 10.0]);

		$response = $this->controller->progress(3);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testProgressInaccessibleGoalReturns404(): void {
		$this->inaccessibleIds = [3];
		$this->service->expects($this->never())->method('getProgress');

		$response = $this->controller->progress(3);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── sharing: write access ────────────────────────────────────────

	public function testUpdateSharedGoalUsesOwner(): void {
		$this->ownerMap = [8 => 'owner2'];
		$this->request->method('getParams')->willReturn([]);
		$captured = null;
		$this->service->method('update')->willReturnCallback(function (...$args) use (&$captured) {
			$captured = $args;
			return $this->makeGoal(['id' => 8, 'userId' => 'owner2']);
		});

		$response = $this->controller->update(8, 'New Name');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(8, $captured[0]);
		$this->assertSame('owner2', $captured[1]); // owner, not the recipient
	}

	public function testUpdateReadOnlyShareReturns403(): void {
		$this->ownerMap = [9 => 'owner2'];
		$this->readOnlyIds = [9];
		$this->request->method('getParams')->willReturn([]);
		$this->service->expects($this->never())->method('update');

		$response = $this->controller->update(9, 'New Name');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testDestroyForbiddenForNonOwner(): void {
		$this->ownerMap = [10 => 'owner2'];
		$this->service->expects($this->never())->method('delete');

		$response = $this->controller->destroy(10);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testDestroyInaccessibleGoalReturns404(): void {
		$this->inaccessibleIds = [11];
		$this->service->expects($this->never())->method('delete');

		$response = $this->controller->destroy(11);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
