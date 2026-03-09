<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\MigrationController;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\MigrationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class MigrationControllerTest extends TestCase {
	private MigrationController $controller;
	private MigrationService $migrationService;
	private AuditService $auditService;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->migrationService = $this->createMock(MigrationService::class);
		$this->auditService = $this->createMock(AuditService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new MigrationController(
			$this->request,
			$this->migrationService,
			$this->auditService,
			'user1',
			$this->logger
		);
	}

	// ── export ──────────────────────────────────────────────────────

	public function testExportHandlesError(): void {
		$this->migrationService->method('exportAll')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->export();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── preview ─────────────────────────────────────────────────────

	public function testPreviewReturnsErrorWhenNoFile(): void {
		$this->request->method('getUploadedFile')->with('file')->willReturn(null);

		$response = $this->controller->preview();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No file uploaded', $response->getData()['error']);
	}

	public function testPreviewReturnsErrorOnUploadFailure(): void {
		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => 'data.zip',
			'tmp_name' => '/tmp/data.zip',
			'error' => UPLOAD_ERR_INI_SIZE,
		]);

		$response = $this->controller->preview();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('File upload failed', $response->getData()['error']);
	}

	public function testPreviewHandlesInvalidArgumentException(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmpFile, 'fake zip content');

		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => 'data.zip',
			'tmp_name' => $tmpFile,
			'error' => UPLOAD_ERR_OK,
		]);
		$this->migrationService->method('previewImport')
			->willThrowException(new \InvalidArgumentException('Invalid format'));

		$response = $this->controller->preview();

		@unlink($tmpFile);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid format', $response->getData()['error']);
		$this->assertFalse($response->getData()['valid']);
	}

	// ── import ──────────────────────────────────────────────────────

	public function testImportReturnsErrorWhenNoFile(): void {
		$this->request->method('getUploadedFile')->with('file')->willReturn(null);

		$response = $this->controller->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No file uploaded', $response->getData()['error']);
	}

	public function testImportReturnsErrorOnUploadFailure(): void {
		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => 'data.zip',
			'tmp_name' => '/tmp/data.zip',
			'error' => UPLOAD_ERR_INI_SIZE,
		]);

		$response = $this->controller->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('File upload failed', $response->getData()['error']);
	}

	public function testImportRequiresConfirmation(): void {
		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => 'data.zip',
			'tmp_name' => '/tmp/data.zip',
			'error' => UPLOAD_ERR_OK,
		]);
		$this->request->method('getParam')->with('confirmed', false)->willReturn(false);

		$response = $this->controller->import();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('not confirmed', $response->getData()['error']);
	}

	public function testImportHandlesInvalidArgumentException(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmpFile, 'fake zip content');

		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => 'data.zip',
			'tmp_name' => $tmpFile,
			'error' => UPLOAD_ERR_OK,
		]);
		$this->request->method('getParam')->with('confirmed', false)->willReturn(true);
		$this->migrationService->method('importAll')
			->willThrowException(new \InvalidArgumentException('Invalid data'));

		$response = $this->controller->import();

		@unlink($tmpFile);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid data', $response->getData()['error']);
	}
}
