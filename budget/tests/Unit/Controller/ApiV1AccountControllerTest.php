<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ApiV1AccountController;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\GranularShareService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiV1AccountControllerTest extends TestCase {
	private ApiV1AccountController $controller;
	private AccountService $service;
	private GranularShareService $granularShareService;

	protected function setUp(): void {
		$this->service = $this->createMock(AccountService::class);
		$this->granularShareService = $this->createMock(GranularShareService::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$this->controller = new ApiV1AccountController(
			$this->createMock(IRequest::class),
			$this->service,
			$this->granularShareService,
			$l,
			'user1',
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testIndexReturnsOwnAndSharedAccounts(): void {
		$this->service->method('findAllWithCurrentBalances')->with('user1')->willReturn([
			['id' => 1, 'name' => 'Current', 'type' => 'checking', 'currency' => 'GBP', 'balance' => 100.0],
		]);
		$this->granularShareService->method('getSharedAccounts')->with('user1')->willReturn([
			['id' => 9, 'name' => 'Joint', 'type' => 'checking', 'currency' => 'GBP', 'balance' => 50.0, '_shared' => true],
		]);

		$response = $this->controller->index();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $data);
		$this->assertFalse($data[0]['shared']);
		$this->assertTrue($data[1]['shared']);
	}

	public function testIndexReturnsSerializedShapeNotRawRows(): void {
		$this->service->method('findAllWithCurrentBalances')->willReturn([
			['id' => 1, 'name' => 'Current', 'iban' => 'GB33BUKB20201555555555', 'userId' => 'user1'],
		]);
		$this->granularShareService->method('getSharedAccounts')->willReturn([]);

		$data = $this->controller->index()->getData();

		$this->assertArrayNotHasKey('iban', $data[0]);
		$this->assertArrayNotHasKey('userId', $data[0]);
	}

	public function testIndexWithNoAccounts(): void {
		$this->service->method('findAllWithCurrentBalances')->willReturn([]);
		$this->granularShareService->method('getSharedAccounts')->willReturn([]);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData());
	}

	public function testIndexHandlesException(): void {
		$this->service->method('findAllWithCurrentBalances')
			->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Failed to retrieve accounts', $response->getData()['error']);
	}

	public function testUnauthenticatedConstructionDoesNotFatal(): void {
		$controller = new ApiV1AccountController(
			$this->createMock(IRequest::class),
			$this->service,
			$this->granularShareService,
			$this->createMock(IL10N::class),
			null,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertInstanceOf(ApiV1AccountController::class, $controller);
	}
}
