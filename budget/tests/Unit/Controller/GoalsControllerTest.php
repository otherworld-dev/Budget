<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\GoalsController;
use OCA\Budget\Db\SavingsGoal;
use OCA\Budget\Service\GoalsService;
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

		$this->controller = new GoalsController(
			$this->request,
			$this->service,
			$validationService,
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
}
