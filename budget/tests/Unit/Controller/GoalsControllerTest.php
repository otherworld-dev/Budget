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
	/** Shared goal ids held at Full control. */
	private array $manageableIds = [];
	/** Tag ids each user may use (requireUsableTags). */
	private array $usableTags = ['user1' => [1, 2, 3, 4, 5, 6], 'owner2' => [5, 6, 77]];
	/** Account ids each user can see (getVisibleAccountIds). */
	private array $visibleAccounts = ['user1' => [1, 2, 4], 'owner2' => [4, 30]];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(GoalsService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string)$param, $text);
			}
			return $text;
		});
		$validationService = new ValidationService($l);
		$logger = $this->createMock(LoggerInterface::class);

		$granularShareService = $this->createMock(GranularShareService::class);
		$granularShareService->method('canAccess')->willReturn(true);
		$granularShareService->method('resolveOwner')->willReturnCallback(
			fn ($u, $t, $id) => in_array($id, $this->inaccessibleIds, true)
				? null
				: ($this->ownerMap[$id] ?? 'user1')
		);
		$granularShareService->method('canWrite')->willReturnCallback(
			fn ($u, $t, $id) => !in_array($id, $this->readOnlyWriteIds, true)
		);
		$granularShareService->method('requireWriteAccess')->willReturnCallback(function ($u, $t, $id): void {
			if (in_array($id, $this->readOnlyIds, true)) {
				throw new \OCA\Budget\Exception\ReadOnlyShareException();
			}
		});
		$granularShareService->method('canManage')->willReturnCallback(
			fn ($u, $t, $id) => ($this->ownerMap[$id] ?? 'user1') === 'user1' || in_array($id, $this->manageableIds, true)
		);
		$granularShareService->method('getSharedSavingsGoalIds')->willReturnCallback(
			fn ($u) => $this->sharedIds
		);
		$granularShareService->method('requireUsableTags')->willReturnCallback(function (string $u, array $ids): void {
			if (array_diff($ids, $this->usableTags[$u] ?? []) !== []) {
				throw new \InvalidArgumentException('Invalid tag ID');
			}
		});
		$granularShareService->method('getVisibleAccountIds')->willReturnCallback(
			fn (string $u) => $this->visibleAccounts[$u] ?? []
		);
		$granularShareService->method('getUsableTagIds')->willReturnCallback(
			fn (string $u, array $ids) => array_values(array_intersect(array_map('intval', $ids), $this->usableTags[$u] ?? []))
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

	// ── a goal's tag and account (T4-5) ─────────────────────────────

	public function testCreateRefusesATagTheUserCannotSee(): void {
		$this->service->expects($this->never())->method('create');

		$response = $this->controller->create('Holiday', 1000.0, tagId: 77);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid tag ID', $response->getData()['error']);
	}

	public function testCreateRefusesAnAccountTheUserCannotSee(): void {
		$this->service->expects($this->never())->method('create');

		$response = $this->controller->create('Holiday', 1000.0, accountId: 30);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid account ID', $response->getData()['error']);
	}

	private function storedSharedGoal(?int $tagId, ?int $accountId): void {
		$this->ownerMap = [8 => 'owner2'];
		$goal = $this->makeGoal(['id' => 8, 'userId' => 'owner2']);
		$goal->setTagId($tagId);
		$goal->setAccountId($accountId);
		$this->service->method('find')->with(8, 'owner2')->willReturn($goal);
	}

	public function testARecipientCannotLinkTheOwnersPrivateTag(): void {
		// Tag 77 is owner2's alone: the goal then summed owner2's tagged
		// spending and showed it to user1 (T4-5)
		$this->storedSharedGoal(null, null);
		$this->request->method('getParams')->willReturn(['tagId' => 77]);
		$this->service->expects($this->never())->method('update');

		$response = $this->controller->update(8, tagId: 77);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testARecipientCannotLinkTheOwnersPrivateAccount(): void {
		$this->storedSharedGoal(null, null);
		$this->request->method('getParams')->willReturn(['accountId' => 30]);
		$this->service->expects($this->never())->method('update');

		$response = $this->controller->update(8, accountId: 30);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid account ID', $response->getData()['error']);
	}

	public function testARecipientCannotLinkATagTheOwnerCannotUse(): void {
		// Tag 3 is user1's own: the goal's sum is the owner's rows, so it
		// would count nothing and put user1's tag on owner2's goal
		$this->storedSharedGoal(null, null);
		$this->request->method('getParams')->willReturn(['tagId' => 3]);
		$this->service->expects($this->never())->method('update');

		$response = $this->controller->update(8, tagId: 3);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testARecipientMayLinkATagAndAccountTheyBothSee(): void {
		$this->storedSharedGoal(null, null);
		$this->request->method('getParams')->willReturn(['tagId' => 5, 'accountId' => 4]);
		$this->service->expects($this->once())->method('update')
			->with(8, 'owner2', null, null, null, null, null, null, 5, true, 4, true)
			->willReturn($this->makeGoal(['id' => 8]));

		$response = $this->controller->update(8, tagId: 5, accountId: 4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAGoalsStoredTagAndAccountKeepSaving(): void {
		// The edit form sends back what is stored, which user1 may not see
		$this->storedSharedGoal(77, 30);
		$this->request->method('getParams')->willReturn(['name' => 'Renamed', 'tagId' => 77, 'accountId' => 30]);
		$this->service->expects($this->once())->method('update')->willReturn($this->makeGoal(['id' => 8]));

		$response = $this->controller->update(8, 'Renamed', tagId: 77, accountId: 30);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testARecipientsSaveKeepsLinksSheCannotSee(): void {
		// The form can't show owner2's tag 77 or account 30, so it sends
		// both as empty: saving unlinked the owner's goal
		$this->storedSharedGoal(77, 30);
		$this->request->method('getParams')->willReturn(['name' => 'Renamed', 'tagId' => null, 'accountId' => null]);
		$this->service->expects($this->once())->method('update')
			->with(8, 'owner2', 'Renamed', null, null, null, null, null, null, false, null, false)
			->willReturn($this->makeGoal(['id' => 8]));

		$response = $this->controller->update(8, 'Renamed');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testARecipientCannotReplaceALinkSheCannotSee(): void {
		// Only the links she can see are hers to change
		$this->storedSharedGoal(77, 30);
		$this->request->method('getParams')->willReturn(['tagId' => 5, 'accountId' => 4]);
		$this->service->expects($this->once())->method('update')
			->with(8, 'owner2', null, null, null, null, null, null, 5, false, 4, false)
			->willReturn($this->makeGoal(['id' => 8]));

		$response = $this->controller->update(8, tagId: 5, accountId: 4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testARecipientMayClearALinkSheCanSee(): void {
		$this->storedSharedGoal(5, 4);
		$this->request->method('getParams')->willReturn(['tagId' => null, 'accountId' => null]);
		$this->service->expects($this->once())->method('update')
			->with(8, 'owner2', null, null, null, null, null, null, null, true, null, true)
			->willReturn($this->makeGoal(['id' => 8]));

		$response = $this->controller->update(8);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testTheOwnerMayClearAnyLink(): void {
		$goal = $this->makeGoal(['id' => 3]);
		$goal->setTagId(77);
		$goal->setAccountId(30);
		$this->service->method('find')->with(3, 'user1')->willReturn($goal);
		$this->request->method('getParams')->willReturn(['tagId' => null, 'accountId' => null]);
		$this->service->expects($this->once())->method('update')
			->with(3, 'user1', null, null, null, null, null, null, null, true, null, true)
			->willReturn($goal);

		$response = $this->controller->update(3);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
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

	public function testFullControlCanDeleteASharedGoal(): void {
		$this->ownerMap = [10 => 'owner2'];
		$this->manageableIds = [10];
		$this->service->expects($this->once())->method('delete')->with(10, 'owner2');

		$response = $this->controller->destroy(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testDestroyInaccessibleGoalReturns404(): void {
		$this->inaccessibleIds = [11];
		$this->service->expects($this->never())->method('delete');

		$response = $this->controller->destroy(11);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
