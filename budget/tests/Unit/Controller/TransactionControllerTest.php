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
	/** @var list<array{0: string, 1: int[]}> requireUsableTags() calls in controllerForSharedRows() */
	private array $tagChecks = [];

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

	public function testIndexHidesOnlyTransferAccountsTheUserCannotSee(): void {
		// Own accounts 1-3, Joint (9) shared with the user; 44 is the owner's
		// account that was never shared. Listing own accounts only must not
		// hide Joint's name, since the user can still see it.
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getOwnAccountIds')->willReturn([1, 2, 3]);
		$shares->method('getVisibleAccountIds')->willReturn([1, 2, 3, 9]);
		$controller = new TransactionController(
			$this->request, $this->service, $this->splitService,
			$this->tagService, $this->validationService, $shares,
			new TransactionCsvExporter($this->l), $this->l, 'user1', $this->logger
		);
		$this->service->method('findWithFilters')->willReturn(['transactions' => [
			['id' => 1, 'accountId' => 1, 'linkedTransactionId' => 501, 'linkedAccountId' => 9, 'linkedAccountName' => 'Joint'],
			['id' => 2, 'accountId' => 1, 'linkedTransactionId' => 502, 'linkedAccountId' => 44, 'linkedAccountName' => 'Owner savings'],
		], 'total' => 2]);

		$rows = $controller->index(excludeShared: true)->getData()['transactions'];

		$this->assertSame('Joint', $rows[0]['linkedAccountName']);
		$this->assertSame([502, null, null], [$rows[1]['linkedTransactionId'], $rows[1]['linkedAccountId'], $rows[1]['linkedAccountName']]);
	}

	public function testIndexReturnsThePageRowsTagsInOneLookup(): void {
		$this->service->method('findWithFilters')->willReturn(['transactions' => [
			['id' => 7, 'accountId' => 1],
			['id' => 8, 'accountId' => 2],
		], 'total' => 2]);
		$tag = new \OCA\Budget\Db\Tag();
		$tag->setId(3);
		$tag->setName('Holiday');
		$this->tagService->expects($this->once())
			->method('getTagsForTransactions')
			->with([7, 8])
			->willReturn([7 => [$tag]]);
		$this->tagService->expects($this->never())->method('getTransactionTags');

		$data = $this->controller->index()->getData();

		$this->assertEquals((object)[7 => [$tag]], $data['tags']);
		$this->assertSame('{"7":[{"id":3', substr(json_encode($data['tags']), 0, 13));
	}

	public function testIndexSendsAnEmptyTagMapAsAnObject(): void {
		$this->service->method('findWithFilters')->willReturn(['transactions' => [], 'total' => 0]);
		$this->tagService->method('getTagsForTransactions')->willReturn([]);

		$this->assertSame('{}', json_encode($this->controller->index()->getData()['tags']));
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

	// ── category ids must be the ledger owner's ─────────────────────

	/**
	 * A controller whose share service knows categories 1-9 for 'owner' and
	 * 'user1'. Anything else is someone else's category, and the list query
	 * would hand its name back if it were stored.
	 */
	private function controllerWithCategories(array $ownAccountIds, ?array &$checks = null): TransactionController {
		$checks = [];
		$granularShareService = $this->createMock(GranularShareService::class);
		$granularShareService->method('getOwnAccountIds')->willReturn($ownAccountIds);
		$granularShareService->method('getVisibleAccountIds')->willReturn([1, 2, 3, 50]);
		$granularShareService->method('requireUsableCategory')->willReturnCallback(
			function (string $ownerId, ?int $categoryId) use (&$checks): void {
				$checks[] = [$ownerId, $categoryId];
				if ($categoryId !== null && $categoryId >= 10) {
					throw new \InvalidArgumentException('Category not found');
				}
			}
		);
		return new TransactionController(
			$this->request, $this->service, $this->splitService,
			$this->tagService, $this->validationService, $granularShareService,
			new TransactionCsvExporter($this->l), $this->l, 'user1', $this->logger
		);
	}

	public function testCreateRejectsACategoryTheOwnerCannotSee(): void {
		$this->service->expects($this->never())->method('create');
		$controller = $this->controllerWithCategories([1, 2, 3]);

		$response = $controller->create(1, '2026-03-01', 'Test', 10.0, 'debit', 999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Category not found', $response->getData()['error']);
	}

	public function testCreateOnASharedAccountChecksTheOwnersCategories(): void {
		$account = new \OCA\Budget\Db\Account();
		$account->setUserId('owner');
		$this->service->method('findAccountById')->willReturn($account);
		$this->service->method('create')->willReturn(new Transaction());
		$controller = $this->controllerWithCategories([], $checks);

		$response = $controller->create(50, '2026-03-01', 'Test', 10.0, 'debit', 4);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame([['owner', 4]], $checks);
	}

	public function testUpdateRejectsACategoryTheOwnerCannotSee(): void {
		$this->request->method('getParams')->willReturn(['categoryId' => 999]);
		$this->service->expects($this->never())->method('update');
		$controller = $this->controllerWithCategories([1, 2, 3]);

		$response = $controller->update(1, categoryId: 999);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testUpdateAllowsClearingTheCategory(): void {
		$this->request->method('getParams')->willReturn(['categoryId' => null]);
		$this->service->expects($this->once())->method('update')
			->with(1, 'user1', ['categoryId' => null])
			->willReturn(new Transaction());
		$controller = $this->controllerWithCategories([1, 2, 3]);

		$response = $controller->update(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	/**
	 * user1 edits a row in owner1's account 4, shared with them to write.
	 * owner1 also has account 9, never shared with user1, and account 6,
	 * shared read-only.
	 */
	private function controllerOnASharedRow(): TransactionController {
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('getVisibleAccountIds')->willReturn([4, 6]);
		$shares->method('canAccess')->willReturnCallback(fn ($user, $type, $id) => in_array($id, [4, 6], true));
		$shares->method('canWrite')->willReturnCallback(fn ($user, $type, $id) => $id === 4);
		$shares->method('requireWriteAccess')->willReturnCallback(function ($user, $type, $id) {
			if ($id !== 4) {
				throw new \OCA\Budget\Exception\ReadOnlyShareException();
			}
		});
		$this->service->method('find')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(''));
		$row = new Transaction();
		$row->setAccountId(4);
		$this->service->method('findForAccounts')->willReturn($row);
		$account = new \OCA\Budget\Db\Account();
		$account->setUserId('owner1');
		$this->service->method('findAccountById')->willReturn($account);
		return new TransactionController($this->request, $this->service, $this->splitService, $this->tagService,
			$this->validationService, $shares, new TransactionCsvExporter($this->l), $this->l, 'user1', $this->logger);
	}

	public function testASharedRowCannotMoveIntoAnAccountOfTheOwnersNeverShared(): void {
		$this->service->expects($this->never())->method('update');

		$response = $this->controllerOnASharedRow()->update(1, accountId: 9);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testASharedRowCannotMoveIntoAReadOnlyAccount(): void {
		$this->service->expects($this->never())->method('update');

		$response = $this->controllerOnASharedRow()->update(1, accountId: 6);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testASharedRowStillSavesInItsOwnAccount(): void {
		$this->service->expects($this->once())->method('update')
			->with(1, 'owner1', ['accountId' => 4])
			->willReturn(new Transaction());

		$response = $this->controllerOnASharedRow()->update(1, accountId: 4);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testBulkEditRejectsACategoryTheOwnerCannotSee(): void {
		$this->service->expects($this->never())->method('bulkEdit');
		// Both rows are in user1's own account 1
		$this->service->method('findAccountIdsWithin')->willReturn([1 => 1, 2 => 1]);
		$controller = $this->controllerWithCategories([1, 2, 3]);

		$response = $controller->bulkEdit([1, 2], ['categoryId' => 999]);

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
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(9));
		// Manual match dialog opts into cross-currency candidates (#326)
		$this->service->method('findPotentialMatches')
			->with(1, 'user1', 3, true, [9, 10])
			->willReturn($matches);

		$response = $this->controllerSeeing([9, 10])->getMatches(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(1, $response->getData()['count']);
	}

	// ── link ────────────────────────────────────────────────────────

	public function testLinkReturnsResult(): void {
		$result = ['linked' => true];
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(1));
		$this->service->method('linkTransactions')->willReturn($result);

		$response = $this->controller->link(1, 2);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testLinkHandlesValidationError(): void {
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(1));
		$this->service->method('linkTransactions')
			->willThrowException(new \RuntimeException('already linked'));

		$response = $this->controller->link(1, 2);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('already linked', $response->getData()['error']);
	}

	// ── convertToTransfer ───────────────────────────────────────────

	public function testConvertToTransferReturnsResult(): void {
		$result = ['transaction' => [], 'linkedTransaction' => []];
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(1));
		$this->service->method('convertToTransfer')->willReturn($result);

		$response = $this->controller->convertToTransfer(1, 20);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testConvertToTransferHandlesValidationError(): void {
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(1));
		$this->service->method('convertToTransfer')
			->willThrowException(new \RuntimeException('Counterpart account must use the same currency'));

		$response = $this->controller->convertToTransfer(1, 20);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Counterpart account must use the same currency', $response->getData()['error']);
	}

	// ── unlink ──────────────────────────────────────────────────────

	public function testUnlinkReturnsResult(): void {
		$result = ['unlinked' => true];
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(1));
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

	// ── bulkTags (#379) ─────────────────────────────────────────────

	public function testBulkTagsReturnsResults(): void {
		$results = ['success' => 2, 'failed' => 0, 'errors' => []];
		$this->tagService->expects($this->once())
			->method('bulkUpdateTags')
			->with('user1', [1, 2], [10], [20])
			->willReturn($results);

		$response = $this->controller->bulkTags([1, 2], [10], [20]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($results, $response->getData());
	}

	public function testBulkTagsRejectsEmptyIds(): void {
		$response = $this->controller->bulkTags([], [10], []);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No transaction IDs provided', $response->getData()['error']);
	}

	public function testBulkTagsRejectsWhenNoTagsGiven(): void {
		$response = $this->controller->bulkTags([1], [], []);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No tags provided', $response->getData()['error']);
	}

	public function testBulkTagsSurfacesServiceRejectionAsBadRequest(): void {
		$this->tagService->method('bulkUpdateTags')
			->willThrowException(new \Exception('Only global tags can be applied in bulk'));

		$response = $this->controller->bulkTags([1], [10], []);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	// ── bulkTagOptions (#379) ───────────────────────────────────────

	public function testBulkTagOptionsReturnsOptionsForTheSelection(): void {
		$options = ['totalSelected' => 2, 'globalTags' => [], 'tagSets' => [], 'unaffectedCount' => 0];
		$this->tagService->expects($this->once())
			->method('getBulkTagOptions')
			->with('user1', [1, 2])
			->willReturn($options);

		$response = $this->controller->bulkTagOptions([1, 2]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame($options, $response->getData());
	}

	public function testBulkTagOptionsRejectsEmptyIds(): void {
		$response = $this->controller->bulkTagOptions([]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No transaction IDs provided', $response->getData()['error']);
	}

	// ── getSplits ───────────────────────────────────────────────────

	public function testGetSplitsReturnsSplits(): void {
		$splits = [['id' => 1, 'amount' => 50.00]];
		// A row in one of user1's own accounts is read as user1
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(1));
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
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(1));
		$this->tagService->expects($this->once())->method('clearTransactionTags')->with(1, 'user1');

		$response = $this->controller->clearTags(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('success', $response->getData()['status']);
	}

	/** This suite drives IRequest directly; there is no shared params bag. */
	private function requestParams(array $params): void {
		$this->request->method('getParams')->willReturn($params);
	}

	// ── a write recipient works on a shared account as its owner (T4-3) ──

	/**
	 * user1 owns account 1. owner1 shared account 4 with them to write and
	 * account 6 read-only; owner1's account 9 was never shared. The row the
	 * single-transaction routes look at sits in $rowAccount.
	 */
	private function controllerForSharedRows(int $rowAccount = 4, ?int $rowCategory = null): TransactionController {
		$shares = $this->createMock(GranularShareService::class);
		// user1 sees owner1's shared categories 2-4 and their own 20-29;
		// owner1's 5-9 were never shared with them
		$shares->method('requireCategoryVisibleToWriter')->willReturnCallback(
			function (string $owner, string $writer, ?int $categoryId, array $kept = []): void {
				if ($categoryId === null || $writer === $owner || in_array($categoryId, $kept, true)) {
					return;
				}
				if (!in_array($categoryId, array_merge([2, 3, 4], range(20, 29)), true)) {
					throw new \InvalidArgumentException('Category not found');
				}
			}
		);
		$shares->method('getOwnAccountIds')->willReturn([1]);
		$shares->method('getVisibleAccountIds')->willReturn([1, 4, 6]);
		$shares->method('canWrite')->willReturnCallback(fn ($user, $type, $id) => in_array($id, [1, 4], true));
		$shares->method('requireWriteAccess')->willReturnCallback(function ($user, $type, $id) {
			if (!in_array($id, [1, 4], true)) {
				throw new \OCA\Budget\Exception\ReadOnlyShareException();
			}
		});
		$shares->method('requireUsableCategory')->willReturnCallback(function (string $owner, ?int $categoryId): void {
			// owner1 can use 1-9, user1 only their own 20-29
			$usable = $owner === 'owner1' ? range(1, 9) : range(20, 29);
			if ($categoryId !== null && !in_array($categoryId, $usable, true)) {
				throw new \InvalidArgumentException('Category not found');
			}
		});
		// user1 can see tags 1-9; owner1 can use those and their own 50-59
		$shares->method('getUsableTagIds')->willReturnCallback(fn (string $user, array $ids) => array_values(array_filter(
			array_map('intval', $ids),
			fn (int $id) => $id < 10 || ($user === 'owner1' && $id >= 50 && $id < 60)
		)));
		$this->tagChecks = [];
		$shares->method('requireUsableTags')->willReturnCallback(function (string $user, array $ids): void {
			$this->tagChecks[] = [$user, array_values($ids)];
			foreach ($ids as $id) {
				if ($id >= 10) {
					throw new \InvalidArgumentException('Invalid tag ID');
				}
			}
		});
		$row = $this->transactionInAccount($rowAccount);
		$row->setId(8);
		$row->setCategoryId($rowCategory);
		$this->service->method('findForAccounts')->willReturnCallback(function (int $id, array $visible) use ($rowAccount, $row) {
			if (!in_array($rowAccount, $visible, true)) {
				throw new \OCP\AppFramework\Db\DoesNotExistException('');
			}
			return $row;
		});
		// The owner-scoped lookup only finds user1's own rows
		$this->service->method('find')->willReturnCallback(function () use ($rowAccount, $row) {
			if ($rowAccount !== 1) {
				throw new \OCP\AppFramework\Db\DoesNotExistException('');
			}
			return $row;
		});
		$this->service->method('findAccountById')->willReturnCallback(function (int $accountId) {
			$account = new \OCA\Budget\Db\Account();
			$account->setUserId($accountId === 1 ? 'user1' : 'owner1');
			return $account;
		});
		// Rows 1-2 are user1's own, 3 and 4 sit in the write share, 5 in
		// the read-only one; 7 is in owner1's unshared account 9
		$this->service->method('findAccountIdsWithin')->willReturnCallback(
			fn (array $ids, array $visible) => array_filter(
				[1 => 1, 2 => 1, 3 => 4, 4 => 4, 5 => 6, 7 => 9],
				fn (int $account, int $id) => in_array($id, $ids, true) && in_array($account, $visible, true),
				ARRAY_FILTER_USE_BOTH
			)
		);
		return new TransactionController($this->request, $this->service, $this->splitService, $this->tagService,
			$this->validationService, $shares, new TransactionCsvExporter($this->l), $this->l, 'user1', $this->logger);
	}

	// ── a write recipient and the owner's unshared categories ───────

	public function testAWriteRecipientCannotFileARowUnderAnUnsharedCategoryOfTheOwners(): void {
		// Category 6 is owner1's, never shared with user1: stored by id, its
		// name then came back in her list and reports
		$this->requestParams(['categoryId' => 6]);
		$this->service->expects($this->never())->method('update');

		$response = $this->controllerForSharedRows(4)->update(8, categoryId: 6);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testARowTheOwnerFiledThatWayKeepsSaving(): void {
		$this->requestParams(['categoryId' => 6, 'notes' => 'checked']);
		$this->service->expects($this->once())->method('update')
			->with(8, 'owner1', $this->anything())->willReturn(new Transaction());

		$response = $this->controllerForSharedRows(4, 6)->update(8, categoryId: 6);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAWriteRecipientMayStillUseASharedCategory(): void {
		$this->requestParams(['categoryId' => 2]);
		$this->service->expects($this->once())->method('update')->willReturn(new Transaction());

		$this->assertSame(Http::STATUS_OK, $this->controllerForSharedRows(4, 6)->update(8, categoryId: 2)->getStatus());
	}

	public function testCreatingOnASharedAccountNeedsACategoryTheWriterCanSee(): void {
		$this->service->expects($this->once())->method('create')->willReturn(new Transaction());
		$controller = $this->controllerForSharedRows(4);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->create(4, '2026-10-01', 'Shop', 5.0, 'debit', 6)->getStatus());
		$this->assertSame(Http::STATUS_CREATED, $controller->create(4, '2026-10-01', 'Shop', 5.0, 'debit', 2)->getStatus());
	}

	public function testBulkActionsCannotUseAnUnsharedCategoryOfTheOwners(): void {
		$this->service->expects($this->never())->method('bulkEdit');
		$this->service->expects($this->never())->method('bulkCategorize');
		$controller = $this->controllerForSharedRows();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->bulkEdit([3], ['categoryId' => 6])->getStatus());
		$this->assertSame(['success' => 0, 'failed' => 1], $controller->bulkCategorize([['id' => 3, 'categoryId' => 6]])->getData());
	}

	private function part(int $id, ?int $categoryId): \OCA\Budget\Db\TransactionSplit {
		$part = new \OCA\Budget\Db\TransactionSplit();
		$part->setId($id);
		$part->setCategoryId($categoryId);
		return $part;
	}

	public function testSplittingIntoAnUnsharedCategoryIsRefused(): void {
		$this->requestParams(['splits' => [['amount' => 6, 'categoryId' => 6], ['amount' => 4, 'categoryId' => 2]]]);
		$this->splitService->method('getSplits')->willReturn([]);
		$this->splitService->expects($this->never())->method('splitTransaction');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controllerForSharedRows(4)->split(8)->getStatus());
	}

	public function testASplitAlreadyUsingAnUnsharedCategoryCanBeResaved(): void {
		$this->requestParams(['splits' => [['amount' => 6, 'categoryId' => 6], ['amount' => 4, 'categoryId' => 2]]]);
		$this->splitService->method('getSplits')->with(8, 'owner1')->willReturn([$this->part(30, 6), $this->part(31, 3)]);
		$this->splitService->expects($this->once())->method('splitTransaction')->willReturn([]);

		$this->assertSame(Http::STATUS_CREATED, $this->controllerForSharedRows(4)->split(8)->getStatus());
	}

	public function testUnsplittingKeepsOnlyACategoryTheRowAlreadyHasOrTheWriterCanSee(): void {
		$this->splitService->method('getSplits')->willReturn([$this->part(30, 6), $this->part(31, 3)]);
		$this->splitService->expects($this->once())->method('unsplitTransaction')
			->with(8, 'owner1', 6)->willReturn(new Transaction());
		$controller = $this->controllerForSharedRows(4);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->unsplit(8, 7)->getStatus());
		$this->assertSame(Http::STATUS_OK, $controller->unsplit(8, 6)->getStatus());
	}

	public function testAPartKeepsItsUnsharedCategoryButCannotMoveToOne(): void {
		$this->splitService->method('getSplits')->willReturn([$this->part(30, 6), $this->part(31, 3)]);
		$this->splitService->expects($this->once())->method('updateSplit')
			->with(30, 'owner1', ['categoryId' => 6, 'description' => 'kept'])->willReturn($this->part(30, 6));
		$controller = $this->controllerForSharedRows(4);

		$this->requestParams(['categoryId' => 6, 'description' => 'kept']);
		$this->assertSame(Http::STATUS_OK, $controller->updateSplit(8, 30)->getStatus());
	}

	public function testAPartCannotMoveToAnUnsharedCategory(): void {
		$this->splitService->method('getSplits')->willReturn([$this->part(30, 6), $this->part(31, 3)]);
		$this->splitService->expects($this->never())->method('updateSplit');

		$this->requestParams(['categoryId' => 7]);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controllerForSharedRows(4)->updateSplit(8, 31)->getStatus());
	}

	public function testAWriteRecipientSplitsAsTheAccountOwner(): void {
		$this->requestParams(['splits' => [['amount' => 6, 'categoryId' => 2], ['amount' => 4, 'categoryId' => 3]]]);
		$this->splitService->expects($this->once())->method('splitTransaction')
			->with(8, 'owner1', $this->anything())
			->willReturn([]);

		$response = $this->controllerForSharedRows(4)->split(8);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testAReadOnlyRecipientCannotSplit(): void {
		$this->requestParams(['splits' => [['amount' => 6, 'categoryId' => 2], ['amount' => 4, 'categoryId' => 3]]]);
		$this->splitService->expects($this->never())->method('splitTransaction');

		$response = $this->controllerForSharedRows(6)->split(8);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testARowInAnAccountNeverSharedCannotBeSplit(): void {
		$this->requestParams(['splits' => [['amount' => 6, 'categoryId' => 2], ['amount' => 4, 'categoryId' => 3]]]);
		$this->splitService->expects($this->never())->method('splitTransaction');

		$response = $this->controllerForSharedRows(9)->split(8);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testAReadOnlyRecipientCanReadTheSplits(): void {
		$this->splitService->expects($this->once())->method('getSplits')
			->with(8, 'owner1')->willReturn([]);

		$response = $this->controllerForSharedRows(6)->getSplits(8);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAWriteRecipientUnsplitsAsTheAccountOwner(): void {
		$this->splitService->expects($this->once())->method('unsplitTransaction')
			->with(8, 'owner1', 2)->willReturn(new Transaction());

		$response = $this->controllerForSharedRows(4)->unsplit(8, 2);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAReadOnlyRecipientCannotUnsplit(): void {
		$this->splitService->expects($this->never())->method('unsplitTransaction');

		$response = $this->controllerForSharedRows(6)->unsplit(8);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	private function splitPart(int $id): \OCA\Budget\Db\TransactionSplit {
		$part = new \OCA\Budget\Db\TransactionSplit();
		$part->setId($id);
		return $part;
	}

	public function testAWriteRecipientEditsAPartAsTheAccountOwner(): void {
		$this->requestParams(['description' => 'petrol']);
		$this->splitService->method('getSplits')->with(8, 'owner1')
			->willReturn([$this->splitPart(30), $this->splitPart(31)]);
		$this->splitService->expects($this->once())->method('updateSplit')
			->with(31, 'owner1', ['description' => 'petrol'])
			->willReturn($this->splitPart(31));

		$response = $this->controllerForSharedRows(4)->updateSplit(8, 31);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAPartOfAnotherTransactionCannotBeEditedThroughThisOne(): void {
		// Part 77 belongs to some other transaction of owner1's, maybe in an
		// account never shared: the write check on transaction 8 says
		// nothing about it
		$this->requestParams(['description' => 'mine now']);
		$this->splitService->method('getSplits')->willReturn([$this->splitPart(30), $this->splitPart(31)]);
		$this->splitService->expects($this->never())->method('updateSplit');

		$response = $this->controllerForSharedRows(4)->updateSplit(8, 77);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testBulkDeleteRunsEachRowAsItsAccountOwner(): void {
		$calls = [];
		$this->service->method('bulkDelete')->willReturnCallback(function (string $owner, array $ids) use (&$calls) {
			$calls[$owner] = $ids;
			return ['success' => count($ids), 'failed' => 0, 'errors' => []];
		});

		$response = $this->controllerForSharedRows()->bulkDelete([1, 3, 5, 7, 2, 4]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['user1' => [1, 2], 'owner1' => [3, 4]], $calls);
		$data = $response->getData();
		$this->assertSame(4, $data['success']);
		$this->assertSame(2, $data['failed']);
		// A refusal says what it is, never the lookup query
		$this->assertSame([5, 7], array_column($data['errors'], 'id'));
		foreach ($data['errors'] as $error) {
			$this->assertStringNotContainsString('SELECT', $error['message']);
		}
	}

	public function testBulkReconcileRunsEachRowAsItsAccountOwner(): void {
		$calls = [];
		$this->service->method('bulkReconcile')->willReturnCallback(function (string $owner, array $ids, bool $reconciled) use (&$calls) {
			$calls[$owner] = $ids;
			return ['success' => count($ids), 'failed' => 0];
		});

		$response = $this->controllerForSharedRows()->bulkReconcile([3, 1, 5], true);

		$this->assertSame(['owner1' => [3], 'user1' => [1]], $calls);
		$this->assertSame(['success' => 2, 'failed' => 1], $response->getData());
	}

	public function testBulkEditRunsEachRowAsItsAccountOwner(): void {
		$calls = [];
		$this->service->method('bulkEdit')->willReturnCallback(function (string $owner, array $ids, array $updates) use (&$calls) {
			$calls[$owner] = [$ids, $updates];
			return ['success' => count($ids), 'failed' => 0, 'errors' => []];
		});

		$response = $this->controllerForSharedRows()->bulkEdit([3, 4, 5], ['notes' => 'checked']);

		$this->assertSame(['owner1' => [[3, 4], ['notes' => 'Some notes']]], $calls);
		$this->assertSame(2, $response->getData()['success']);
		$this->assertSame(1, $response->getData()['failed']);
	}

	public function testBulkEditOfASharedRowNeedsACategoryItsOwnerCanUse(): void {
		// 25 is user1's own category, which owner1 can't see
		$this->service->expects($this->never())->method('bulkEdit');

		$response = $this->controllerForSharedRows()->bulkEdit([1, 3], ['categoryId' => 25]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString("account owner's categories", $response->getData()['error']);
	}

	// ── tags on a shared row: as its owner, only tags user1 can see ──

	private function tag(int $id): \OCA\Budget\Db\Tag {
		$tag = new \OCA\Budget\Db\Tag();
		$tag->setId($id);
		return $tag;
	}

	public function testAWriteRecipientTagsASharedRowAsItsOwner(): void {
		$this->requestParams(['tagIds' => [3, 50]]);
		// 50 is already on the row (the owner's own tag); only 3 is new
		$this->tagService->method('getTransactionTags')->with(8, 'owner1')->willReturn([$this->tag(50)]);
		$this->tagService->expects($this->once())->method('setTransactionTags')
			->with(8, 'owner1', [3, 50])->willReturn([]);

		$response = $this->controllerForSharedRows(4)->setTags(8);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([['user1', [3]]], $this->tagChecks);
	}

	public function testARecipientsTagSaveKeepsTheOwnersTagsSheCannotSee(): void {
		// 50 is owner1's own tag on the row; user1 sends only what she
		// picked. 99 is an old id neither of them can use: it goes.
		$this->requestParams(['tagIds' => [4]]);
		$this->tagService->method('getTransactionTags')->with(8, 'owner1')
			->willReturn([$this->tag(50), $this->tag(3), $this->tag(99)]);
		$this->tagService->expects($this->once())->method('setTransactionTags')
			->with(8, 'owner1', [4, 50])->willReturn([]);

		$response = $this->controllerForSharedRows(4)->setTags(8);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testARecipientClearingTagsKeepsTheOwnersTagsSheCannotSee(): void {
		$this->tagService->method('getTransactionTags')->with(8, 'owner1')
			->willReturn([$this->tag(50), $this->tag(3)]);
		$this->tagService->expects($this->never())->method('clearTransactionTags');
		$this->tagService->expects($this->once())->method('setTransactionTags')
			->with(8, 'owner1', [50])->willReturn([]);

		$response = $this->controllerForSharedRows(4)->clearTags(8);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testTheOwnerReplacesAllOfARowsTags(): void {
		$this->requestParams(['tagIds' => [4]]);
		$this->tagService->method('getTransactionTags')->with(8, 'user1')
			->willReturn([$this->tag(50), $this->tag(3)]);
		$this->tagService->expects($this->once())->method('setTransactionTags')
			->with(8, 'user1', [4])->willReturn([]);

		$response = $this->controllerForSharedRows(1)->setTags(8);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testATagTheUserCannotSeeIsRefused(): void {
		// Any id used to be stored, and its name read back off the row
		$this->requestParams(['tagIds' => [42]]);
		$this->tagService->method('getTransactionTags')->willReturn([]);
		$this->tagService->expects($this->never())->method('setTransactionTags');

		$response = $this->controllerForSharedRows(4)->setTags(8);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testAReadOnlyRecipientCannotTag(): void {
		$this->requestParams(['tagIds' => [3]]);
		$this->tagService->expects($this->never())->method('setTransactionTags');

		$response = $this->controllerForSharedRows(6)->setTags(8);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAWriteRecipientClearsTagsAsTheOwner(): void {
		$this->tagService->expects($this->once())->method('clearTransactionTags')->with(8, 'owner1');

		$response = $this->controllerForSharedRows(4)->clearTags(8);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAReadOnlyRecipientCannotClearTags(): void {
		$this->tagService->expects($this->never())->method('clearTransactionTags');

		$response = $this->controllerForSharedRows(6)->clearTags(8);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	// ── bulk-categorize applies the ledger-category rule (R6-3) ─────

	public function testBulkCategorizeRefusesAnotherUsersCategory(): void {
		// 999 is nobody user1 can see: stored, its name came back in the list
		$this->service->expects($this->never())->method('bulkCategorize');

		$response = $this->controllerForSharedRows()->bulkCategorize([['id' => 1, 'categoryId' => 999]]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['success' => 0, 'failed' => 1], $response->getData());
	}

	public function testBulkCategorizeChecksEachRowAgainstItsOwner(): void {
		$calls = [];
		$this->service->method('bulkCategorize')->willReturnCallback(function (string $owner, array $updates) use (&$calls) {
			$calls[$owner] = $updates;
			return ['success' => count($updates), 'failed' => 0];
		});

		$response = $this->controllerForSharedRows()->bulkCategorize([
			['id' => 1, 'categoryId' => 25],  // user1's row, user1's category
			['id' => 3, 'categoryId' => 25],  // owner1's row: owner1 can't see 25
			['id' => 4, 'categoryId' => 2],   // owner1's row, owner1's category
			['id' => 5, 'categoryId' => 2],   // read-only share
			['id' => 2, 'categoryId' => null],
		]);

		$this->assertSame([
			'user1' => [['id' => 1, 'categoryId' => 25], ['id' => 2, 'categoryId' => null]],
			'owner1' => [['id' => 4, 'categoryId' => 2]],
		], $calls);
		$this->assertSame(['success' => 3, 'failed' => 2], $response->getData());
	}

	public function testBulkCategorizeCountsAMalformedEntryAsFailed(): void {
		$this->service->expects($this->never())->method('bulkCategorize');

		$response = $this->controllerForSharedRows()->bulkCategorize([['categoryId' => 2], 'junk']);

		$this->assertSame(['success' => 0, 'failed' => 2], $response->getData());
	}

	public function testBulkEditOfASharedRowWithTheOwnersCategory(): void {
		$this->service->expects($this->once())->method('bulkEdit')
			->with('owner1', [3], ['categoryId' => 2])
			->willReturn(['success' => 1, 'failed' => 0, 'errors' => []]);

		$response = $this->controllerForSharedRows()->bulkEdit([3], ['categoryId' => 2]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
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
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(1));
		$this->splitService->expects($this->once())
			->method('splitTransaction')
			->with(5, 'user1', $this->anything())
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

	/**
	 * A controller that can see $visibleAccountIds. Built fresh rather than
	 * re-stubbing setUp()'s share mock, which PHPUnit will not let a later
	 * willReturn() override.
	 */
	private function controllerSeeing(array $visibleAccountIds, bool $canWrite = true): TransactionController {
		$granularShareService = $this->createMock(GranularShareService::class);
		$granularShareService->method('canAccess')->willReturn(true);
		$granularShareService->method('getOwnAccountIds')->willReturn([]);
		$granularShareService->method('getVisibleAccountIds')->willReturn($visibleAccountIds);
		$granularShareService->method('getWritableAccountIds')->willReturn($canWrite ? $visibleAccountIds : []);
		$granularShareService->method('canWrite')->willReturn($canWrite);
		if (!$canWrite) {
			$granularShareService->method('requireWriteAccess')
				->willThrowException(new \OCA\Budget\Exception\ReadOnlyShareException());
		}
		return new TransactionController(
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

	private function transactionInAccount(int $accountId): Transaction {
		$transaction = new Transaction();
		$transaction->setAccountId($accountId);
		return $transaction;
	}

	// ── transfers across shared accounts (#368) ─────────────────────

	public function testLinkScopesBothLegsToTheVisibleAccounts(): void {
		$this->service->method('findForAccounts')
			->willReturn($this->transactionInAccount(9));
		$this->service->expects($this->once())->method('linkTransactions')
			->with(5, 6, 'user1', [9, 10])
			->willReturn(['transaction' => null, 'linkedTransaction' => null]);

		$response = $this->controllerSeeing([9, 10])->link(5, 6);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testLinkRefusesALegOutsideTheVisibleAccounts(): void {
		$this->service->method('findForAccounts')
			->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException('nope'));
		$this->service->expects($this->never())->method('linkTransactions');

		$response = $this->controllerSeeing([9, 10])->link(5, 6);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testLinkDoesNotLeakTheLookupQueryToTheClient(): void {
		$this->service->method('findForAccounts')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException(
				'Did expect one result but found none when executing: query "SELECT `t`.* FROM `oc_budget_transactions`"'
			)
		);

		$response = $this->controllerSeeing([9, 10])->link(5, 6);

		$this->assertStringNotContainsString('SELECT', $response->getData()['error']);
	}

	public function testLinkRefusesAReadOnlySharedAccount(): void {
		$this->service->method('findForAccounts')
			->willReturn($this->transactionInAccount(9));
		$this->service->expects($this->never())->method('linkTransactions');

		$response = $this->controllerSeeing([9, 10], false)->link(5, 6);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testConvertToTransferScopesToTheVisibleAccounts(): void {
		$this->service->method('findForAccounts')
			->willReturn($this->transactionInAccount(9));
		$this->service->expects($this->once())->method('convertToTransfer')
			->with(5, 10, 'user1', [9, 10])
			->willReturn(['transaction' => null, 'linkedTransaction' => null]);

		$response = $this->controllerSeeing([9, 10])->convertToTransfer(5, 10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUnlinkScopesToTheVisibleAccounts(): void {
		$this->service->method('findForAccounts')
			->willReturn($this->transactionInAccount(9));
		$this->service->expects($this->once())->method('unlinkTransaction')
			->with(5, 'user1', [9, 10])
			->willReturn(['transaction' => null, 'unlinkedTransactionId' => 6]);

		$response = $this->controllerSeeing([9, 10])->unlink(5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUnlinkSaysWhyAPreBookedTransferStaysLinked(): void {
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(9));
		$this->service->method('unlinkTransaction')->willThrowException(
			new \InvalidArgumentException('This is the upcoming payment of a recurring transfer, so its two sides stay linked.')
		);

		$response = $this->controllerSeeing([9, 10])->unlink(5);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringContainsString('recurring transfer', $response->getData()['error']);
	}

	// ── transfer matching across shared accounts (#378) ─────────────

	public function testGetMatchesSearchesEveryWritableAccount(): void {
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(9));
		// The dialog is where the user picks the pair, so a bill's recorded
		// payment is offered there, unlike in any automatic flow
		$this->service->expects($this->once())->method('findPotentialMatches')
			->with(5, 'user1', 3, true, [9, 10], true)
			->willReturn([]);

		$response = $this->controllerSeeing([9, 10])->getMatches(5);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testGetMatchesRefusesATransactionOutsideTheVisibleAccounts(): void {
		$this->service->method('findForAccounts')->willThrowException(
			new \OCP\AppFramework\Db\DoesNotExistException('nope')
		);
		$this->service->expects($this->never())->method('findPotentialMatches');

		$response = $this->controllerSeeing([9, 10])->getMatches(5);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testGetMatchesRefusesAReadOnlySharedAccount(): void {
		$this->service->method('findForAccounts')->willReturn($this->transactionInAccount(9));
		$this->service->expects($this->never())->method('findPotentialMatches');

		$response = $this->controllerSeeing([9, 10], false)->getMatches(5);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testBulkMatchAfterAnImportStartsFromTheImportedRows(): void {
		$this->service->expects($this->once())->method('bulkFindAndMatch')
			->with('user1', 3, 100, [9, 10], [41, 42])
			->willReturn(['autoMatched' => [], 'needsReview' => [], 'stats' => []]);

		$response = $this->controllerSeeing([9, 10])->bulkMatch(3, [41, 42]);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testScanMatchesSearchesEveryWritableAccount(): void {
		$this->service->expects($this->once())->method('scanForMatches')
			->with('user1', 3, 100, [9, 10])
			->willReturn(['candidates' => [], 'stats' => []]);

		$response = $this->controllerSeeing([9, 10])->scanMatches();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testBulkLinkScopesEachPairToTheWritableAccounts(): void {
		$this->requestParams(['pairs' => [['sourceId' => 5, 'targetId' => 6]]]);
		$this->service->expects($this->once())->method('bulkLinkTransactions')
			->with('user1', [['sourceId' => 5, 'targetId' => 6]], [9, 10])
			->willReturn(['linked' => [], 'failed' => [], 'stats' => []]);

		$response = $this->controllerSeeing([9, 10])->bulkLink();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}
}
