<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ApiV1BudgetController;
use OCA\Budget\Service\BudgetStatusService;
use OCA\Budget\Service\CurrencyConversionService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiV1BudgetControllerTest extends TestCase {
	private BudgetStatusService $service;
	private array $params = [];
	private ApiV1BudgetController $controller;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
		$this->service = $this->createMock(BudgetStatusService::class);
		$currency = $this->createMock(CurrencyConversionService::class);
		$currency->method('getBaseCurrency')->willReturn('GBP');
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$this->controller = new ApiV1BudgetController($request, $this->service, $currency, $l, 'user1', $this->createMock(LoggerInterface::class));
	}

	private static function statusFor(string $month): array {
		return ['month' => $month, 'startDate' => "$month-01", 'endDate' => "$month-30",
			'totals' => ['budgeted' => '0', 'spent' => '0', 'remaining' => '0'], 'categories' => []];
	}

	public function testNoMonthAsksForTheCurrentOne(): void {
		$this->service->expects($this->once())->method('forMonth')->with('user1', null)->willReturn(self::statusFor('2026-09'));

		$data = $this->controller->status()->getData();

		$this->assertSame('2026-09', $data['month']);
		$this->assertSame('GBP', $data['currency']);
	}

	public function testAnEmptyMonthIsTheCurrentOne(): void {
		$this->params = ['month' => ''];
		$this->service->expects($this->once())->method('forMonth')->with('user1', null)->willReturn(self::statusFor('2026-09'));

		$this->assertSame(Http::STATUS_OK, $this->controller->status()->getStatus());
	}

	public function testAGivenMonthIsPassedOn(): void {
		$this->params = ['month' => '2026-08'];
		$this->service->expects($this->once())->method('forMonth')->with('user1', '2026-08')->willReturn(self::statusFor('2026-08'));

		$this->assertSame('2026-08', $this->controller->status()->getData()['month']);
	}

	/** @dataProvider malformedMonths */
	public function testAMalformedMonthIsABadRequest(mixed $month): void {
		$this->params = ['month' => $month];
		$this->service->expects($this->never())->method('forMonth');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->status()->getStatus());
	}

	public static function malformedMonths(): array {
		return [['abc'], ['2026-13'], ['2026-00'], ['2026-9'], ['2026-09-01'], [['2026-09']]];
	}

	public function testAFailureIsReportedLikeTheOtherV1Routes(): void {
		$this->service->method('forMonth')->willThrowException(new \RuntimeException('db'));

		// handleError()'s default, as every v1 controller uses it
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->status()->getStatus());
	}
}
