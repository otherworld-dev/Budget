<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\AdminSettingController;
use OCA\Budget\Service\AdminSettingService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class AdminSettingControllerTest extends TestCase {
	private AdminSettingController $controller;
	private AdminSettingService $service;
	private IRequest $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(AdminSettingService::class);

		$this->controller = new AdminSettingController(
			$this->request,
			$this->service
		);
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsAllSettings(): void {
		$settings = ['bankSyncEnabled' => true];
		$this->service->method('getAll')->willReturn($settings);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($settings, $response->getData());
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateWithBankSyncEnabledCallsService(): void {
		$this->request->method('getParams')->willReturn([
			'bankSyncEnabled' => true,
		]);

		$this->service->expects($this->once())
			->method('setBankSyncEnabled')
			->with(true);

		$updatedSettings = ['bankSyncEnabled' => true];
		$this->service->method('getAll')->willReturn($updatedSettings);

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($updatedSettings, $response->getData());
	}

	public function testUpdateWithoutRelevantParamsDoesNotCallService(): void {
		$this->request->method('getParams')->willReturn([
			'someOtherKey' => 'value',
		]);

		$this->service->expects($this->never())
			->method('setBankSyncEnabled');

		$settings = ['bankSyncEnabled' => false];
		$this->service->method('getAll')->willReturn($settings);

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($settings, $response->getData());
	}

	public function testUpdateCastsBankSyncEnabledToBoolean(): void {
		$this->request->method('getParams')->willReturn([
			'bankSyncEnabled' => '0',
		]);

		$this->service->expects($this->once())
			->method('setBankSyncEnabled')
			->with(false);

		$this->service->method('getAll')->willReturn(['bankSyncEnabled' => false]);

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateReturnsCurrentSettingsAfterUpdate(): void {
		$this->request->method('getParams')->willReturn([
			'bankSyncEnabled' => true,
		]);

		$this->service->method('setBankSyncEnabled');

		$expected = ['bankSyncEnabled' => true, 'otherSetting' => 'value'];
		$this->service->method('getAll')->willReturn($expected);

		$response = $this->controller->update();

		$this->assertSame($expected, $response->getData());
	}
}
