<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ImportController;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\ImportService;
use OCP\AppFramework\Http;
use OCP\Files\IAppData;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ImportControllerTest extends TestCase {
	private ImportController $controller;
	private ImportService $service;
	private AuditService $auditService;
	private IAppData $appData;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(ImportService::class);
		$this->auditService = $this->createMock(AuditService::class);
		$this->appData = $this->createMock(IAppData::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		$this->controller = new ImportController(
			$this->request,
			$this->service,
			$this->auditService,
			$this->appData,
			$l,
			'user1',
			$this->logger
		);
	}

	// ── upload ──────────────────────────────────────────────────────

	public function testUploadReturnsErrorWhenNoFile(): void {
		$this->request->method('getUploadedFile')->with('file')->willReturn(null);

		$response = $this->controller->upload();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No file uploaded', $response->getData()['error']);
	}

	public function testUploadReturnsErrorOnUploadFailure(): void {
		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => 'test.csv',
			'tmp_name' => '/tmp/test.csv',
			'error' => UPLOAD_ERR_INI_SIZE,
		]);

		$response = $this->controller->upload();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('File upload failed', $response->getData()['error']);
	}

	// ── preview ─────────────────────────────────────────────────────

	public function testPreviewReturnsData(): void {
		$preview = ['rows' => 10, 'columns' => ['date', 'amount']];
		$this->service->method('previewImport')
			->with('user1', 'file123', [], null, null, true, ',')
			->willReturn($preview);

		$response = $this->controller->preview('file123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($preview, $response->getData());
	}

	public function testPreviewHandlesError(): void {
		$this->service->method('previewImport')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->preview('file123');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── process ─────────────────────────────────────────────────────

	public function testProcessReturnsData(): void {
		$result = ['imported' => 5, 'skipped' => 1, 'accountResults' => []];
		$this->service->method('processImport')
			->with('user1', 'file123', [], null, null, true, true, ',')
			->willReturn($result);

		$response = $this->controller->process('file123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testProcessHandlesError(): void {
		$this->service->method('processImport')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->process('file123');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── templates ───────────────────────────────────────────────────

	public function testTemplatesReturnsData(): void {
		$templates = [['name' => 'Bank CSV', 'format' => 'csv']];
		$this->service->method('getImportTemplates')->willReturn($templates);

		$response = $this->controller->templates();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($templates, $response->getData());
	}

	public function testTemplatesHandlesError(): void {
		$this->service->method('getImportTemplates')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->templates();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── history ─────────────────────────────────────────────────────

	public function testHistoryReturnsData(): void {
		$history = [['id' => 1, 'date' => '2026-01-01']];
		$this->service->method('getImportHistory')
			->with('user1', 50)
			->willReturn($history);

		$response = $this->controller->history();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($history, $response->getData());
	}

	public function testHistoryHandlesError(): void {
		$this->service->method('getImportHistory')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->history();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── validateFile ────────────────────────────────────────────────

	public function testValidateFileReturnsData(): void {
		$validation = ['valid' => true, 'format' => 'csv'];
		$this->service->method('validateFile')
			->with('user1', 'file123')
			->willReturn($validation);

		$response = $this->controller->validateFile('file123');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($validation, $response->getData());
	}

	public function testValidateFileHandlesError(): void {
		$this->service->method('validateFile')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->validateFile('file123');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── execute ─────────────────────────────────────────────────────

	public function testExecuteReturnsData(): void {
		$result = ['imported' => 3];
		$this->service->method('executeImport')
			->with('user1', 'import123', 1, [1, 2, 3])
			->willReturn($result);

		$response = $this->controller->execute('import123', 1, [1, 2, 3]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testExecuteHandlesError(): void {
		$this->service->method('executeImport')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->execute('import123', 1, [1]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── rollback ────────────────────────────────────────────────────

	public function testRollbackReturnsData(): void {
		$result = ['rolledBack' => 5];
		$this->service->method('rollbackImport')
			->with('user1', 1)
			->willReturn($result);

		$response = $this->controller->rollback(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($result, $response->getData());
	}

	public function testRollbackHandlesError(): void {
		$this->service->method('rollbackImport')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->rollback(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
