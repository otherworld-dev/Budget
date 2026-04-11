<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ReportController;
use OCA\Budget\Service\ReportService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ReportControllerTest extends TestCase {
	private ReportController $controller;
	private ReportService $service;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ReportService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		$this->controller = new ReportController(
			$this->request,
			$this->service,
			$l,
			'user1',
			$this->logger
		);
	}

	// ── summary ─────────────────────────────────────────────────────

	public function testSummaryReturnsData(): void {
		$summary = ['totalIncome' => 5000, 'totalExpenses' => 3000];
		$this->service->method('generateSummary')->willReturn($summary);

		$response = $this->controller->summary(null, '2026-01-01', '2026-01-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($summary, $response->getData());
	}

	public function testSummaryUsesDefaultDates(): void {
		$summary = ['totalIncome' => 5000];
		$this->service->method('generateSummary')->willReturn($summary);

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testSummaryHandlesError(): void {
		$this->service->method('generateSummary')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->summary();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── spending ────────────────────────────────────────────────────

	public function testSpendingReturnsData(): void {
		$spending = ['categories' => [['name' => 'Food', 'total' => 500]]];
		$this->service->method('getSpendingReport')->willReturn($spending);

		$response = $this->controller->spending(null, '2026-01-01', '2026-01-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($spending, $response->getData());
	}

	public function testSpendingHandlesError(): void {
		$this->service->method('getSpendingReport')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->spending();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── income ──────────────────────────────────────────────────────

	public function testIncomeReturnsData(): void {
		$income = ['months' => [['month' => '2026-01', 'total' => 5000]]];
		$this->service->method('getIncomeReport')->willReturn($income);

		$response = $this->controller->income(null, '2026-01-01', '2026-01-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($income, $response->getData());
	}

	public function testIncomeHandlesError(): void {
		$this->service->method('getIncomeReport')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->income();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── export ──────────────────────────────────────────────────────

	public function testExportHandlesError(): void {
		$this->service->method('exportReport')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->export('summary');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── budget ──────────────────────────────────────────────────────

	public function testBudgetReturnsData(): void {
		$budget = ['categories' => [], 'totalBudget' => 2000];
		$this->service->method('getBudgetReport')->willReturn($budget);

		$response = $this->controller->budget('2026-01-01', '2026-01-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($budget, $response->getData());
	}

	public function testBudgetHandlesError(): void {
		$this->service->method('getBudgetReport')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->budget();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── summaryWithComparison ───────────────────────────────────────

	public function testSummaryWithComparisonReturnsData(): void {
		$summary = ['current' => [], 'previous' => []];
		$this->service->method('generateSummaryWithComparison')->willReturn($summary);

		$response = $this->controller->summaryWithComparison(null, '2026-01-01', '2026-01-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($summary, $response->getData());
	}

	public function testSummaryWithComparisonHandlesError(): void {
		$this->service->method('generateSummaryWithComparison')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->summaryWithComparison();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── cashflow ────────────────────────────────────────────────────

	public function testCashflowReturnsData(): void {
		$cashflow = ['months' => []];
		$this->service->method('getCashFlowReport')->willReturn($cashflow);

		$response = $this->controller->cashflow(null, '2026-01-01', '2026-01-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($cashflow, $response->getData());
	}

	public function testCashflowHandlesError(): void {
		$this->service->method('getCashFlowReport')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->cashflow();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── tagDimensions ───────────────────────────────────────────────

	public function testTagDimensionsReturnsData(): void {
		$dimensions = ['tagSets' => []];
		$this->service->method('getTagDimensions')->willReturn($dimensions);

		$response = $this->controller->tagDimensions('2026-01-01', '2026-01-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($dimensions, $response->getData());
	}

	public function testTagDimensionsHandlesError(): void {
		$this->service->method('getTagDimensions')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->tagDimensions();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── tagCombinations ─────────────────────────────────────────────

	public function testTagCombinationsReturnsData(): void {
		$combinations = ['combinations' => []];
		$this->service->method('getTagCombinationReport')->willReturn($combinations);

		$response = $this->controller->tagCombinations('2026-01-01', '2026-01-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($combinations, $response->getData());
	}

	public function testTagCombinationsHandlesError(): void {
		$this->service->method('getTagCombinationReport')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->tagCombinations();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── tagCrossTab ─────────────────────────────────────────────────

	public function testTagCrossTabReturnsData(): void {
		$crossTab = ['rows' => [], 'columns' => []];
		$this->service->method('getTagCrossTabulation')->willReturn($crossTab);

		$response = $this->controller->tagCrossTab(1, 2, '2026-01-01', '2026-01-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($crossTab, $response->getData());
	}

	public function testTagCrossTabHandlesError(): void {
		$this->service->method('getTagCrossTabulation')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->tagCrossTab(1, 2);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── tagTrends ───────────────────────────────────────────────────

	public function testTagTrendsReturnsData(): void {
		$trends = ['months' => []];
		$this->service->method('getTagTrendReport')->willReturn($trends);

		$response = $this->controller->tagTrends([1, 2], '2026-01-01', '2026-01-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($trends, $response->getData());
	}

	public function testTagTrendsHandlesError(): void {
		$this->service->method('getTagTrendReport')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->tagTrends();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── tagSetBreakdown ─────────────────────────────────────────────

	public function testTagSetBreakdownReturnsData(): void {
		$breakdown = ['tags' => []];
		$this->service->method('getTagSetBreakdown')->willReturn($breakdown);

		$response = $this->controller->tagSetBreakdown(1, '2026-01-01', '2026-01-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($breakdown, $response->getData());
	}

	public function testTagSetBreakdownHandlesError(): void {
		$this->service->method('getTagSetBreakdown')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->tagSetBreakdown(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
