<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\MigrationController;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\MigrationService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IL10N;
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
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		$this->controller = new MigrationController(
			$this->request,
			$this->migrationService,
			$this->auditService,
			$l,
			'user1',
			$this->logger
		);
	}

	// ── export ──────────────────────────────────────────────────────

	public function testExportReturnsDownloadResponse(): void {
		$this->migrationService->method('exportAll')
			->with('user1')
			->willReturn([
				'content' => 'zipdata',
				'filename' => 'budget_export_2026-03-09.zip',
				'contentType' => 'application/zip',
			]);

		$response = $this->controller->export();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
	}

	public function testExportLogsAuditEvent(): void {
		$this->migrationService->method('exportAll')
			->willReturn([
				'content' => 'zipdata',
				'filename' => 'budget_export.zip',
				'contentType' => 'application/zip',
			]);

		$this->auditService->expects($this->once())
			->method('log')
			->with(
				'user1',
				'data_export',
				'migration',
				0,
				['filename' => 'budget_export.zip']
			);

		$this->controller->export();
	}

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

	public function testPreviewReturnsPreviewData(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmpFile, 'fake zip content');

		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => 'data.zip',
			'tmp_name' => $tmpFile,
			'error' => UPLOAD_ERR_OK,
		]);

		$previewData = [
			'valid' => true,
			'manifest' => ['version' => '1.0.0'],
			'counts' => ['accounts' => 2, 'transactions' => 50],
			'warnings' => [],
		];
		$this->migrationService->method('previewImport')
			->willReturn($previewData);

		$response = $this->controller->preview();

		@unlink($tmpFile);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($previewData, $response->getData());
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

	public function testPreviewHandlesGenericException(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmpFile, 'fake zip content');

		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => 'data.zip',
			'tmp_name' => $tmpFile,
			'error' => UPLOAD_ERR_OK,
		]);
		$this->migrationService->method('previewImport')
			->willThrowException(new \RuntimeException('Unexpected error'));

		$response = $this->controller->preview();

		@unlink($tmpFile);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
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

	public function testImportSuccessReturnsResult(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmpFile, 'fake zip content');

		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => 'data.zip',
			'tmp_name' => $tmpFile,
			'error' => UPLOAD_ERR_OK,
		]);
		$this->request->method('getParam')->with('confirmed', false)->willReturn(true);

		$importResult = [
			'success' => true,
			'counts' => ['categories' => 5, 'accounts' => 2, 'transactions' => 100],
		];
		$this->migrationService->method('importAll')
			->with('user1', $this->anything())
			->willReturn($importResult);

		$this->auditService->expects($this->exactly(2))->method('log');

		$response = $this->controller->import();

		@unlink($tmpFile);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($importResult, $response->getData());
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

	public function testImportHandlesGenericException(): void {
		$tmpFile = tempnam(sys_get_temp_dir(), 'budget_test_');
		file_put_contents($tmpFile, 'fake zip content');

		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => 'data.zip',
			'tmp_name' => $tmpFile,
			'error' => UPLOAD_ERR_OK,
		]);
		$this->request->method('getParam')->with('confirmed', false)->willReturn(true);
		$this->migrationService->method('importAll')
			->willThrowException(new \RuntimeException('DB connection lost'));

		$this->auditService->expects($this->exactly(2))->method('log');

		$response = $this->controller->import();

		@unlink($tmpFile);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
