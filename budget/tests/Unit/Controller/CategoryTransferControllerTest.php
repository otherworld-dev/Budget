<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\CategoryTransferController;
use OCA\Budget\Service\CategoryTransferService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CategoryTransferControllerTest extends TestCase {
	private CategoryTransferController $controller;
	private CategoryTransferService $service;

	protected function setUp(): void {
		$this->service = $this->createMock(CategoryTransferService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text) => $text);

		$this->controller = new CategoryTransferController(
			$this->createMock(IRequest::class),
			$this->service,
			$l,
			'user1',
			$this->createMock(LoggerInterface::class)
		);
	}

	public function testExportReturnsPrettyJsonDownload(): void {
		$this->service->method('export')->with('user1')->willReturn([
			'app' => 'budget', 'type' => 'categories', 'version' => 1, 'exportedAt' => '2026-08-23T00:00:00+00:00',
			'categories' => [['name' => 'Café', 'type' => 'expense']],
		]);

		$response = $this->controller->export();

		$this->assertInstanceOf(DataDownloadResponse::class, $response);
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		// getHeaders() needs a booted server; read the raw header map instead
		$headersProp = new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers');
		$headersProp->setAccessible(true);
		$headers = $headersProp->getValue($response);
		$this->assertMatchesRegularExpression('/^attachment; filename="budget-categories-\d{4}-\d{2}-\d{2}\.json"$/', $headers['Content-Disposition']);
		$this->assertSame('application/json', $headers['Content-Type']);
		$body = $response->render();
		$this->assertStringContainsString("\n    \"categories\": [", $body, 'pretty-printed so it can be read and edited by hand');
		$this->assertStringContainsString('"name": "Café"', $body, 'unicode left as-is');
	}

	public function testPreviewReturnsPlanAndWarningsWithoutImporting(): void {
		$this->service->method('parse')->with("path\nFood", null)->willReturn([
			'categories' => [['name' => 'Food', 'type' => 'expense', 'children' => []]],
			'warnings' => ['"Food" has no type; imported as an expense category'],
		]);
		$this->service->method('plan')->with('user1', $this->anything())->willReturn([
			'categories' => [['name' => 'Food', 'type' => 'expense', 'action' => 'create', 'existingId' => null, 'children' => []]],
			'counts' => ['create' => 1, 'exists' => 0, 'total' => 1],
		]);
		$this->service->expects($this->never())->method('import');

		$response = $this->controller->preview("path\nFood");

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame('create', $data['categories'][0]['action']);
		$this->assertSame(['create' => 1, 'exists' => 0, 'total' => 1], $data['counts']);
		$this->assertSame(['"Food" has no type; imported as an expense category'], $data['warnings']);
	}

	public function testPreviewReportsUnreadableFileAs400WithTheReason(): void {
		$this->service->method('parse')->willThrowException(new \InvalidArgumentException('The file is not valid JSON'));

		$response = $this->controller->preview('{nope');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(['error' => 'The file is not valid JSON'], $response->getData());
	}

	public function testImportReturnsCountsAndWarnings(): void {
		$this->service->method('parse')->with('[...]', 'json')->willReturn([
			'categories' => [['name' => 'Food', 'type' => 'expense', 'children' => []]],
			'warnings' => [],
		]);
		$this->service->expects($this->once())->method('import')->with('user1', $this->anything())
			->willReturn(['created' => 3, 'skipped' => 1]);

		$response = $this->controller->import('[...]', 'json');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['created' => 3, 'skipped' => 1, 'warnings' => []], $response->getData());
	}

	public function testImportFailureIsGenericAndLogged(): void {
		$this->service->method('parse')->willReturn(['categories' => [['name' => 'X', 'type' => 'expense', 'children' => []]], 'warnings' => []]);
		$this->service->method('import')->willThrowException(new \RuntimeException('db down'));

		$response = $this->controller->import('[]');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Failed to import categories', $response->getData()['error']);
	}
}
