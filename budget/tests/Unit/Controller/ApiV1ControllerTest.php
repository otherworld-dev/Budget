<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ApiV1Controller;
use OCA\Budget\Service\AttachmentService;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\Ocr\ReceiptExtractionService;
use OCA\Budget\Service\OcrSettingsService;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class ApiV1ControllerTest extends TestCase {
	private ApiV1Controller $controller;
	private IAppManager $appManager;
	private CurrencyConversionService $conversionService;
	private OcrSettingsService $ocrSettings;

	protected function setUp(): void {
		$this->appManager = $this->createMock(IAppManager::class);
		$this->conversionService = $this->createMock(CurrencyConversionService::class);
		$this->conversionService->method('getBaseCurrency')->willReturn('GBP');
		$this->ocrSettings = $this->createMock(OcrSettingsService::class);

		$this->controller = new ApiV1Controller(
			$this->createMock(IRequest::class),
			$this->appManager,
			$this->conversionService,
			$this->ocrSettings,
			'user1'
		);
	}

	public function testInfoReportsVersionAndUser(): void {
		$this->appManager->method('getAppVersion')->willReturn('2.40.0');

		$response = $this->controller->info();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('1.0', $data['api_version']);
		$this->assertSame('2.40.0', $data['app_version']);
		$this->assertSame('user1', $data['user_id']);
		$this->assertSame('GBP', $data['base_currency']);
	}

	public function testInfoAdvertisesTheLimitsTheUploadPathActuallyEnforces(): void {
		$this->appManager->method('getAppVersion')->willReturn('2.40.0');

		$limits = $this->controller->info()->getData()['limits'];

		$this->assertSame(AttachmentService::MAX_SIZE, $limits['max_receipt_bytes']);
		$this->assertSame(AttachmentService::ALLOWED_MIMES, $limits['receipt_mime_types']);
		$this->assertSame(ReceiptExtractionService::EXTRACT_MIMES, $limits['receipt_ocr_mime_types']);
	}

	public function testInfoReportsOcrFromTheConfiguredState(): void {
		// This flag is what makes the capture flow appear in clients — it must
		// track the server's actual ability to serve an extraction request.
		$this->appManager->method('getAppVersion')->willReturn('2.40.0');
		$this->ocrSettings->method('isConfigured')->willReturn(true);

		$features = $this->controller->info()->getData()['features'];

		$this->assertTrue($features['receipt_ocr']);
		$this->assertTrue($features['receipt_upload']);
		$this->assertTrue($features['create_transaction']);
	}

	public function testInfoReportsOcrOffWhenNoProviderIsConfigured(): void {
		$this->appManager->method('getAppVersion')->willReturn('2.40.0');
		$this->ocrSettings->method('isConfigured')->willReturn(false);

		$this->assertFalse($this->controller->info()->getData()['features']['receipt_ocr']);
	}

	public function testInfoSurvivesAnUnresolvableAppVersion(): void {
		$this->appManager->method('getAppVersion')
			->willThrowException(new \RuntimeException('app not found'));

		$response = $this->controller->info();

		// The version is advisory; failing to read it must not take out the
		// endpoint a client uses to decide whether the API is usable at all.
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertNull($response->getData()['app_version']);
	}

	public function testCapabilitiesIsExactlyTheHandoffShape(): void {
		$this->appManager->method('getAppVersion')->willReturn('2.41.0');
		$this->ocrSettings->method('isConfigured')->willReturn(true);

		$data = $this->controller->capabilities()->getData();

		// The capture app's first call — three keys, exactly these names.
		$this->assertSame([
			'ocr_available' => true,
			'currency' => 'GBP',
			'version' => '2.41.0',
		], $data);
	}

	public function testUnauthenticatedConstructionDoesNotFatal(): void {
		// OCS instantiates the controller before the security middleware runs
		// its auth check, so a null user must be survivable — otherwise the
		// TypeError replaces a clean 401 with an empty 200.
		$controller = new ApiV1Controller(
			$this->createMock(IRequest::class),
			$this->appManager,
			$this->conversionService,
			$this->ocrSettings,
			null
		);

		$this->assertInstanceOf(ApiV1Controller::class, $controller);
	}
}
