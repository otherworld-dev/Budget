<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\CategoryController;
use OCA\Budget\Db\Category;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\RecurringBudgetService;
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
	private RecurringBudgetService $recurringBudgetService;
	private IRequest $request;
	private GranularShareService $granularShareService;

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

		$this->granularShareService = $this->createMock(GranularShareService::class);
		$this->granularShareService->method('canAccess')->willReturn(true);

		$this->recurringBudgetService = $this->createMock(RecurringBudgetService::class);

		$this->controller = new CategoryController(
			$this->request,
			$this->service,
			$this->validationService,
			$this->granularShareService,
			$this->recurringBudgetService,
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

	public function testWriteShareRecipientCanReorderButNotRestructure(): void {
		// A write-share recipient may change sortOrder (reorder shared categories)
		// but structural/budget fields are stripped (#328).
		$this->granularShareService->method('resolveOwner')->willReturn('owner1');
		$category = $this->makeCategory();
		$this->service->expects($this->once())
			->method('update')
			->with(1, 'owner1', $this->callback(function ($updates) {
				return array_key_exists('sortOrder', $updates)
					&& !array_key_exists('type', $updates)
					&& !array_key_exists('budgetAmount', $updates);
			}))
			->willReturn($category);

		// Recipient sends type + budgetAmount + sortOrder; only sortOrder survives.
		$response = $this->controller->update(1, null, 'income', null, null, null, 99.0, null, 5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── reorder (#328) ──────────────────────────────────────────────

	public function testReorderResolvesOwnerForSharedCategory(): void {
		$this->granularShareService->method('resolveOwner')->willReturn('owner1');
		$this->request->method('getParams')->willReturn(['targetId' => 2, 'position' => 'above']);
		$category = $this->makeCategory();
		$this->service->expects($this->once())
			->method('reorderCategory')->with(1, 'owner1', 2, 'above')
			->willReturn($category);

		$response = $this->controller->reorder(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testReorderBlocksNestingSharedCategory(): void {
		// Reparenting (child) a category you don't own is owner-only.
		$this->granularShareService->method('resolveOwner')->willReturn('owner1');
		$this->request->method('getParams')->willReturn(['targetId' => 2, 'position' => 'child']);
		$this->service->expects($this->never())->method('reorderCategory');

		$response = $this->controller->reorder(1);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroySuccess(): void {
		// destroy() resolves the owner first (owner-only delete, #306 phase 2)
		$this->granularShareService->method('resolveOwner')->willReturn('user1');
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	public function testDestroyHandlesException(): void {
		$this->granularShareService->method('resolveOwner')->willReturn('user1');
		$this->service->method('delete')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testDestroyReturnsConflictWithCodeWhenCategoryInUse(): void {
		// A category with transactions returns a machine-readable code so the
		// client can offer to reassign and retry (#332).
		$this->granularShareService->method('resolveOwner')->willReturn('user1');
		$this->service->method('delete')
			->willThrowException(new \OCA\Budget\Exception\CategoryInUseException('has transactions assigned'));

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('has_transactions', $response->getData()['code']);
	}

	public function testDestroyReassignsWhenRequested(): void {
		$this->granularShareService->method('resolveOwner')->willReturn('user1');
		$this->service->expects($this->once())
			->method('deleteWithReassign')->with(1, 'user1');
		$this->service->expects($this->never())->method('delete');

		$response = $this->controller->destroy(1, true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	// ── per-viewer report mutes ─────────────────────────────────────

	public function testReportMutesReturnsIds(): void {
		$this->service->method('getMutedCategoryIds')->with('user1')->willReturn([4, 9]);

		$response = $this->controller->reportMutes();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([4, 9], $response->getData());
	}

	public function testSetReportMuteStoresForVisibleCategory(): void {
		$this->granularShareService->method('getVisibleCategoryIds')->with('user1')->willReturn([4, 9]);
		$this->service->expects($this->once())
			->method('setReportMute')
			->with(9, 'user1', true);

		$response = $this->controller->setReportMute(9, true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue($response->getData()['muted']);
	}

	public function testSetReportMuteRejectsInvisibleCategory(): void {
		$this->granularShareService->method('getVisibleCategoryIds')->willReturn([4]);
		$this->service->expects($this->never())->method('setReportMute');

		$response = $this->controller->setReportMute(999, true);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
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

	public function testAllSpendingExcludeSharedScopesToOwnAccounts(): void {
		// excludeShared narrows the account scope to the user's own accounts
		// (shared-with-me accounts dropped) before hitting the service (#286)
		$this->granularShareService->method('getOwnAccountIds')->willReturn([1, 2]);
		$this->granularShareService->expects($this->never())->method('getVisibleAccountIds');

		$this->service->expects($this->once())
			->method('getAllCategorySpending')
			->with('user1', '2025-01-01', '2025-03-31', [1, 2], 'debit')
			->willReturn([]);

		$response = $this->controller->allSpending('2025-01-01', '2025-03-31', 'debit', true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── spending ────────────────────────────────────────────────────

	public function testSpendingReturnsAmountForCategory(): void {
		$this->granularShareService->method('resolveOwner')->willReturn('user1');
		$this->service->method('getCategorySpending')
			->with(1, 'user1', '2025-01-01', '2025-03-31')
			->willReturn(350.0);

		$response = $this->controller->spending(1, '2025-01-01', '2025-03-31');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertEqualsWithDelta(350.0, $response->getData()['spending'], 0.001);
	}

	public function testSpendingHandlesException(): void {
		$this->granularShareService->method('resolveOwner')->willReturn('user1');
		$this->service->method('getCategorySpending')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->spending(999, '2025-01-01', '2025-03-31');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── shared-category reads resolve to the owner (#328) ───────────

	public function testDetailsResolvesOwnerForSharedCategory(): void {
		// Category 137 is shared to user1 by owner1; details must query the owner.
		$this->granularShareService->method('resolveOwner')
			->with('user1', 'category', 137)->willReturn('owner1');
		$this->service->expects($this->once())
			->method('getCategoryDetails')
			->with(137, 'owner1', null, null, null)
			->willReturn(['count' => 3, 'total' => 60.0]);

		$response = $this->controller->details(137);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(3, $response->getData()['count']);
	}

	public function testDetailsReturnsNotFoundWhenNoAccess(): void {
		$this->granularShareService->method('resolveOwner')->willReturn(null);
		$this->service->expects($this->never())->method('getCategoryDetails');

		$response = $this->controller->details(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testTransactionsResolvesOwnerForSharedCategory(): void {
		$this->granularShareService->method('resolveOwner')
			->with('user1', 'category', 137)->willReturn('owner1');
		$this->service->expects($this->once())
			->method('getCategoryTransactions')
			->with(137, 'owner1', 5)
			->willReturn([['id' => 1]]);

		$response = $this->controller->transactions(137);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testTransactionsReturnsNotFoundWhenNoAccess(): void {
		$this->granularShareService->method('resolveOwner')->willReturn(null);
		$this->service->expects($this->never())->method('getCategoryTransactions');

		$response = $this->controller->transactions(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}
}
