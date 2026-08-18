<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\AdminSettingController;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\OcrSettingsService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class AdminSettingControllerTest extends TestCase {
	private AdminSettingController $controller;
	private AdminSettingService $service;
	private OcrSettingsService $ocrSettings;
	private IRequest $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(AdminSettingService::class);
		$this->ocrSettings = $this->createMock(OcrSettingsService::class);
		$this->ocrSettings->method('getSettings')->willReturn(['provider' => 'none']);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text) => $text);

		$this->controller = new AdminSettingController(
			$this->request,
			$this->service,
			$this->ocrSettings,
			$l
		);
	}

	// ── ocrPortal ───────────────────────────────────────────────────

	public function testPortalReturnsTheUrl(): void {
		$this->ocrSettings->method('createPortalUrl')->willReturn('https://billing.stripe.com/s/1');

		$response = $this->controller->ocrPortal();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['url' => 'https://billing.stripe.com/s/1'], $response->getData());
	}

	public function testPortalMapsCodesToStatusesAndProse(): void {
		$cases = [
			['not_relay', Http::STATUS_BAD_REQUEST],
			['no_key', Http::STATUS_BAD_REQUEST],
			['no_subscription', Http::STATUS_NOT_FOUND],
			['unavailable', Http::STATUS_BAD_GATEWAY],
		];
		foreach ($cases as [$code, $status]) {
			$ocr = $this->createMock(OcrSettingsService::class);
			$ocr->method('createPortalUrl')->willThrowException(new \RuntimeException($code));
			$l = $this->createMock(IL10N::class);
			$l->method('t')->willReturnCallback(fn (string $text) => $text);
			$controller = new AdminSettingController($this->request, $this->service, $ocr, $l);

			$response = $controller->ocrPortal();

			$this->assertSame($status, $response->getStatus(), $code);
			$error = $response->getData()['error'];
			$this->assertIsString($error, $code);
			// Machine codes must never surface as the user-facing message.
			$this->assertNotSame($code, $error, $code);
		}
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsAllSettings(): void {
		$this->service->method('getAll')->willReturn(['bankSyncEnabled' => true]);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			['bankSyncEnabled' => true, 'ocr' => ['provider' => 'none']],
			$response->getData()
		);
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateWithBankSyncEnabledCallsService(): void {
		$this->request->method('getParams')->willReturn([
			'bankSyncEnabled' => true,
		]);

		$this->service->expects($this->once())
			->method('setBankSyncEnabled')
			->with(true);

		$this->service->method('getAll')->willReturn(['bankSyncEnabled' => true]);

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['bankSyncEnabled']);
	}

	public function testUpdateWithoutRelevantParamsDoesNotCallService(): void {
		$this->request->method('getParams')->willReturn([
			'someOtherKey' => 'value',
		]);

		$this->service->expects($this->never())
			->method('setBankSyncEnabled');

		$this->service->method('getAll')->willReturn(['bankSyncEnabled' => false]);

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertFalse($response->getData()['bankSyncEnabled']);
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

		$this->service->method('getAll')->willReturn(['bankSyncEnabled' => true, 'otherSetting' => 'value']);

		$response = $this->controller->update();

		$this->assertSame(
			['bankSyncEnabled' => true, 'otherSetting' => 'value', 'ocr' => ['provider' => 'none']],
			$response->getData()
		);
	}

	// ── receipt scanning (OCR) ──────────────────────────────────────

	public function testUpdatePassesOcrFieldsToTheOcrService(): void {
		$this->request->method('getParams')->willReturn([
			'ocr' => ['provider' => 'custom', 'endpoint' => 'http://ollama.lan:11434/v1', 'model' => 'qwen2.5vl'],
		]);
		$this->service->method('getAll')->willReturn([]);

		$this->ocrSettings->expects($this->once())
			->method('update')
			->with(['provider' => 'custom', 'endpoint' => 'http://ollama.lan:11434/v1', 'model' => 'qwen2.5vl']);

		$this->assertSame(Http::STATUS_OK, $this->controller->update()->getStatus());
	}

	public function testUpdateDropsUnknownOcrFields(): void {
		$this->request->method('getParams')->willReturn([
			'ocr' => ['provider' => 'relay', 'configured' => true, 'apiKeySet' => true],
		]);
		$this->service->method('getAll')->willReturn([]);

		// configured/apiKeySet are reported by the server, never set by the
		// client — accepting them back would let the UI lie about its state.
		$this->ocrSettings->expects($this->once())
			->method('update')
			->with(['provider' => 'relay']);

		$this->controller->update();
	}

	public function testUpdateAnswersBadRequestWhenTheOcrValuesAreRejected(): void {
		$this->request->method('getParams')->willReturn([
			'ocr' => ['provider' => 'custom', 'endpoint' => 'not-a-url'],
		]);
		$this->service->method('getAll')->willReturn([]);

		$this->ocrSettings->method('update')
			->willThrowException(new \InvalidArgumentException('The endpoint must be a valid http:// or https:// URL'));

		$response = $this->controller->update();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			'The endpoint must be a valid http:// or https:// URL',
			$response->getData()['error']
		);
	}

	public function testUpdateDoesNotApplyBankSyncWhenTheOcrValuesAreRejected(): void {
		$this->request->method('getParams')->willReturn([
			'bankSyncEnabled' => true,
			'ocr' => ['provider' => 'custom', 'endpoint' => 'not-a-url'],
		]);

		$this->ocrSettings->method('update')
			->willThrowException(new \InvalidArgumentException('The endpoint must be a valid http:// or https:// URL'));

		// The 400 says "nothing was saved" — so nothing may be, including the
		// bank-sync flag riding in the same payload.
		$this->service->expects($this->never())->method('setBankSyncEnabled');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->update()->getStatus());
	}

	public function testUpdateIgnoresANonArrayOcrValue(): void {
		$this->request->method('getParams')->willReturn(['ocr' => 'custom']);
		$this->service->method('getAll')->willReturn([]);

		$this->ocrSettings->expects($this->never())->method('update');

		$this->assertSame(Http::STATUS_OK, $this->controller->update()->getStatus());
	}
}
