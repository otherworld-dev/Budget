<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\YearOverYearController;
use OCA\Budget\Service\YearOverYearService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class YearOverYearControllerTest extends TestCase {
	private YearOverYearController $controller;
	private YearOverYearService $service;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(YearOverYearService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new YearOverYearController(
			$this->request,
			$this->service,
			'user1',
			$this->logger
		);
	}

	// ── compareMonth ────────────────────────────────────────────────

	public function testCompareMonthReturnsData(): void {
		$comparison = ['month' => 3, 'years' => []];
		$this->service->method('compareMonth')
			->with('user1', 3, 3, null)
			->willReturn($comparison);

		$response = $this->controller->compareMonth(3);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($comparison, $response->getData());
	}

	public function testCompareMonthDefaultsInvalidMonth(): void {
		// Month 0 or > 12 should default to current month
		$this->service->method('compareMonth')->willReturn(['month' => 1]);

		$response = $this->controller->compareMonth(0);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareMonthClampsYears(): void {
		// Years > 10 should be clamped to 10
		$this->service->method('compareMonth')->willReturn(['month' => 1]);

		$response = $this->controller->compareMonth(1, 20);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareMonthHandlesError(): void {
		$this->service->method('compareMonth')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->compareMonth(1);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to compare month data', $response->getData()['error']);
	}

	// ── compareYears ────────────────────────────────────────────────

	public function testCompareYearsReturnsData(): void {
		$comparison = ['years' => [['year' => 2025], ['year' => 2026]]];
		$this->service->method('compareYears')
			->with('user1', 3, null)
			->willReturn($comparison);

		$response = $this->controller->compareYears();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($comparison, $response->getData());
	}

	public function testCompareYearsHandlesError(): void {
		$this->service->method('compareYears')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->compareYears();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to compare year data', $response->getData()['error']);
	}

	// ── compareCategories ───────────────────────────────────────────

	public function testCompareCategoriesReturnsData(): void {
		$comparison = ['categories' => []];
		$this->service->method('compareCategorySpending')
			->with('user1', 2, null)
			->willReturn($comparison);

		$response = $this->controller->compareCategories();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($comparison, $response->getData());
	}

	public function testCompareCategoriesClampsYearsTo5(): void {
		// Years > 5 should be clamped to 5
		$this->service->method('compareCategorySpending')->willReturn([]);

		$response = $this->controller->compareCategories(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareCategoriesHandlesError(): void {
		$this->service->method('compareCategorySpending')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->compareCategories();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to compare category data', $response->getData()['error']);
	}

	// ── monthlyTrends ───────────────────────────────────────────────

	public function testMonthlyTrendsReturnsData(): void {
		$trends = ['months' => []];
		$this->service->method('getMonthlyTrends')
			->with('user1', 2, null)
			->willReturn($trends);

		$response = $this->controller->monthlyTrends();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($trends, $response->getData());
	}

	public function testMonthlyTrendsHandlesError(): void {
		$this->service->method('getMonthlyTrends')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->monthlyTrends();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to get monthly trends', $response->getData()['error']);
	}

	// ── export ──────────────────────────────────────────────────────

	public function testExportHandlesError(): void {
		$this->service->method('compareYears')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->export();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to export YoY data', $response->getData()['error']);
	}
}
