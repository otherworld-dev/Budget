<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ApiV1RunwayController;
use OCA\Budget\Service\Runway\RunwayService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiV1RunwayControllerTest extends TestCase {
	private RunwayService $service;
	private ApiV1RunwayController $controller;

	protected function setUp(): void {
		$this->service = $this->createMock(RunwayService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$this->controller = new ApiV1RunwayController(
			$this->createMock(IRequest::class), $this->service, $l, 'user1', $this->createMock(LoggerInterface::class)
		);
	}

	private static function runwayResult(): array {
		$none = ['state' => 'not_running_out', 'monthlyDrain' => null, 'months' => null, 'runsOutOn' => null];
		return [
			'window' => ['months' => 3, 'effectiveMonths' => 3, 'from' => '2026-08-10', 'to' => '2026-11-10'],
			'monthlyOut' => '100', 'monthlyIn' => '200',
			'excluded' => ['count' => 0, 'total' => '0', 'ids' => []],
			'scenarios' => ['current' => $none, 'onBudget' => ['state' => 'unavailable'] + $none],
			'differenceMonths' => null,
			'series' => ['history' => [], 'current' => [], 'onBudget' => []],
			'events' => [],
			'currency' => 'GBP', 'total' => '50',
			'accounts' => [['id' => 1, 'name' => 'Current', 'balance' => '50']],
			'skippedAccounts' => [],
			'oneOffs' => [],
			'settings' => ['accountIds' => [1], 'windowMonths' => 3, 'includeScheduled' => true, 'accountIdsAreDefault' => true],
		];
	}

	public function testShowReturnsTheSerializedShape(): void {
		$this->service->expects($this->once())->method('get')->with('user1')->willReturn(self::runwayResult());

		$response = $this->controller->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('GBP', $data['currency']);
		$this->assertSame('50.00', $data['total']);
		$this->assertSame('not_running_out', $data['scenarios']['current']['state']);
		$this->assertArrayNotHasKey('series', $data);
	}

	public function testAFailureIsReportedLikeTheOtherV1Routes(): void {
		$this->service->method('get')->willThrowException(new \RuntimeException('db'));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->show()->getStatus());
	}

	public function testUnauthenticatedConstructionDoesNotFatal(): void {
		$controller = new ApiV1RunwayController(
			$this->createMock(IRequest::class), $this->service,
			$this->createMock(IL10N::class), null, $this->createMock(LoggerInterface::class)
		);

		$this->assertInstanceOf(ApiV1RunwayController::class, $controller);
	}
}
