<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ApiV1ReceiptController;
use OCA\Budget\Service\Ocr\OcrNotConfiguredException;
use OCA\Budget\Service\Ocr\OcrProviderException;
use OCA\Budget\Service\Ocr\OcrQuotaExhaustedException;
use OCA\Budget\Service\Ocr\ReceiptExtractionService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

class ApiV1ReceiptControllerTest extends TestCase {
	private ApiV1ReceiptController $controller;
	private ReceiptExtractionService $extractionService;
	private IRequest $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->extractionService = $this->createMock(ReceiptExtractionService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(static fn (string $text) => $text);

		$this->controller = new ApiV1ReceiptController(
			$this->request,
			$this->extractionService,
			$l,
			'user1'
		);
	}

	private function withUpload(string $field = 'image'): array {
		$upload = ['name' => 'r.jpg', 'tmp_name' => '/tmp/x', 'error' => UPLOAD_ERR_OK, 'size' => 10];
		$this->request->method('getUploadedFile')
			->willReturnCallback(fn (string $key) => $key === $field ? $upload : null);

		return $upload;
	}

	public function testExtractReturnsTheSerializedDraft(): void {
		$this->withUpload();
		$this->extractionService->method('extract')->willReturn([
			'merchant' => 'Shop', 'date' => '2026-08-01', 'currency' => null,
			'total' => '5.00', 'lineItems' => [],
			'suggestedCategoryId' => null, 'suggestedCategoryName' => null,
			'warnings' => [],
		]);

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Shop', $response->getData()['merchant']);
		$this->assertSame('5.00', $response->getData()['total']);
	}

	public function testMissingFileIsABadRequest(): void {
		$this->request->method('getUploadedFile')->willReturn(null);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->extract()->getStatus());
	}

	public function testNoProviderConfiguredIs412WithItsMachineCode(): void {
		// The handoff contract: the app switches on error_code, never prose.
		$this->withUpload();
		$this->extractionService->method('extract')
			->willThrowException(new OcrNotConfiguredException('off'));

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_PRECONDITION_FAILED, $response->getStatus());
		$this->assertSame('ocr_not_configured', $response->getData()['error_code']);
	}

	public function testProviderFailureIs422WithItsMachineCode(): void {
		$this->withUpload();
		$this->extractionService->method('extract')
			->willThrowException(new OcrProviderException('unreachable'));

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('ocr_extraction_failed', $response->getData()['error_code']);
		// The internal detail (endpoint, curl error) must not leak to clients.
		$this->assertStringNotContainsString('unreachable', $response->getData()['error']);
	}

	public function testAnExhaustedQuotaIs429WithItsMachineCode(): void {
		$this->withUpload();
		$this->extractionService->method('extract')
			->willThrowException(new OcrQuotaExhaustedException('meter empty'));

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_TOO_MANY_REQUESTS, $response->getStatus());
		$this->assertSame('ocr_quota_exhausted', $response->getData()['error_code']);
	}

	public function testADraftWithoutATotalIsAnExtractionFailure(): void {
		// total is the one field the handoff contract requires in a draft.
		$this->withUpload();
		$this->extractionService->method('extract')->willReturn([
			'merchant' => 'Shop', 'date' => '2026-08-01', 'currency' => null,
			'total' => null, 'lineItems' => [],
			'suggestedCategoryId' => null, 'suggestedCategoryName' => null,
			'warnings' => ['no-total'],
		]);

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_UNPROCESSABLE_ENTITY, $response->getStatus());
		$this->assertSame('ocr_extraction_failed', $response->getData()['error_code']);
	}

	public function testTheLegacyFileFieldIsStillAccepted(): void {
		$this->withUpload('file');
		$this->extractionService->method('extract')->willReturn([
			'merchant' => 'Shop', 'date' => null, 'currency' => null,
			'total' => '5.00', 'lineItems' => [],
			'suggestedCategoryId' => null, 'suggestedCategoryName' => null,
			'warnings' => [],
		]);

		$this->assertSame(Http::STATUS_OK, $this->controller->extract()->getStatus());
	}

	public function testAnUnusableUploadIsABadRequest(): void {
		$this->withUpload();
		$this->extractionService->method('extract')
			->willThrowException(new \InvalidArgumentException('Receipt extraction accepts JPEG, PNG or WebP images'));

		$response = $this->controller->extract();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUnauthenticatedConstructionDoesNotFatal(): void {
		// OCS instantiates controllers before the auth check — see ApiV1Controller.
		$controller = new ApiV1ReceiptController(
			$this->createMock(IRequest::class),
			$this->extractionService,
			$this->createMock(IL10N::class),
			null
		);

		$this->assertInstanceOf(ApiV1ReceiptController::class, $controller);
	}
}
