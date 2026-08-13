<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\TransactionController;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Service\Export\TransactionCsvExporter;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\TransactionTagService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
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
	private IL10N $l;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(TransactionService::class);
		$this->splitService = $this->createMock(TransactionSplitService::class);
		$this->tagService = $this->createMock(TransactionTagService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->l = $this->createMock(IL10N::class);
		$this->l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

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

		$granularShareService = $this->createMock(GranularShareService::class);
		$granularShareService->method('canAccess')->willReturn(true);
		$granularShareService->method('getOwnAccountIds')->willReturn([1, 2, 3]);

		$this->controller = new TransactionController(
			$this->request,
			$this->service,
			$this->splitService,
			$this->tagService,
			$this->validationService,
			$granularShareService,
			new TransactionCsvExporter($this->l),
			$this->l,
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

	// ── export ──────────────────────────────────────────────────────

	public function testExportStreamsEveryMatchingTransaction(): void {
		$this->service->method('findAllForExport')->willReturnCallback(function () {
			yield [
				['date' => '2026-01-15', 'description' => 'Subs', 'type' => 'credit', 'amount' => 120.0],
				['date' => '2026-01-16', 'description' => 'Kit', 'type' => 'debit', 'amount' => 57.68],
			];
		});

		$response = $this->controller->export();
		$csv = $response->render();

		$this->assertStringContainsString('Subs', $csv);
		$this->assertStringContainsString('120.00', $csv);
		$this->assertStringContainsString('-57.68', $csv);
	}

	public function testExportPassesTheSameFiltersAsIndex(): void {
		$captured = null;
		$this->service->method('findAllForExport')->willReturnCallback(
			function (string $userId, array $filters) use (&$captured) {
				$captured = $filters;
				yield [];
			}
		);

		$this->controller->export(
			accountId: 7,
			search: 'kit',
			dateFrom: '2026-01-01',
			dateTo: '2026-12-31',
			category: '3',
			type: 'debit',
			status: 'cleared'
		);

		$this->assertSame(7, $captured['accountId']);
		$this->assertSame('kit', $captured['search']);
		$this->assertSame('2026-01-01', $captured['dateFrom']);
		$this->assertSame('2026-12-31', $captured['dateTo']);
		$this->assertSame('3', $captured['category']);
		$this->assertSame('debit', $captured['type']);
		$this->assertSame('cleared', $captured['status']);
	}

	public function testExportOfNoRowsStillReturnsAHeaderRow(): void {
		$this->service->method('findAllForExport')->willReturnCallback(function () {
			yield [];
		});

		$response = $this->controller->export();

		$this->assertStringContainsString('Date,Description', $response->render());
	}

	/**
	 * The name arrives as user-entered text (an account name), so a path
	 * separator or a quote in it must not reach the Content-Disposition header.
	 */
	public function testExportFilenameIsSanitisedAndDated(): void {
		$method = new \ReflectionMethod(TransactionController::class, 'exportFilename');
		$method->setAccessible(true);

		$this->assertSame(
			'Club_Current_AC_2026_main_' . date('Y-m-d') . '.csv',
			$method->invoke($this->controller, 'Club Current A/C: 2026 "main"')
		);
	}

	public function testExportFilenameFallsBackWhenNothingUsableIsLeft(): void {
		$method = new \ReflectionMethod(TransactionController::class, 'exportFilename');
		$method->setAccessible(true);

		$this->assertSame('transactions_' . date('Y-m-d') . '.csv', $method->invoke($this->controller, '///'));
		$this->assertSame('transactions_' . date('Y-m-d') . '.csv', $method->invoke($this->controller, null));
	}

	public function testExportHandlesError(): void {
		$this->service->method('findAllForExport')->willThrowException(new \Exception('boom'));

		$response = $this->controller->export();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
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

	// ── ids ─────────────────────────────────────────────────────────

	public function testIdsReturnsAllMatchingIds(): void {
		$this->service->expects($this->once())
			->method('findIdsWithFilters')
			->with(
				'user1',
				$this->callback(fn ($filters) => $filters['accountId'] === 10 && $filters['type'] === 'debit'),
				$this->anything()
			)
			->willReturn(['ids' => [1, 2, 3], 'billCount' => 2]);

		$response = $this->controller->ids(accountId: 10, type: 'debit');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame([1, 2, 3], $data['ids']);
		$this->assertSame(3, $data['total']);
		$this->assertSame(2, $data['billCount']);
	}

	public function testIdsHandlesError(): void {
		$this->service->method('findIdsWithFilters')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->ids();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
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
		$this->service->method('findForAccounts')->willThrowException(new \RuntimeException('not found'));

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

		$granularShareService2 = $this->createMock(GranularShareService::class);
		$granularShareService2->method('canAccess')->willReturn(true);
		$granularShareService2->method('getOwnAccountIds')->willReturn([1, 2, 3]);
		$this->controller = new TransactionController(
			$this->request, $this->service, $this->splitService,
			$this->tagService, $this->validationService, $granularShareService2,
			new TransactionCsvExporter($this->l), $this->l, 'user1', $this->logger
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
		// Manual match dialog opts into cross-currency candidates (#326)
		$this->service->method('findPotentialMatches')->with(1, 'user1', 3, true)->willReturn($matches);

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

	// ── convertToTransfer ───────────────────────────────────────────

	public function testConvertToTransferReturnsResult(): void {
		$result = ['transaction' => [], 'linkedTransaction' => []];
		$this->service->method('convertToTransfer')->willReturn($result);

		$response = $this->controller->convertToTransfer(1, 20);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testConvertToTransferHandlesValidationError(): void {
		$this->service->method('convertToTransfer')
			->willThrowException(new \RuntimeException('Counterpart account must use the same currency'));

		$response = $this->controller->convertToTransfer(1, 20);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Counterpart account must use the same currency', $response->getData()['error']);
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

	/** This suite drives IRequest directly; there is no shared params bag. */
	private function requestParams(array $params): void {
		$this->request->method('getParams')->willReturn($params);
	}

	// ── negative parts: a receipt's savings line ────────────────────

	public function testASplitPartMayBeNegative(): void {
		// A savings or coupon line is a real allocation. Refusing it rejected
		// every supermarket receipt: the parts arrived correct and summing to
		// the total, and were turned away one at a time on their sign. The
		// invariant is the SUM, which the service enforces — not the sign of
		// any one part.
		$this->requestParams([
			'splits' => [
				['amount' => 41.63, 'categoryId' => 3],
				['amount' => -4.50, 'categoryId' => null, 'description' => 'Savings'],
			],
		]);
		$this->splitService->expects($this->once())
			->method('splitTransaction')
			->willReturn([]);

		$response = $this->controller->split(5);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testASplitPartOfZeroIsStillRefused(): void {
		// An empty row is not an allocation.
		$this->requestParams([
			'splits' => [
				['amount' => 10.00],
				['amount' => 0],
			],
		]);
		$this->splitService->expects($this->never())->method('splitTransaction');

		$response = $this->controller->split(5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('cannot be zero', $response->getData()['error']);
	}

	public function testASplitPartStillNeedsANumericAmount(): void {
		$this->requestParams(['splits' => [['amount' => 'free'], ['amount' => 10.00]]]);
		$this->splitService->expects($this->never())->method('splitTransaction');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->split(5)->getStatus());
	}
}
