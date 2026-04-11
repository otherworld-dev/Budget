<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\AlertController;
use OCA\Budget\Service\BudgetAlertService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AlertControllerTest extends TestCase {
	private AlertController $controller;
	private BudgetAlertService $service;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(BudgetAlertService::class);
		$logger = $this->createMock(LoggerInterface::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		$this->controller = new AlertController(
			$request,
			$this->service,
			$l,
			'user1',
			$logger
		);
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsAlerts(): void {
		$alerts = [
			['category' => 'Food', 'spent' => 450, 'budget' => 500, 'percentage' => 90],
			['category' => 'Transport', 'spent' => 200, 'budget' => 200, 'percentage' => 100],
		];
		$this->service->method('getAlerts')->with('user1')->willReturn($alerts);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $response->getData());
	}

	public function testIndexHandlesException(): void {
		$this->service->method('getAlerts')
			->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── status ──────────────────────────────────────────────────────

	public function testStatusReturnsBudgetStatus(): void {
		$status = [['category' => 'Food', 'budget' => 500, 'spent' => 250]];
		$this->service->method('getBudgetStatus')->with('user1')->willReturn($status);

		$response = $this->controller->status();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($status, $response->getData());
	}

	public function testStatusHandlesException(): void {
		$this->service->method('getBudgetStatus')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->status();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── summary ─────────────────────────────────────────────────────

	public function testSummaryReturnsData(): void {
		$summary = ['totalBudgeted' => 2000, 'totalSpent' => 1500, 'alertCount' => 3];
		$this->service->method('getSummary')->with('user1')->willReturn($summary);

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($summary, $response->getData());
	}

	public function testSummaryHandlesException(): void {
		$this->service->method('getSummary')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
