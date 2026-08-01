<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ApiV1TransactionController;
use OCA\Budget\Db\Account;
use OCA\Budget\Db\Attachment;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Service\AttachmentService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ApiV1TransactionControllerTest extends TestCase {
	private ApiV1TransactionController $controller;
	private TransactionService $service;
	private AttachmentService $attachmentService;
	private ValidationService $validationService;
	private GranularShareService $granularShareService;
	private IRequest $request;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(TransactionService::class);
		$this->attachmentService = $this->createMock(AttachmentService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->granularShareService = $this->createMock(GranularShareService::class);

		// Validation passes by default; individual tests override.
		$this->validationService->method('validateDescription')
			->willReturn(['valid' => true, 'sanitized' => 'Weekly shop']);
		$this->validationService->method('validateDate')
			->willReturn(['valid' => true]);
		$this->validationService->method('validateVendor')
			->willReturn(['valid' => true, 'sanitized' => 'Tesco']);
		$this->validationService->method('validateReference')
			->willReturn(['valid' => true, 'sanitized' => 'REF1']);
		$this->validationService->method('validateNotes')
			->willReturn(['valid' => true, 'sanitized' => 'note']);

		$this->granularShareService->method('getVisibleAccountIds')->willReturn([1, 2, 9]);
		$this->granularShareService->method('getOwnAccountIds')->willReturn([1, 2]);

		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		$this->controller = new ApiV1TransactionController(
			$this->request,
			$this->service,
			$this->attachmentService,
			$this->validationService,
			$this->granularShareService,
			$l,
			'user1',
			$this->createMock(LoggerInterface::class)
		);
	}

	private function transaction(int $id = 10): Transaction {
		$transaction = new Transaction();
		$transaction->setId($id);
		$transaction->setAccountId(1);
		$transaction->setDate('2026-08-01');
		$transaction->setDescription('Weekly shop');
		$transaction->setAmount(42.5);
		$transaction->setType('debit');

		return $transaction;
	}

	// ── index ───────────────────────────────────────────────────────

	public function testIndexReturnsAPageWithItsOwnBounds(): void {
		$this->service->method('findWithFilters')->willReturn([
			'transactions' => [['id' => 1, 'accountId' => 1]],
			'total' => 137,
		]);

		$response = $this->controller->index(limit: 25, offset: 50);
		$data = $response->getData();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(137, $data['total']);
		$this->assertSame(25, $data['limit']);
		$this->assertSame(50, $data['offset']);
		$this->assertCount(1, $data['transactions']);
	}

	public function testIndexClampsAnOversizedLimitRatherThanRejecting(): void {
		$this->service->method('findWithFilters')
			->with(
				'user1',
				$this->anything(),
				ApiV1TransactionController::MAX_LIMIT,
				0,
				$this->anything()
			)
			->willReturn(['transactions' => [], 'total' => 0]);

		$data = $this->controller->index(limit: 100000)->getData();

		$this->assertSame(ApiV1TransactionController::MAX_LIMIT, $data['limit']);
	}

	public function testIndexRejectsNonsenseLimitAndOffsetSafely(): void {
		$this->service->method('findWithFilters')
			->with('user1', $this->anything(), 1, 0, $this->anything())
			->willReturn(['transactions' => [], 'total' => 0]);

		$data = $this->controller->index(limit: -5, offset: -20)->getData();

		$this->assertSame(1, $data['limit']);
		$this->assertSame(0, $data['offset']);
	}

	public function testIndexScopesToVisibleAccounts(): void {
		$this->service->expects($this->once())
			->method('findWithFilters')
			->with('user1', $this->anything(), $this->anything(), $this->anything(), [1, 2, 9])
			->willReturn(['transactions' => [], 'total' => 0]);

		$this->controller->index();
	}

	public function testIndexTranslatesCategoryIdToTheFilterBuilderVocabulary(): void {
		$this->service->expects($this->once())
			->method('findWithFilters')
			->with(
				'user1',
				$this->callback(fn ($filters) => $filters['category'] === '7'
					&& $filters['accountId'] === 3
					&& $filters['sort'] === 'date'
					&& $filters['direction'] === 'desc'),
				$this->anything(),
				$this->anything(),
				$this->anything()
			)
			->willReturn(['transactions' => [], 'total' => 0]);

		$this->controller->index(accountId: 3, categoryId: 7);
	}

	public function testIndexRejectsAMalformedDate(): void {
		$validationService = $this->createMock(ValidationService::class);
		$validationService->method('validateDate')->willReturn(['valid' => false, 'error' => 'bad date']);

		$controller = $this->controllerWith($validationService);
		$this->service->expects($this->never())->method('findWithFilters');

		$response = $controller->index(dateFrom: '01-08-2026');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testIndexHandlesException(): void {
		$this->service->method('findWithFilters')->willThrowException(new \RuntimeException('DB error'));

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Failed to retrieve transactions', $response->getData()['error']);
	}

	// ── show ────────────────────────────────────────────────────────

	public function testShowReturnsSerializedTransaction(): void {
		$this->service->method('findForAccounts')->with(10, [1, 2, 9])->willReturn($this->transaction());

		$response = $this->controller->show(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(10, $response->getData()['id']);
	}

	public function testShowReturnsNotFoundForAnInvisibleTransaction(): void {
		$this->service->method('findForAccounts')->willThrowException(new DoesNotExistException('nope'));

		$response = $this->controller->show(999);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Transaction not found', $response->getData()['error']);
	}

	// ── create ──────────────────────────────────────────────────────

	public function testCreateReturnsCreated(): void {
		$this->service->expects($this->once())
			->method('create')
			->with('user1', 1, '2026-08-01', 'Weekly shop', 42.5, 'debit')
			->willReturn($this->transaction());

		$response = $this->controller->create(1, '2026-08-01', 'Weekly shop', 42.5, 'debit');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(10, $response->getData()['id']);
	}

	public function testCreateWritesSharedAccountRowsUnderTheAccountOwner(): void {
		// Account 9 is visible but not owned. The row must land in the owner's
		// ledger — writing it under the acting user would orphan it.
		$account = new Account();
		$account->setId(9);
		$account->setUserId('owner2');

		$this->granularShareService->expects($this->once())
			->method('requireWriteAccess')
			->with('user1', 'account', 9);
		$this->service->method('findAccountById')->with(9)->willReturn($account);
		$this->service->expects($this->once())
			->method('create')
			->with('owner2', 9, $this->anything(), $this->anything(), $this->anything(), $this->anything())
			->willReturn($this->transaction());

		$response = $this->controller->create(9, '2026-08-01', 'Weekly shop', 42.5, 'debit');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateReturnsForbiddenOnAReadOnlyShare(): void {
		$this->granularShareService->method('requireWriteAccess')
			->willThrowException(new ReadOnlyShareException());
		$this->service->expects($this->never())->method('create');

		$response = $this->controller->create(9, '2026-08-01', 'Weekly shop', 42.5, 'debit');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testCreateRejectsAnUnknownType(): void {
		$this->service->expects($this->never())->method('create');

		$response = $this->controller->create(1, '2026-08-01', 'Weekly shop', 42.5, 'sideways');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid transaction type. Must be credit or debit', $response->getData()['error']);
	}

	public function testCreateRejectsAnInvalidDescription(): void {
		$validationService = $this->createMock(ValidationService::class);
		$validationService->method('validateDescription')
			->willReturn(['valid' => false, 'error' => 'Description is required']);
		$validationService->method('validateDate')->willReturn(['valid' => true]);

		$controller = $this->controllerWith($validationService);
		$this->service->expects($this->never())->method('create');

		$response = $controller->create(1, '2026-08-01', '', 42.5, 'debit');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Description is required', $response->getData()['error']);
	}

	public function testCreateRejectsAnInvalidDate(): void {
		$validationService = $this->createMock(ValidationService::class);
		$validationService->method('validateDescription')
			->willReturn(['valid' => true, 'sanitized' => 'Weekly shop']);
		$validationService->method('validateDate')
			->willReturn(['valid' => false, 'error' => 'Date must be in YYYY-MM-DD format']);

		$controller = $this->controllerWith($validationService);
		$this->service->expects($this->never())->method('create');

		$response = $controller->create(1, '01/08/2026', 'Weekly shop', 42.5, 'debit');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreatePassesSanitizedOptionalFields(): void {
		$this->service->expects($this->once())
			->method('create')
			->with('user1', 1, '2026-08-01', 'Weekly shop', 42.5, 'debit', 5, 'Tesco', 'REF1', 'note')
			->willReturn($this->transaction());

		$this->controller->create(1, '2026-08-01', 'Weekly shop', 42.5, 'debit', 5, 'raw vendor', 'raw ref', 'raw note');
	}

	public function testCreateOmitsOptionalFieldsThatWereNotSent(): void {
		$this->service->expects($this->once())
			->method('create')
			->with('user1', 1, '2026-08-01', 'Weekly shop', 42.5, 'debit', null, null, null, null)
			->willReturn($this->transaction());

		$this->controller->create(1, '2026-08-01', 'Weekly shop', 42.5, 'debit');
	}

	public function testCreateReturnsNotFoundForAnUnknownAccount(): void {
		$this->service->method('create')->willThrowException(new DoesNotExistException('no account'));

		$response = $this->controller->create(1, '2026-08-01', 'Weekly shop', 42.5, 'debit');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Account not found', $response->getData()['error']);
	}

	// ── receipts ────────────────────────────────────────────────────

	public function testReceiptsReturnsSerializedAttachments(): void {
		$this->attachmentService->method('listForTransaction')->with(10, 'user1')->willReturn([
			['id' => 1, 'transactionId' => 10, 'fileId' => 85, 'fileName' => 'r.png', 'missing' => false, 'isImage' => true],
		]);

		$data = $this->controller->receipts(10)->getData();

		$this->assertCount(1, $data);
		$this->assertSame(85, $data[0]['fileId']);
		// isImage is an internal UI convenience, not part of the contract.
		$this->assertArrayNotHasKey('isImage', $data[0]);
	}

	public function testReceiptsIsOwnerOnly(): void {
		$this->attachmentService->method('listForTransaction')
			->willThrowException(new DoesNotExistException('not yours'));

		$response = $this->controller->receipts(10);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testUploadReceiptReturnsCreated(): void {
		$attachment = new Attachment();
		$attachment->setId(1);
		$attachment->setTransactionId(10);
		$attachment->setFileId(85);

		$this->request->method('getUploadedFile')->with('file')->willReturn([
			'name' => 'r.png', 'tmp_name' => '/tmp/r.png', 'error' => UPLOAD_ERR_OK, 'size' => 100,
		]);
		$this->attachmentService->method('upload')->willReturn($attachment);

		$response = $this->controller->uploadReceipt(10);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(85, $response->getData()['fileId']);
	}

	public function testUploadReceiptRequiresAFile(): void {
		$this->request->method('getUploadedFile')->willReturn(null);
		$this->attachmentService->expects($this->never())->method('upload');

		$response = $this->controller->uploadReceipt(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No file uploaded', $response->getData()['error']);
	}

	public function testUploadReceiptSurfacesTheRejectionReason(): void {
		$this->request->method('getUploadedFile')->willReturn(['name' => 'r.txt', 'error' => UPLOAD_ERR_OK]);
		$this->attachmentService->method('upload')
			->willThrowException(new \InvalidArgumentException('File type not allowed'));

		$response = $this->controller->uploadReceipt(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('File type not allowed', $response->getData()['error']);
	}

	public function testUnauthenticatedConstructionDoesNotFatal(): void {
		$controller = new ApiV1TransactionController(
			$this->request,
			$this->service,
			$this->attachmentService,
			$this->validationService,
			$this->granularShareService,
			$this->createMock(IL10N::class),
			null,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertInstanceOf(ApiV1TransactionController::class, $controller);
	}

	private function controllerWith(ValidationService $validationService): ApiV1TransactionController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		return new ApiV1TransactionController(
			$this->request,
			$this->service,
			$this->attachmentService,
			$validationService,
			$this->granularShareService,
			$l,
			'user1',
			$this->createMock(LoggerInterface::class)
		);
	}
}
