<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ApiV1CategoryController;
use OCA\Budget\Db\Category;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\GranularShareService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiV1CategoryControllerTest extends TestCase {
	private ApiV1CategoryController $controller;
	private CategoryService $service;
	private GranularShareService $granularShareService;

	protected function setUp(): void {
		$this->service = $this->createMock(CategoryService::class);
		$this->granularShareService = $this->createMock(GranularShareService::class);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$this->controller = new ApiV1CategoryController(
			$this->createMock(IRequest::class),
			$this->service,
			$this->granularShareService,
			$l,
			'user1',
			$this->createMock(LoggerInterface::class)
		);
	}

	private function category(int $id, string $name, string $type, ?int $parentId = null): Category {
		$category = new Category();
		$category->setId($id);
		$category->setName($name);
		$category->setType($type);
		$category->setParentId($parentId);

		return $category;
	}

	public function testIndexReturnsAllCategoriesWhenNoTypeGiven(): void {
		$this->service->expects($this->once())
			->method('findAll')
			->with('user1')
			->willReturn([
				$this->category(1, 'Food', 'expense'),
				$this->category(2, 'Salary', 'income'),
			]);
		$this->granularShareService->method('getSharedCategories')->willReturn([]);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $response->getData());
	}

	public function testIndexFiltersByType(): void {
		$this->service->expects($this->once())
			->method('findByType')
			->with('user1', 'expense')
			->willReturn([$this->category(1, 'Food', 'expense')]);
		$this->granularShareService->method('getSharedCategories')->willReturn([]);

		$data = $this->controller->index('expense')->getData();

		$this->assertCount(1, $data);
		$this->assertSame('expense', $data[0]['type']);
	}

	public function testIndexFiltersSharedCategoriesByTypeToo(): void {
		$this->service->method('findByType')->willReturn([$this->category(1, 'Food', 'expense')]);
		$this->granularShareService->method('getSharedCategories')->willReturn([
			['id' => 8, 'name' => 'Shared Food', 'type' => 'expense', '_shared' => true],
			['id' => 9, 'name' => 'Shared Salary', 'type' => 'income', '_shared' => true],
		]);

		$data = $this->controller->index('expense')->getData();

		$this->assertCount(2, $data);
		$this->assertSame([1, 8], array_column($data, 'id'));
	}

	public function testIndexMergesSharedCategoriesAsAList(): void {
		$this->service->method('findAll')->willReturn([$this->category(1, 'Food', 'expense')]);
		// Sparse keys survive array_filter; the response must still be a JSON
		// array rather than an object keyed by the surviving indices.
		$this->granularShareService->method('getSharedCategories')->willReturn([
			5 => ['id' => 8, 'name' => 'Shared Food', 'type' => 'expense', '_shared' => true],
		]);

		$data = $this->controller->index()->getData();

		$this->assertSame([0, 1], array_keys($data));
		$this->assertTrue($data[1]['shared']);
	}

	public function testIndexRejectsTransactionVocabulary(): void {
		// Categories are expense/income; debit/credit belongs to transactions.
		$response = $this->controller->index('debit');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid category type. Must be income or expense', $response->getData()['error']);
	}

	public function testIndexRejectsUnknownType(): void {
		$response = $this->controller->index('nonsense');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testIndexHandlesException(): void {
		$this->service->method('findAll')->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Failed to retrieve categories', $response->getData()['error']);
	}

	public function testUnauthenticatedConstructionDoesNotFatal(): void {
		$controller = new ApiV1CategoryController(
			$this->createMock(IRequest::class),
			$this->service,
			$this->granularShareService,
			$this->createMock(IL10N::class),
			null,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertInstanceOf(ApiV1CategoryController::class, $controller);
	}
}
