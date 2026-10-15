<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ApiV1BillController;
use OCA\Budget\Service\UpcomingBillsService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiV1BillControllerTest extends TestCase {
	private UpcomingBillsService $service;
	private array $params = [];
	private ApiV1BillController $controller;

	protected function setUp(): void {
		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
		$this->service = $this->createMock(UpcomingBillsService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$this->controller = new ApiV1BillController($request, $this->service, $l, 'user1', $this->createMock(LoggerInterface::class));
	}

	/** @dataProvider daysCases */
	public function testDaysIsDefaultedAndClamped(mixed $days, int $expected): void {
		$this->params = $days === null ? [] : ['days' => $days];
		$this->service->expects($this->once())->method('upcoming')->with('user1', $expected)->willReturn([]);

		$data = $this->controller->upcoming()->getData();

		$this->assertSame(['days' => $expected, 'bills' => []], $data);
	}

	public static function daysCases(): array {
		return [[null, 14], ['30', 30], ['abc', 14], ['0', 1], ['-5', 1], ['1000', 90], [['7'], 14]];
	}

	public function testBillsAreSerialized(): void {
		$this->service->method('upcoming')->willReturn([['id' => 3, 'name' => 'Netflix', 'amount' => 12.99, 'userId' => 'user1']]);

		$bill = $this->controller->upcoming()->getData()['bills'][0];

		$this->assertSame('12.99', $bill['amount']);
		$this->assertArrayNotHasKey('userId', $bill);
	}

	public function testAFailureIsReportedLikeTheOtherV1Routes(): void {
		$this->service->method('upcoming')->willThrowException(new \RuntimeException('db'));

		// handleError()'s default, as every v1 controller uses it
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->upcoming()->getStatus());
	}
}
