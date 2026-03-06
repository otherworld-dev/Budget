<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ForecastController;
use OCA\Budget\Service\ForecastService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ForecastControllerTest extends TestCase {
	private ForecastController $controller;
	private ForecastService $service;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ForecastService::class);
		$logger = $this->createMock(LoggerInterface::class);

		$this->controller = new ForecastController(
			$request,
			$this->service,
			'user1',
			$logger
		);
	}

	// ── generate ────────────────────────────────────────────────────

	public function testGenerateReturnsForecast(): void {
		$forecast = ['months' => [['month' => '2025-07', 'balance' => 1500]]];
		$this->service->method('generateForecast')
			->with('user1', null, 3, 6)
			->willReturn($forecast);

		$response = $this->controller->generate();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($forecast, $response->getData());
	}

	public function testGenerateWithAccountId(): void {
		$forecast = ['months' => []];
		$this->service->method('generateForecast')
			->with('user1', 5, 3, 6)
			->willReturn($forecast);

		$response = $this->controller->generate(5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testGenerateWithCustomParams(): void {
		$forecast = ['months' => []];
		$this->service->expects($this->once())
			->method('generateForecast')
			->with('user1', 1, 6, 12)
			->willReturn($forecast);

		$response = $this->controller->generate(1, 6, 12);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testGenerateHandlesException(): void {
		$this->service->method('generateForecast')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->generate();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── cashflow ────────────────────────────────────────────────────

	public function testCashflowReturnsForecast(): void {
		$cashflow = ['data' => []];
		$this->service->method('getCashFlowForecast')->willReturn($cashflow);

		$response = $this->controller->cashflow();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCashflowWithCustomDates(): void {
		$cashflow = ['data' => []];
		$this->service->expects($this->once())
			->method('getCashFlowForecast')
			->with('user1', '2025-01-01', '2025-06-30', null)
			->willReturn($cashflow);

		$response = $this->controller->cashflow(null, '2025-01-01', '2025-06-30');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCashflowHandlesException(): void {
		$this->service->method('getCashFlowForecast')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->cashflow();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── trends ──────────────────────────────────────────────────────

	public function testTrendsReturnsData(): void {
		$trends = ['monthly' => []];
		$this->service->method('getSpendingTrends')
			->with('user1', null, 12)
			->willReturn($trends);

		$response = $this->controller->trends();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($trends, $response->getData());
	}

	public function testTrendsWithParams(): void {
		$trends = ['monthly' => []];
		$this->service->expects($this->once())
			->method('getSpendingTrends')
			->with('user1', 3, 6)
			->willReturn($trends);

		$response = $this->controller->trends(3, 6);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testTrendsHandlesException(): void {
		$this->service->method('getSpendingTrends')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->trends();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── scenarios ───────────────────────────────────────────────────

	public function testScenariosReturnsResults(): void {
		$results = ['scenarios' => []];
		$this->service->method('runScenarios')
			->with('user1', null, [])
			->willReturn($results);

		$response = $this->controller->scenarios();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testScenariosWithParams(): void {
		$scenarioInput = [['name' => 'optimistic', 'modifier' => 0.8]];
		$results = ['scenarios' => []];
		$this->service->expects($this->once())
			->method('runScenarios')
			->with('user1', 1, $scenarioInput)
			->willReturn($results);

		$response = $this->controller->scenarios(1, $scenarioInput);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testScenariosHandlesException(): void {
		$this->service->method('runScenarios')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->scenarios();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── enhanced ────────────────────────────────────────────────────

	public function testEnhancedReturnsForecast(): void {
		$forecast = ['enhanced' => true];
		$this->service->method('generateEnhancedForecast')
			->with('user1', null, 6, 6, 90)
			->willReturn($forecast);

		$response = $this->controller->enhanced();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testEnhancedWithCustomParams(): void {
		$forecast = ['enhanced' => true];
		$this->service->expects($this->once())
			->method('generateEnhancedForecast')
			->with('user1', 2, 12, 12, 95)
			->willReturn($forecast);

		$response = $this->controller->enhanced(2, 12, 12, 95);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testEnhancedHandlesException(): void {
		$this->service->method('generateEnhancedForecast')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->enhanced();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── live ────────────────────────────────────────────────────────

	public function testLiveReturnsForecast(): void {
		$forecast = ['live' => true];
		$this->service->method('getLiveForecast')
			->with('user1', 6)
			->willReturn($forecast);

		$response = $this->controller->live();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testLiveWithCustomMonths(): void {
		$forecast = ['live' => true];
		$this->service->expects($this->once())
			->method('getLiveForecast')
			->with('user1', 12)
			->willReturn($forecast);

		$response = $this->controller->live(12);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testLiveHandlesException(): void {
		$this->service->method('getLiveForecast')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->live();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── export ──────────────────────────────────────────────────────

	public function testExportReturnsData(): void {
		$forecastData = ['months' => [['month' => '2025-07']]];
		$exportResult = ['exported' => true];
		$this->service->method('exportForecast')
			->with('user1', $forecastData)
			->willReturn($exportResult);

		$response = $this->controller->export($forecastData);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testExportHandlesException(): void {
		$this->service->method('exportForecast')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->export([]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
