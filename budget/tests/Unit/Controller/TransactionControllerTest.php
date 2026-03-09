<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\TransactionController;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\TransactionTagService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class TransactionControllerTest extends TestCase {
	private TransactionController $controller;
	private TransactionService $service;
	private TransactionSplitService $splitService;
	private TransactionTagService $tagService;
	private ValidationService $validationService;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(TransactionService::class);
		$this->splitService = $this->createMock(TransactionSplitService::class);
		$this->tagService = $this->createMock(TransactionTagService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		// Default validation passes
		$this->validationService->method('validateDescription')
			->willReturn(['valid' => true, 'sanitized' => 'Test desc']);
		$this->validationService->method('validateDate')
			->willReturn(['valid' => true]);
		$this->validationService->method('validateVendor')
			->willReturn(['valid' => true, 'sanitized' => 'Test vendor']);
		$this->validationService->method('validateReference')
			->willReturn(['valid' => true, 'sanitized' => 'REF001']);
		$this->validationService->method('validateNotes')
			->willReturn(['valid' => true, 'sanitized' => 'Some notes']);

		$this->controller = new TransactionController(
			$this->request,
			$this->service,
			$this->splitService,
			$this->tagService,
			$this->validationService,
			'user1',
			$this->logger
		);
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsTransactions(): void {
		$result = ['transactions' => [['id' => 1]], 'total' => 1];
		$this->service->method('findWithFilters')->willReturn($result);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(1, $data['total']);
		$this->assertSame(1, $data['page']);
	}

	public function testIndexHandlesError(): void {
		$this->service->method('findWithFilters')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testIndexCalculatesPagination(): void {
		$result = ['transactions' => [], 'total' => 250];
		$this->service->method('findWithFilters')->willReturn($result);

		$response = $this->controller->index(page: 3, limit: 50);

		$data = $response->getData();
		$this->assertSame(3, $data['page']);
		$this->assertEquals(5, $data['totalPages']);
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsTransaction(): void {
		$txn = $this->createMock(Transaction::class);
		$this->service->method('find')->with(1, 'user1')->willReturn($txn);

		$response = $this->controller->show(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testShowReturnsNotFound(): void {
		$this->service->method('find')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateReturnsCreated(): void {
		$txn = $this->createMock(Transaction::class);
		$this->service->method('create')->willReturn($txn);

		$response = $this->controller->create(1, '2026-03-01', 'Test desc', 100.00, 'debit');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateRejectsBadType(): void {
		$response = $this->controller->create(1, '2026-03-01', 'Test', 100.00, 'invalid');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid transaction type', $response->getData()['error']);
	}

	public function testCreateRejectsInvalidDescription(): void {
		$this->validationService = $this->createMock(ValidationService::class);
		$this->validationService->method('validateDescription')
			->willReturn(['valid' => false, 'error' => 'Description too long']);
		$this->validationService->method('validateDate')
			->willReturn(['valid' => true]);
		$this->validationService->method('validateVendor')
			->willReturn(['valid' => true, 'sanitized' => '']);
		$this->validationService->method('validateReference')
			->willReturn(['valid' => true, 'sanitized' => '']);
		$this->validationService->method('validateNotes')
			->willReturn(['valid' => true, 'sanitized' => '']);

		$this->controller = new TransactionController(
			$this->request, $this->service, $this->splitService,
			$this->tagService, $this->validationService, 'user1', $this->logger
		);

		$response = $this->controller->create(1, '2026-03-01', str_repeat('x', 1000), 100.00, 'debit');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateHandlesServiceError(): void {
		$this->service->method('create')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->create(1, '2026-03-01', 'Test', 100.00, 'debit');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── update ──────────────────────────────────────────────────────

	public function testUpdateReturnsUpdatedTransaction(): void {
		$txn = $this->createMock(Transaction::class);
		$this->service->method('update')->willReturn($txn);

		$response = $this->controller->update(1, description: 'Updated');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateRejectsEmptyUpdates(): void {
		$response = $this->controller->update(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No valid fields to update', $response->getData()['error']);
	}

	public function testUpdateRejectsInvalidType(): void {
		$response = $this->controller->update(1, type: 'invalid');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid transaction type', $response->getData()['error']);
	}

	public function testUpdateRejectsInvalidStatus(): void {
		$response = $this->controller->update(1, status: 'invalid');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid status', $response->getData()['error']);
	}

	// ── destroy ─────────────────────────────────────────────────────

	public function testDestroyDeletesTransaction(): void {
		$this->service->expects($this->once())->method('delete')->with(1, 'user1');

		$response = $this->controller->destroy(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	public function testDestroyReturnsNotFound(): void {
		$this->service->method('delete')->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->destroy(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	// ── search ──────────────────────────────────────────────────────

	public function testSearchReturnsResults(): void {
		$txns = [['id' => 1, 'description' => 'Groceries']];
		$this->service->method('search')->with('user1', 'groce', 100)->willReturn($txns);

		$response = $this->controller->search('groce');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testSearchHandlesError(): void {
		$this->service->method('search')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->search('test');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── uncategorized ───────────────────────────────────────────────

	public function testUncategorizedReturnsTransactions(): void {
		$txns = [['id' => 1]];
		$this->service->method('findUncategorized')->willReturn($txns);

		$response = $this->controller->uncategorized();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── bulkCategorize ──────────────────────────────────────────────

	public function testBulkCategorizeReturnsResults(): void {
		$updates = [['id' => 1, 'categoryId' => 5]];
		$results = ['updated' => 1];
		$this->service->method('bulkCategorize')->willReturn($results);

		$response = $this->controller->bulkCategorize($updates);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── getMatches ──────────────────────────────────────────────────

	public function testGetMatchesReturnsMatches(): void {
		$matches = [['id' => 2, 'amount' => -100.00]];
		$this->service->method('findPotentialMatches')->with(1, 'user1', 3)->willReturn($matches);

		$response = $this->controller->getMatches(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['count']);
	}

	// ── link ────────────────────────────────────────────────────────

	public function testLinkReturnsResult(): void {
		$result = ['linked' => true];
		$this->service->method('linkTransactions')->willReturn($result);

		$response = $this->controller->link(1, 2);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testLinkHandlesValidationError(): void {
		$this->service->method('linkTransactions')
			->willThrowException(new \RuntimeException('already linked'));

		$response = $this->controller->link(1, 2);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('already linked', $response->getData()['error']);
	}

	// ── unlink ──────────────────────────────────────────────────────

	public function testUnlinkReturnsResult(): void {
		$result = ['unlinked' => true];
		$this->service->method('unlinkTransaction')->willReturn($result);

		$response = $this->controller->unlink(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── bulkMatch ───────────────────────────────────────────────────

	public function testBulkMatchReturnsResult(): void {
		$result = ['autoLinked' => 3, 'multipleMatches' => []];
		$this->service->method('bulkFindAndMatch')->willReturn($result);

		$response = $this->controller->bulkMatch();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── bulkDelete ──────────────────────────────────────────────────

	public function testBulkDeleteReturnsResults(): void {
		$results = ['deleted' => 3];
		$this->service->method('bulkDelete')->willReturn($results);

		$response = $this->controller->bulkDelete([1, 2, 3]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testBulkDeleteRejectsEmptyIds(): void {
		$response = $this->controller->bulkDelete([]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No transaction IDs provided', $response->getData()['error']);
	}

	// ── bulkReconcile ───────────────────────────────────────────────

	public function testBulkReconcileReturnsResults(): void {
		$results = ['updated' => 2];
		$this->service->method('bulkReconcile')->willReturn($results);

		$response = $this->controller->bulkReconcile([1, 2], true);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testBulkReconcileRejectsEmptyIds(): void {
		$response = $this->controller->bulkReconcile([], true);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── bulkEdit ────────────────────────────────────────────────────

	public function testBulkEditReturnsResults(): void {
		$results = ['updated' => 2];
		$this->service->method('bulkEdit')->willReturn($results);

		$response = $this->controller->bulkEdit([1, 2], ['categoryId' => 5]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testBulkEditRejectsEmptyIds(): void {
		$response = $this->controller->bulkEdit([], ['categoryId' => 5]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No transaction IDs provided', $response->getData()['error']);
	}

	public function testBulkEditRejectsEmptyUpdates(): void {
		$response = $this->controller->bulkEdit([1], []);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No update fields provided', $response->getData()['error']);
	}

	public function testBulkEditRejectsInvalidFields(): void {
		$response = $this->controller->bulkEdit([1], ['invalidField' => 'value']);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('Invalid fields', $response->getData()['error']);
	}

	// ── getSplits ───────────────────────────────────────────────────

	public function testGetSplitsReturnsSplits(): void {
		$splits = [['id' => 1, 'amount' => 50.00]];
		$this->splitService->method('getSplits')->with(1, 'user1')->willReturn($splits);

		$response = $this->controller->getSplits(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── getTags ─────────────────────────────────────────────────────

	public function testGetTagsReturnsTags(): void {
		$tags = [['id' => 1, 'name' => 'Tag1']];
		$this->tagService->method('getTransactionTags')->with(1, 'user1')->willReturn($tags);

		$response = $this->controller->getTags(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── clearTags ───────────────────────────────────────────────────

	public function testClearTagsReturnsSuccess(): void {
		$this->tagService->expects($this->once())->method('clearTransactionTags')->with(1, 'user1');

		$response = $this->controller->clearTags(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}
}
