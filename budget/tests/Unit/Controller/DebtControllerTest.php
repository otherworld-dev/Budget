<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\DebtController;
use OCA\Budget\Service\DebtPayoffService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DebtControllerTest extends TestCase {
	private DebtController $controller;
	private DebtPayoffService $service;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(DebtPayoffService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->controller = new DebtController(
			$request,
			$this->service,
			'user1',
			$logger
		);
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsDebts(): void {
		$debts = [['name' => 'CC', 'balance' => 5000], ['name' => 'Loan', 'balance' => 10000]];
		$this->service->method('getDebts')->with('user1')->willReturn($debts);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $response->getData());
	}

	public function testIndexHandlesException(): void {
		$this->service->method('getDebts')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to retrieve debts', $response->getData()['error']);
	}

	// ── summary ─────────────────────────────────────────────────────

	public function testSummaryReturnsData(): void {
		$summary = ['totalDebt' => 15000, 'monthlyPayment' => 500];
		$this->service->method('getSummary')->with('user1')->willReturn($summary);

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($summary, $response->getData());
	}

	public function testSummaryHandlesException(): void {
		$this->service->method('getSummary')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	// ── payoffPlan ──────────────────────────────────────────────────

	public function testPayoffPlanDefaultAvalanche(): void {
		$plan = ['strategy' => 'avalanche', 'months' => 24];
		$this->service->method('calculatePayoffPlan')
			->with('user1', 'avalanche', null)
			->willReturn($plan);

		$response = $this->controller->payoffPlan();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($plan, $response->getData());
	}

	public function testPayoffPlanSnowball(): void {
		$plan = ['strategy' => 'snowball', 'months' => 26];
		$this->service->method('calculatePayoffPlan')
			->with('user1', 'snowball', null)
			->willReturn($plan);

		$response = $this->controller->payoffPlan('snowball');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testPayoffPlanWithExtraPayment(): void {
		$plan = ['months' => 18];
		$this->service->expects($this->once())
			->method('calculatePayoffPlan')
			->with('user1', 'avalanche', 200.0)
			->willReturn($plan);

		$response = $this->controller->payoffPlan('avalanche', 200.0);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testPayoffPlanRejectsInvalidStrategy(): void {
		$response = $this->controller->payoffPlan('invalid');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid strategy', $response->getData()['error']);
	}

	public function testPayoffPlanRejectsNegativeExtraPayment(): void {
		$response = $this->controller->payoffPlan('avalanche', -50.0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('cannot be negative', $response->getData()['error']);
	}

	public function testPayoffPlanHandlesException(): void {
		$this->service->method('calculatePayoffPlan')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->payoffPlan();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	// ── compare ─────────────────────────────────────────────────────

	public function testCompareReturnsData(): void {
		$comparison = ['avalanche' => ['months' => 24], 'snowball' => ['months' => 26]];
		$this->service->method('compareStrategies')
			->with('user1', null)
			->willReturn($comparison);

		$response = $this->controller->compare();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($comparison, $response->getData());
	}

	public function testCompareWithExtraPayment(): void {
		$comparison = ['savings' => 500];
		$this->service->expects($this->once())
			->method('compareStrategies')
			->with('user1', 100.0)
			->willReturn($comparison);

		$response = $this->controller->compare(100.0);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareRejectsNegativeExtraPayment(): void {
		$response = $this->controller->compare(-10.0);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCompareHandlesException(): void {
		$this->service->method('compareStrategies')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->compare();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}
}
