<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\YearOverYearController;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\YearOverYearService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCA\Budget\Tests\Unit\Support\ReadsPdfText;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class YearOverYearControllerTest extends TestCase {
	use ReadsPdfText;

	private YearOverYearController $controller;
	private YearOverYearService $service;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(YearOverYearService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		$granularShareService = $this->createMock(GranularShareService::class);
		$granularShareService->method('canAccess')->willReturn(true);

		$this->controller = new YearOverYearController(
			$this->request,
			$this->service,
			$granularShareService,
			$l,
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
		$this->service->method('compareMonth')->willReturn(['month' => 1]);

		$response = $this->controller->compareMonth(0);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareMonthDefaultsMonthAbove12(): void {
		$currentMonth = (int) date('n');
		$this->service->expects($this->once())
			->method('compareMonth')
			->with('user1', $currentMonth, 3, null)
			->willReturn(['month' => $currentMonth]);

		$response = $this->controller->compareMonth(13);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareMonthDefaultsNegativeMonth(): void {
		$currentMonth = (int) date('n');
		$this->service->expects($this->once())
			->method('compareMonth')
			->with('user1', $currentMonth, 3, null)
			->willReturn(['month' => $currentMonth]);

		$response = $this->controller->compareMonth(-1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareMonthClampsYears(): void {
		$this->service->expects($this->once())
			->method('compareMonth')
			->with('user1', 1, 10, null)
			->willReturn(['month' => 1]);

		$response = $this->controller->compareMonth(1, 20);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareMonthClampsYearsMinimumTo1(): void {
		$this->service->expects($this->once())
			->method('compareMonth')
			->with('user1', 1, 1, null)
			->willReturn(['month' => 1]);

		$response = $this->controller->compareMonth(1, 0);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareMonthWithAccountId(): void {
		$comparison = ['month' => 6, 'years' => []];
		$this->service->expects($this->once())
			->method('compareMonth')
			->with('user1', 6, 3, 42)
			->willReturn($comparison);

		$response = $this->controller->compareMonth(6, 3, 42);

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

	public function testCompareYearsWithCustomYears(): void {
		$this->service->expects($this->once())
			->method('compareYears')
			->with('user1', 5, null)
			->willReturn(['years' => []]);

		$response = $this->controller->compareYears(5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareYearsClampsYearsTo10(): void {
		$this->service->expects($this->once())
			->method('compareYears')
			->with('user1', 10, null)
			->willReturn(['years' => []]);

		$response = $this->controller->compareYears(15);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareYearsClampsYearsMinimumTo1(): void {
		$this->service->expects($this->once())
			->method('compareYears')
			->with('user1', 1, null)
			->willReturn(['years' => []]);

		$response = $this->controller->compareYears(0);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareYearsWithAccountId(): void {
		$this->service->expects($this->once())
			->method('compareYears')
			->with('user1', 3, 7)
			->willReturn(['years' => []]);

		$response = $this->controller->compareYears(3, 7);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
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
		$this->service->expects($this->once())
			->method('compareCategorySpending')
			->with('user1', 5, null)
			->willReturn([]);

		$response = $this->controller->compareCategories(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareCategoriesClampsYearsMinimumTo1(): void {
		$this->service->expects($this->once())
			->method('compareCategorySpending')
			->with('user1', 1, null)
			->willReturn([]);

		$response = $this->controller->compareCategories(0);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testCompareCategoriesWithAccountId(): void {
		$this->service->expects($this->once())
			->method('compareCategorySpending')
			->with('user1', 2, 15)
			->willReturn(['categories' => []]);

		$response = $this->controller->compareCategories(2, 15);

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

	public function testMonthlyTrendsWithCustomYears(): void {
		$this->service->expects($this->once())
			->method('getMonthlyTrends')
			->with('user1', 4, null)
			->willReturn(['months' => []]);

		$response = $this->controller->monthlyTrends(4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMonthlyTrendsClampsYearsTo5(): void {
		$this->service->expects($this->once())
			->method('getMonthlyTrends')
			->with('user1', 5, null)
			->willReturn(['months' => []]);

		$response = $this->controller->monthlyTrends(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMonthlyTrendsClampsYearsMinimumTo1(): void {
		$this->service->expects($this->once())
			->method('getMonthlyTrends')
			->with('user1', 1, null)
			->willReturn(['months' => []]);

		$response = $this->controller->monthlyTrends(-5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMonthlyTrendsWithAccountId(): void {
		$this->service->expects($this->once())
			->method('getMonthlyTrends')
			->with('user1', 2, 99)
			->willReturn(['months' => []]);

		$response = $this->controller->monthlyTrends(2, 99);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMonthlyTrendsHandlesError(): void {
		$this->service->method('getMonthlyTrends')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->monthlyTrends();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to get monthly trends', $response->getData()['error']);
	}

	// ── export ──────────────────────────────────────────────────────

	public function testExportDefaultsCsvYears(): void {
		$data = [
			'years' => [
				['year' => 2026, 'income' => 5000, 'expenses' => 3000, 'savings' => 2000, 'transactionCount' => 50],
				['year' => 2025, 'income' => 4500, 'expenses' => 2800, 'savings' => 1700, 'transactionCount' => 45, 'incomeChange' => 11.1, 'expenseChange' => 7.1],
			],
		];
		$this->service->expects($this->once())
			->method('compareYears')
			->with('user1', 3, null)
			->willReturn($data);

		$response = $this->controller->export();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportCsvMonth(): void {
		$data = [
			'monthName' => 'June',
			'years' => [
				['year' => 2026, 'income' => 1000, 'expenses' => 800, 'savings' => 200, 'transactionCount' => 10],
			],
		];
		$this->service->expects($this->once())
			->method('compareMonth')
			->with('user1', 6, 3, null)
			->willReturn($data);

		$response = $this->controller->export('month', 'csv', 3, 6);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportCsvCategories(): void {
		$data = [
			'categories' => [
				[
					'name' => 'Food',
					'years' => [
						['year' => 2026, 'spending' => 500],
						['year' => 2025, 'spending' => 450],
					],
					'change' => 11.1,
				],
			],
		];
		$this->service->expects($this->once())
			->method('compareCategorySpending')
			->with('user1', 3, null)
			->willReturn($data);

		$response = $this->controller->export('categories', 'csv', 3);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportCsvCategoriesWithNullChange(): void {
		$data = [
			'categories' => [
				[
					'name' => 'New Category',
					'years' => [['year' => 2026, 'spending' => 100]],
					'change' => null,
				],
			],
		];
		$this->service->method('compareCategorySpending')->willReturn($data);

		$response = $this->controller->export('categories', 'csv', 2);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportMonthDefaultsInvalidMonthToCurrent(): void {
		$currentMonth = (int) date('n');
		$this->service->expects($this->once())
			->method('compareMonth')
			->with('user1', $currentMonth, 3, null)
			->willReturn(['monthName' => 'March', 'years' => []]);

		$response = $this->controller->export('month', 'csv', 3, 0);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportClampsYearsTo10(): void {
		$this->service->expects($this->once())
			->method('compareYears')
			->with('user1', 10, null)
			->willReturn(['years' => []]);

		$response = $this->controller->export('years', 'csv', 20);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportCategoriesClampsYearsTo5(): void {
		$this->service->expects($this->once())
			->method('compareCategorySpending')
			->with('user1', 5, null)
			->willReturn(['categories' => []]);

		$response = $this->controller->export('categories', 'csv', 8);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportWithAccountId(): void {
		$this->service->expects($this->once())
			->method('compareYears')
			->with('user1', 3, 42)
			->willReturn(['years' => []]);

		$response = $this->controller->export('years', 'csv', 3, 0, 42);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	/**
	 * The controller again, with a translator that knows $dictionary and
	 * passes everything else through vsprintf.
	 *
	 * @param array<string, string> $dictionary
	 */
	private function controllerTranslating(array $dictionary): YearOverYearController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(
			fn(string $text, array $params = []) => vsprintf($dictionary[$text] ?? $text, $params)
		);
		$granularShareService = $this->createMock(GranularShareService::class);
		$granularShareService->method('canAccess')->willReturn(true);

		return new YearOverYearController($this->request, $this->service, $granularShareService, $l, 'user1', $this->logger);
	}

	public function testExportCsvIsWrittenInTheUsersLanguage(): void {
		$controller = $this->controllerTranslating(['Year' => 'Jahr', 'Income' => 'Einnahmen', 'Year Comparison' => 'Jahresvergleich']);
		$this->service->method('compareYears')->willReturn(['years' => [
			['year' => 2026, 'income' => 5000, 'expenses' => 3000, 'savings' => 2000, 'transactionCount' => 50],
		]]);

		$csv = $controller->export('years', 'csv')->render();

		$this->assertStringContainsString('Jahresvergleich', $csv);
		$this->assertStringContainsString('Jahr,Einnahmen', $csv);
		$this->assertStringNotContainsString('Year', $csv);
	}

	public function testExportMonthComparisonNamesTheMonthInTheUsersLanguage(): void {
		$controller = $this->controllerTranslating(['March' => 'Maerz', '%s Comparison' => 'Vergleich %s']);
		$this->service->method('compareMonth')->willReturn(['type' => 'month', 'month' => 3, 'monthName' => 'March', 'years' => [
			['year' => 2026, 'month' => 3, 'monthName' => 'March', 'income' => 100, 'expenses' => 50, 'savings' => 50, 'transactionCount' => 3],
		]]);

		$csv = $controller->export('month', 'csv', 3, 3)->render();

		$this->assertStringContainsString('Vergleich Maerz', $csv);
		$this->assertStringNotContainsString('March', $csv);
	}

	public function testExportPdfIsWrittenInTheUsersLanguage(): void {
		$this->requireTcpdf();
		$controller = $this->controllerTranslating([
			'Year-over-Year Report' => 'Jahresbericht',
			'Category Spending Comparison' => 'Kategorienvergleich',
		]);
		$this->service->method('compareCategorySpending')->willReturn(['categories' => [
			['name' => 'Food', 'years' => [['year' => 2025, 'spending' => 100], ['year' => 2026, 'spending' => 120]], 'change' => 20.0],
		]]);

		$text = $this->pdfText($controller->export('categories', 'pdf')->render());

		$this->assertStringContainsString('Jahresbericht', $text);
		$this->assertStringContainsString('Kategorienvergleich', $text);
	}

	public function testExportPdfFallsToCsvWhenTcpdfUnavailable(): void {
		$this->service->method('compareYears')
			->willReturn(['years' => []]);

		$response = $this->controller->export('years', 'pdf', 3);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportCsvYearsWithEmptyData(): void {
		$this->service->method('compareYears')
			->willReturn(['years' => []]);

		$response = $this->controller->export('years', 'csv', 3);

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportHandlesError(): void {
		$this->service->method('compareYears')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->export();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to export YoY data', $response->getData()['error']);
	}
}
