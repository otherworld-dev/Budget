<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\CategoryController;
use OCA\Budget\Db\Category;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CategoryControllerTest extends TestCase {
	private CategoryController $controller;
	private CategoryService $service;
	private ValidationService $validationService;
	private IRequest $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(CategoryService::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function (string $text, array $params = []) {
			foreach ($params as $i => $param) {
				$text = str_replace('%' . ($i + 1) . '$s', (string) $param, $text);
			}
			return $text;
		});
		$this->validationService = new ValidationService($l);
		$logger = $this->createMock(LoggerInterface::class);

		$this->controller = new CategoryController(
			$this->request,
			$this->service,
			$this->validationService,
			$l,
			'user1',
			$logger
		);
	}

	private function makeCategory(array $overrides = []): Category {
		$c = new Category();
		$c->setId($overrides['id'] ?? 1);
		$c->setUserId($overrides['userId'] ?? 'user1');
		$c->setName($overrides['name'] ?? 'Groceries');
		$c->setType($overrides['type'] ?? 'expense');
		return $c;
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsAllCategories(): void {
		$categories = [$this->makeCategory(), $this->makeCategory(['id' => 2, 'name' => 'Rent'])];
		$this->service->method('findAll')->with('user1')->willReturn($categories);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $response->getData());
	}

	public function testIndexFiltersByType(): void {
		$categories = [$this->makeCategory()];
		$this->service->method('findByType')->with('user1', 'expense')->willReturn($categories);

		$response = $this->controller->index('expense');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testIndexHandlesException(): void {
		$this->service->method('findAll')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Failed to retrieve categories', $response->getData()['error']);
	}

	// ── tree ────────────────────────────────────────────────────────

	public function testTreeReturnsCategoryTree(): void {
		$tree = [['id' => 1, 'name' => 'Food', 'children' => []]];
		$this->service->method('getCategoryTree')->with('user1')->willReturn($tree);

		$response = $this->controller->tree();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($tree, $response->getData());
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsCategory(): void {
		$category = $this->makeCategory();
		$this->service->method('find')->with(1, 'user1')->willReturn($category);

		$response = $this->controller->show(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testShowReturns404WhenNotFound(): void {
		$this->service->method('find')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Category not found', $response->getData()['error']);
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateValidCategory(): void {
		$category = $this->makeCategory();
		$this->service->method('create')->willReturn($category);

		$response = $this->controller->create('Groceries', 'expense');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateWithAllOptionalFields(): void {
		$category = $this->makeCategory();
		$this->service->expects($this->once())
			->method('create')
			->with('user1', 'Groceries', 'expense', 5, 'cart', '#ff0000', 500.0, 2)
			->willReturn($category);

		$response = $this->controller->create('Groceries', 'expense', 5, 'cart', '#ff0000', 500.0, 2);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateRejectsEmptyName(): void {
		$response = $this->controller->create('', 'expense');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('required', strtolower($response->getData()['error']));
	}

	public function testCreateRejectsInvalidType(): void {
		$response = $this->controller->create('Valid Name', 'invalid_type');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid category type', $response->getData()['error']);
	}

	public function testCreateAcceptsIncomeType(): void {
		$category = $this->makeCategory(['type' => 'income']);
		$this->service->method('create')->willReturn($category);

		$response = $this->controller->create('Salary', 'income');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateRejectsInvalidColor(): void {
		$response = $this->controller->create('Name', 'expense', null, null, 'not-a-color');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateAcceptsValidColor(): void {
		$category = $this->makeCategory();
		$this->service->method('create')->willReturn($category);

		$response = $this->controller->create('Name', 'expense', null, null, '#ff5500');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateHandlesServiceException(): void {
		$this->service->method('create')->willThrowException(new \RuntimeException('duplicate'));

		$response = $this->controller->create('Groceries', 'expense');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('duplicate', $response->getData()['error']);
	}

	public function testCreateSanitizesName(): void {
		$category = $this->makeCategory();
		// ValidationService trims and strips tags from names
		$this->service->expects($this->once())
			->method('create')
			->with(
				'user1',
				$this->callback(fn($v) => $v === 'Groceries'),
				'expense',
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything(),
				$this->anything()
			)
			->willReturn($category);

		$response = $this->controller->create('  Groceries  ', 'expense');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateWithName(): void {
		$category = $this->makeCategory(['name' => 'Updated']);
		$this->service->method('update')->willReturn($category);

		$response = $this->controller->update(1, 'Updated');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateRejectsInvalidType(): void {
		$response = $this->controller->update(1, null, 'bogus');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid category type', $response->getData()['error']);
	}

	public function testUpdateRejectsInvalidColor(): void {
		$response = $this->controller->update(1, null, null, null, null, 'xyz');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateRejectsInvalidBudgetPeriod(): void {
		$response = $this->controller->update(1, null, null, null, null, null, null, 'biweekly');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid budget period', $response->getData()['error']);
	}

	public function testUpdateAcceptsValidBudgetPeriod(): void {
		$category = $this->makeCategory();
		$this->service->method('update')->willReturn($category);

		$response = $this->controller->update(1, null, null, null, null, null, null, 'quarterly');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateRejectsEmptyUpdates(): void {
		// All params null → no fields to update
		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No valid fields to update', $response->getData()['error']);
	}

	public function testUpdateMultipleFields(): void {
		$category = $this->makeCategory();
		$this->service->expects($this->once())
			->method('update')
			->with(1, 'user1', $this->callback(function ($updates) {
				return isset($updates['name']) && isset($updates['type']) && isset($updates['budgetPeriod']);
			}))
			->willReturn($category);

		$response = $this->controller->update(1, 'New Name', 'income', null, null, null, null, 'yearly');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroySuccess(): void {
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	public function testDestroyHandlesException(): void {
		$this->service->method('delete')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── allSpending ─────────────────────────────────────────────────

	public function testAllSpendingReturnsData(): void {
		$spending = [['category' => 'Food', 'total' => 500.0]];
		$this->service->method('getAllCategorySpending')
			->with('user1', '2025-01-01', '2025-03-31')
			->willReturn($spending);

		$response = $this->controller->allSpending('2025-01-01', '2025-03-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($spending, $response->getData());
	}

	// ── spending ────────────────────────────────────────────────────

	public function testSpendingReturnsAmountForCategory(): void {
		$this->service->method('getCategorySpending')
			->with(1, 'user1', '2025-01-01', '2025-03-31')
			->willReturn(350.0);

		$response = $this->controller->spending(1, '2025-01-01', '2025-03-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertEqualsWithDelta(350.0, $response->getData()['spending'], 0.001);
	}

	public function testSpendingHandlesException(): void {
		$this->service->method('getCategorySpending')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->spending(999, '2025-01-01', '2025-03-31');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}
}
