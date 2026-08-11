<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ReceiptScanController;
use OCA\Budget\Service\Ocr\OcrNotConfiguredException;
use OCA\Budget\Service\Ocr\OcrProviderException;
use OCA\Budget\Service\Ocr\OcrQuotaExhaustedException;
use OCA\Budget\Service\Ocr\ReceiptExtractionService;
use OCA\Budget\Service\OcrSettingsService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The web UI's own scan endpoint. It shares the extraction service with the
 * public v1 route but not its response shape or status codes — the v1 codes
 * are a contract with outside clients, these are for one known caller.
 */
class ReceiptScanControllerTest extends TestCase {
	private ReceiptScanController $controller;
	private ReceiptExtractionService $extractionService;
	private OcrSettingsService $settings;
	private IRequest $request;

	private array $uploads = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getUploadedFile')
			->willReturnCallback(fn (string $key) => $this->uploads[$key] ?? null);

		$this->extractionService = $this->createMock(ReceiptExtractionService::class);
		$this->settings = $this->createMock(OcrSettingsService::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new ReceiptScanController(
			$this->request,
			$this->extractionService,
			$this->settings,
			$l,
			'user1',
			$this->createMock(LoggerInterface::class)
		);
	}

	private function draft(array $overrides = []): array {
		return $overrides + [
			'merchant' => 'The Corner Deli',
			'date' => '2026-08-05',
			'currency' => 'GBP',
			'total' => '23.77',
			'lineItems' => [['description' => 'Flat White', 'amount' => '3.40']],
			'suggestedCategoryId' => 16,
			'suggestedCategoryName' => 'Groceries',
			'warnings' => [],
		];
	}

	private function withImage(string $field = 'image'): void {
		$this->uploads[$field] = ['name' => 'r.jpg', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 10];
	}

	// ── status ──────────────────────────────────────────────────────

	public function testStatusReportsWhetherTheServerCanScan(): void {
		// The button is hidden on an unconfigured server: an ordinary user
		// cannot see the admin setting, and a button that always errors is
		// worse than no button.
		$this->settings->method('isConfigured')->willReturn(true);

		$data = $this->controller->status()->getData();

		$this->assertTrue($data['available']);
		$this->assertSame(ReceiptExtractionService::EXTRACT_MIMES, $data['mimeTypes']);
	}

	public function testStatusReportsUnavailableWhenNoProviderIsConfigured(): void {
		$this->settings->method('isConfigured')->willReturn(false);

		$this->assertFalse($this->controller->status()->getData()['available']);
	}

	// ── extract ─────────────────────────────────────────────────────

	public function testExtractReturnsTheDraftForTheForm(): void {
		$this->withImage();
		$this->extractionService->method('extract')->willReturn($this->draft());

		$response = $this->controller->extract();
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// Internal shape: the JS reads these names directly, no v1 mapping.
		$this->assertSame('The Corner Deli', $data['merchant']);
		$this->assertSame('23.77', $data['total']);
		$this->assertSame(16, $data['suggestedCategoryId']);
	}

	public function testMissingFileIsABadRequest(): void {
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->extract()->getStatus());
	}

	public function testADraftWithoutATotalIsAFailureNotAPartialFill(): void {
		// Filling in everything except the amount would look like success.
		$this->withImage();
		$this->extractionService->method('extract')->willReturn($this->draft(['total' => null]));

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertArrayHasKey('error', $response->getData());
	}

	public function testAnUnconfiguredServerIsAPreconditionFailure(): void {
		$this->withImage();
		$this->extractionService->method('extract')
			->willThrowException(new OcrNotConfiguredException('off'));

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $this->controller->extract()->getStatus());
	}

	public function testAnExhaustedQuotaIsReportedSeparately(): void {
		$this->withImage();
		$this->extractionService->method('extract')
			->willThrowException(new OcrQuotaExhaustedException('meter empty'));

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $this->controller->extract()->getStatus());
	}

	public function testAProviderFailureDoesNotLeakItsDetail(): void {
		$this->withImage();
		$this->extractionService->method('extract')
			->willThrowException(new OcrProviderException('cURL error 7 to http://ollama.lan:11434'));

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertStringNotContainsString('ollama.lan', $response->getData()['error']);
	}

	public function testAnUnusableUploadSurfacesItsReason(): void {
		$this->withImage();
		$this->extractionService->method('extract')
			->willThrowException(new \InvalidArgumentException('Receipt extraction accepts JPEG, PNG or WebP images'));

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('JPEG', $response->getData()['error']);
	}

	public function testTheFileFieldIsAcceptedAsWellAsImage(): void {
		$this->withImage('file');
		$this->extractionService->method('extract')->willReturn($this->draft());

		$this->assertSame(Http::STATUS_OK, $this->controller->extract()->getStatus());
	}
}
