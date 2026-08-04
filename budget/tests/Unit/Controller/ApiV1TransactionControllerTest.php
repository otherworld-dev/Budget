<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ApiV1TransactionController;
use OCA\Budget\Db\Account;
use OCA\Budget\Db\Attachment;
use OCA\Budget\Db\IdempotencyKey;
use OCA\Budget\Db\IdempotencyKeyMapper;
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
	private IdempotencyKeyMapper $idempotencyKeys;
	private IRequest $request;

	/** What the mocked request reports. */
	private array $params = [];
	private array $uploads = [];
	private string $idemHeader = '';

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn () => $this->params);
		$this->request->method('getUploadedFile')->willReturnCallback(fn (string $key) => $this->uploads[$key] ?? null);
		$this->request->method('getParam')->willReturnCallback(fn (string $key, $default = null) => $this->params[$key] ?? $default);
		$this->request->method('getHeader')->willReturnCallback(
			fn (string $name) => $name === 'Idempotency-Key' ? $this->idemHeader : ''
		);

		$this->service = $this->createMock(TransactionService::class);
		$this->attachmentService = $this->createMock(AttachmentService::class);
		$this->validationService = $this->createMock(ValidationService::class);
		$this->granularShareService = $this->createMock(GranularShareService::class);
		$this->idempotencyKeys = $this->createMock(IdempotencyKeyMapper::class);
		$this->idempotencyKeys->method('findByKey')->willThrowException(new DoesNotExistException('fresh'));
		// The reservation flow uses insert's return value as the claim.
		$this->idempotencyKeys->method('insert')->willReturnArgument(0);

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

		$this->controller = $this->buildController($this->idempotencyKeys, $this->validationService);
	}

	private function buildController(IdempotencyKeyMapper $keys, ValidationService $validation): ApiV1TransactionController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));

		return new ApiV1TransactionController(
			$this->request,
			$this->service,
			$this->attachmentService,
			$validation,
			$this->granularShareService,
			$keys,
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

	/** The handoff's minimal valid POST body. */
	private function captureParams(array $overrides = []): array {
		return $overrides + [
			'account_id' => '1',
			'date' => '2026-08-01',
			'merchant' => 'Tesco',
			'amount' => '42.50',
		];
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

		$controller = $this->buildController($this->idempotencyKeys, $validationService);
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

	// ── recent ──────────────────────────────────────────────────────

	public function testRecentReturnsTheHandoffShape(): void {
		$this->service->method('findWithFilters')->willReturn([
			'transactions' => [[
				'id' => 20070, 'accountId' => 36, 'date' => '2026-08-01',
				'description' => 'CARD 1234 TESCO STORES', 'vendor' => 'Tesco',
				'amount' => 15.0, 'type' => 'debit',
				'accountName' => 'Current Account', 'accountCurrency' => 'GBP',
			]],
			'total' => 1,
		]);

		$data = $this->controller->recent()->getData();

		// Exactly the handoff's row shape: flat, merchant-first.
		$this->assertSame([[
			'id' => 20070,
			'merchant' => 'Tesco',
			'date' => '2026-08-01',
			'amount' => '15.00',
			'currency' => 'GBP',
			'account_name' => 'Current Account',
		]], $data);
	}

	public function testRecentFallsBackToTheDescriptionWhenThereIsNoVendor(): void {
		$this->service->method('findWithFilters')->willReturn([
			'transactions' => [['id' => 1, 'description' => 'Weekly shop', 'vendor' => null, 'amount' => 5]],
			'total' => 1,
		]);

		$this->assertSame('Weekly shop', $this->controller->recent()->getData()[0]['merchant']);
	}

	public function testRecentClampsItsLimit(): void {
		$this->params = ['limit' => '100000'];
		$this->service->expects($this->once())
			->method('findWithFilters')
			->with('user1', $this->anything(), ApiV1TransactionController::MAX_LIMIT, 0, $this->anything())
			->willReturn(['transactions' => [], 'total' => 0]);

		$this->controller->recent();
	}

	public function testRecentTreatsAGarbageLimitAsTheDefaultNotAnError(): void {
		// The docs promise clamping, never a 500 — and framework int-binding
		// would fatal on limit=abc, which is why the limit is read by hand.
		$this->params = ['limit' => 'abc'];
		$this->service->expects($this->once())
			->method('findWithFilters')
			->with('user1', $this->anything(), ApiV1TransactionController::DEFAULT_LIMIT, 0, $this->anything())
			->willReturn(['transactions' => [], 'total' => 0]);

		$this->assertSame(Http::STATUS_OK, $this->controller->recent()->getStatus());
	}

	public function testRecentExcludesFutureScheduledRows(): void {
		// A glanceable capture list led by next week's scheduled bills
		// buries today's capture.
		$this->service->expects($this->once())
			->method('findWithFilters')
			->with('user1', $this->callback(fn ($f) => ($f['dateTo'] ?? null) === date('Y-m-d')),
				$this->anything(), $this->anything(), $this->anything())
			->willReturn(['transactions' => [], 'total' => 0]);

		$this->controller->recent();
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

	// ── create: the handoff dialect ─────────────────────────────────

	public function testCreateAcceptsTheHandoffFieldsAndDefaultsToDebit(): void {
		$this->params = $this->captureParams();

		// merchant becomes both the description and the vendor; the capture
		// app records spending, so type defaults to debit.
		$this->service->expects($this->once())
			->method('create')
			->with('user1', 1, '2026-08-01', 'Weekly shop', 42.5, 'debit', null, 'Tesco', null, null)
			->willReturn($this->transaction());

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(10, $response->getData()['id']);
	}

	public function testCreateStillAcceptsThePreHandoffNames(): void {
		$this->params = [
			'accountId' => 1, 'date' => '2026-08-01', 'description' => 'Weekly shop',
			'amount' => 42.5, 'type' => 'credit', 'categoryId' => 5,
		];

		$this->service->expects($this->once())
			->method('create')
			->with('user1', 1, '2026-08-01', 'Weekly shop', 42.5, 'credit', 5, null, null, null)
			->willReturn($this->transaction());

		$this->assertSame(Http::STATUS_CREATED, $this->controller->create()->getStatus());
	}

	public function testCreateRequiresAnAmount(): void {
		$this->params = $this->captureParams(['amount' => 'lots']);
		$this->service->expects($this->never())->method('create');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}

	public function testCreateRequiresAnAccount(): void {
		$this->params = $this->captureParams(['account_id' => '0']);
		$this->service->expects($this->never())->method('create');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create()->getStatus());
	}

	public function testCreateWritesSharedAccountRowsUnderTheAccountOwner(): void {
		// Account 9 is visible but not owned. The row must land in the owner's
		// ledger — writing it under the acting user would orphan it.
		$account = new Account();
		$account->setId(9);
		$account->setUserId('owner2');

		$this->params = $this->captureParams(['account_id' => '9']);

		$this->granularShareService->expects($this->once())
			->method('requireWriteAccess')
			->with('user1', 'account', 9);
		$this->service->method('findAccountById')->with(9)->willReturn($account);
		$this->service->expects($this->once())
			->method('create')
			->with('owner2', 9, $this->anything(), $this->anything(), $this->anything(), $this->anything())
			->willReturn($this->transaction());

		$this->assertSame(Http::STATUS_CREATED, $this->controller->create()->getStatus());
	}

	public function testCreateReturnsForbiddenOnAReadOnlyShare(): void {
		$this->params = $this->captureParams(['account_id' => '9']);
		$this->granularShareService->method('requireWriteAccess')
			->willThrowException(new ReadOnlyShareException());
		$this->service->expects($this->never())->method('create');

		$this->assertSame(Http::STATUS_FORBIDDEN, $this->controller->create()->getStatus());
	}

	public function testCreateRejectsAnUnknownType(): void {
		$this->params = $this->captureParams(['type' => 'sideways']);
		$this->service->expects($this->never())->method('create');

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid transaction type. Must be credit or debit', $response->getData()['error']);
	}

	public function testCreateRejectsAnInvalidDescription(): void {
		$validationService = $this->createMock(ValidationService::class);
		$validationService->method('validateDescription')
			->willReturn(['valid' => false, 'error' => 'Description is required']);
		$validationService->method('validateDate')->willReturn(['valid' => true]);
		$validationService->method('validateVendor')->willReturn(['valid' => true, 'sanitized' => 'Tesco']);

		$controller = $this->buildController($this->idempotencyKeys, $validationService);
		$this->params = $this->captureParams();
		$this->service->expects($this->never())->method('create');

		$response = $controller->create();

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Description is required', $response->getData()['error']);
	}

	public function testCreateReturnsNotFoundForAnUnknownAccount(): void {
		$this->params = $this->captureParams();
		$this->service->method('create')->willThrowException(new DoesNotExistException('no account'));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Account not found', $response->getData()['error']);
	}

	// ── create: the inline photo ────────────────────────────────────

	public function testCreateAttachesAnInlinePhoto(): void {
		$this->params = $this->captureParams();
		$this->uploads['photo'] = ['name' => 'r.jpg', 'tmp_name' => '/tmp/r', 'error' => UPLOAD_ERR_OK, 'size' => 10];

		$this->service->method('create')->willReturn($this->transaction());
		$this->attachmentService->expects($this->once())
			->method('upload')
			->with(10, 'user1', $this->uploads['photo']);

		$this->assertSame(Http::STATUS_CREATED, $this->controller->create()->getStatus());
	}

	public function testAPhotoOnASharedAccountIsAttachedUnderTheOwner(): void {
		// Like the transaction itself: receipts live in the owner's Files,
		// and an acting-user attach on a shared account can never succeed.
		$account = new Account();
		$account->setId(9);
		$account->setUserId('owner2');

		$this->params = $this->captureParams(['account_id' => '9']);
		$this->uploads['photo'] = ['name' => 'r.jpg', 'error' => UPLOAD_ERR_OK];

		$this->service->method('findAccountById')->with(9)->willReturn($account);
		$this->service->method('create')->willReturn($this->transaction());
		$this->attachmentService->expects($this->once())
			->method('upload')
			->with(10, 'owner2', $this->uploads['photo']);

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertArrayNotHasKey('photo_error', $response->getData());
	}

	public function testAnEmptyCategoryFieldMeansUncategorisedNotCategoryZero(): void {
		$this->params = $this->captureParams(['category_id' => '']);

		$this->service->expects($this->once())
			->method('create')
			->with('user1', 1, $this->anything(), $this->anything(), $this->anything(), $this->anything(),
				null, $this->anything(), $this->anything(), $this->anything())
			->willReturn($this->transaction());

		$this->assertSame(Http::STATUS_CREATED, $this->controller->create()->getStatus());
	}

	public function testCreateWithoutAPhotoDoesNotTouchAttachments(): void {
		// Quick Add posts with the photo part omitted entirely.
		$this->params = $this->captureParams();
		$this->service->method('create')->willReturn($this->transaction());
		$this->attachmentService->expects($this->never())->method('upload');

		$this->assertSame(Http::STATUS_CREATED, $this->controller->create()->getStatus());
	}

	public function testAFailedPhotoAttachDoesNotFailTheRecordedTransaction(): void {
		// The transaction is committed; failing the request now would make
		// the client retry and duplicate the one thing the key protects.
		$this->params = $this->captureParams();
		$this->uploads['photo'] = ['name' => 'r.jpg', 'error' => UPLOAD_ERR_OK];
		$this->service->method('create')->willReturn($this->transaction());
		$this->attachmentService->method('upload')->willThrowException(new \RuntimeException('quota'));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(10, $response->getData()['id']);
		$this->assertArrayHasKey('photo_error', $response->getData());
	}

	// ── create: idempotency ─────────────────────────────────────────

	/** A mapper whose key is already held, pointing at the given tx id. */
	private function heldKeyMapper(int $transactionId): IdempotencyKeyMapper {
		$existing = new IdempotencyKey();
		$existing->setUserId('user1');
		$existing->setIdemKey('uuid-1');
		$existing->setTransactionId($transactionId);

		$keys = $this->createMock(IdempotencyKeyMapper::class);
		$keys->method('insert')->willThrowException(new \RuntimeException('unique violation'));
		$keys->method('findByKey')->willReturn($existing);

		return $keys;
	}

	public function testAReplayedKeyAnswersWithTheExistingTransactionAndCreatesNothing(): void {
		$this->params = $this->captureParams(['idempotency_key' => 'uuid-1']);

		$controller = $this->buildController($this->heldKeyMapper(10), $this->validationService);

		$this->service->method('findForAccounts')->with(10, [1, 2, 9])->willReturn($this->transaction());
		$this->service->expects($this->never())->method('create');

		$response = $controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(10, $response->getData()['id']);
	}

	public function testAFreshKeyIsReservedBeforeCreateAndFinalisedAfter(): void {
		// The reservation-first order is the whole race fix: the key row
		// exists (transaction_id 0) before any transaction is inserted, so
		// a concurrent identical POST can never also reach create().
		$this->params = $this->captureParams(['idempotency_key' => 'uuid-2']);

		$order = [];
		$keys = $this->createMock(IdempotencyKeyMapper::class);
		$keys->expects($this->once())->method('insert')
			->willReturnCallback(function (IdempotencyKey $k) use (&$order) {
				$order[] = 'reserve';
				$this->assertSame('user1', $k->getUserId());
				$this->assertSame('uuid-2', $k->getIdemKey());
				$this->assertSame(0, $k->getTransactionId());

				return $k;
			});
		$keys->expects($this->once())->method('purgeOlderThan');
		$keys->expects($this->once())->method('update')
			->willReturnCallback(function (IdempotencyKey $k) use (&$order) {
				$order[] = 'finalise';
				$this->assertSame(10, $k->getTransactionId());

				return $k;
			});

		$this->service->method('create')->willReturnCallback(function () use (&$order) {
			$order[] = 'create';

			return $this->transaction();
		});

		$controller = $this->buildController($keys, $this->validationService);

		$this->assertSame(Http::STATUS_CREATED, $controller->create()->getStatus());
		$this->assertSame(['reserve', 'create', 'finalise'], $order);
	}

	public function testTheKeyIsAlsoReadFromTheHeader(): void {
		$this->params = $this->captureParams();
		$this->idemHeader = 'uuid-3';

		$keys = $this->createMock(IdempotencyKeyMapper::class);
		$keys->expects($this->once())->method('insert')
			->with($this->callback(fn (IdempotencyKey $k) => $k->getIdemKey() === 'uuid-3'))
			->willReturnArgument(0);
		$this->service->method('create')->willReturn($this->transaction());

		$this->buildController($keys, $this->validationService)->create();
	}

	public function testAnEmptyKeyFieldFallsThroughToTheHeader(): void {
		// idempotency_key= (sent but empty) must not silently disable the
		// key the client also put in the header.
		$this->params = $this->captureParams(['idempotency_key' => '  ']);
		$this->idemHeader = 'uuid-6';

		$keys = $this->createMock(IdempotencyKeyMapper::class);
		$keys->expects($this->once())->method('insert')
			->with($this->callback(fn (IdempotencyKey $k) => $k->getIdemKey() === 'uuid-6'))
			->willReturnArgument(0);
		$this->service->method('create')->willReturn($this->transaction());

		$this->buildController($keys, $this->validationService)->create();
	}

	public function testAKeylessPostNeverTouchesTheIdempotencyTable(): void {
		$this->params = $this->captureParams();
		$this->service->method('create')->willReturn($this->transaction());

		$keys = $this->createMock(IdempotencyKeyMapper::class);
		$keys->expects($this->never())->method('insert');
		$keys->expects($this->never())->method('findByKey');
		$keys->expects($this->never())->method('purgeOlderThan');

		$this->assertSame(Http::STATUS_CREATED, $this->buildController($keys, $this->validationService)->create()->getStatus());
	}

	public function testAKeyWhoseTransactionWasDeletedIsForgottenNotHonoured(): void {
		$this->params = $this->captureParams(['idempotency_key' => 'uuid-4']);

		$stale = new IdempotencyKey();
		$stale->setUserId('user1');
		$stale->setIdemKey('uuid-4');
		$stale->setTransactionId(99);

		// First claim loses to the stale row; after it is deleted, the
		// second claim succeeds and the create proceeds.
		$attempt = 0;
		$keys = $this->createMock(IdempotencyKeyMapper::class);
		$keys->method('insert')->willReturnCallback(function (IdempotencyKey $k) use (&$attempt) {
			if (++$attempt === 1) {
				throw new \RuntimeException('unique violation');
			}

			return $k;
		});
		$keys->method('findByKey')->willReturn($stale);
		$keys->expects($this->once())->method('delete')->with($stale);
		$controller = $this->buildController($keys, $this->validationService);

		$this->service->method('findForAccounts')->willThrowException(new DoesNotExistException('gone'));
		$this->service->expects($this->once())->method('create')->willReturn($this->transaction());

		$this->assertSame(Http::STATUS_CREATED, $controller->create()->getStatus());
	}

	public function testReusingAKeyForADifferentPurchaseIsAConflictNotAReplay(): void {
		// Same key, different amount: answering with the OTHER purchase's
		// numbers would hide a client bug behind a plausible 201.
		$this->params = $this->captureParams(['idempotency_key' => 'uuid-1', 'amount' => '99.99']);

		$controller = $this->buildController($this->heldKeyMapper(10), $this->validationService);
		$this->service->method('findForAccounts')->willReturn($this->transaction()); // amount 42.5
		$this->service->expects($this->never())->method('create');

		$response = $controller->create();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('idempotency_key_conflict', $response->getData()['error_code']);
	}

	public function testAnInFlightWinnerYieldsRequestInFlightNotADuplicate(): void {
		// The winner holds the reservation (transaction_id 0) for the whole
		// wait: the loser must give a retryable 409, never a second insert.
		$this->params = $this->captureParams(['idempotency_key' => 'uuid-1']);

		$keys = $this->heldKeyMapper(0);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn ($text, $parameters = []) => vsprintf($text, $parameters));
		$controller = new class($this->request, $this->service, $this->attachmentService, $this->validationService, $this->granularShareService, $keys, $l, 'user1', $this->createMock(LoggerInterface::class)) extends ApiV1TransactionController {
			protected function waitForInFlightWinner(): void {
				// No sleeping in unit tests.
			}
		};

		$this->service->expects($this->never())->method('create');

		$response = $controller->create();

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('request_in_flight', $response->getData()['error_code']);
	}

	public function testAFailedCreateReleasesTheReservation(): void {
		// A reservation surviving a failed create would make every honest
		// retry of this key wait on a ghost, then 409 forever.
		$this->params = $this->captureParams(['idempotency_key' => 'uuid-7']);

		$keys = $this->createMock(IdempotencyKeyMapper::class);
		$keys->method('insert')->willReturnArgument(0);
		$keys->expects($this->once())->method('delete');
		$controller = $this->buildController($keys, $this->validationService);

		$this->service->method('create')->willThrowException(new \RuntimeException('DB error'));

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->create()->getStatus());
	}

	public function testAFailedReservationFinaliseNeverFailsTheRecordedTransaction(): void {
		$this->params = $this->captureParams(['idempotency_key' => 'uuid-8']);
		$this->service->method('create')->willReturn($this->transaction());
		$this->idempotencyKeys->method('update')->willThrowException(new \RuntimeException('hiccup'));

		$response = $this->controller->create();

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(10, $response->getData()['id']);
	}

	public function testAReplayedRetryHealsAMissingPhoto(): void {
		// First attempt recorded the transaction but its photo attach
		// failed and the response was lost. The faithful retry carries the
		// photo again — the replay attaches it instead of dropping it.
		$this->params = $this->captureParams(['idempotency_key' => 'uuid-1']);
		$this->uploads['photo'] = ['name' => 'r.jpg', 'error' => UPLOAD_ERR_OK];

		$account = new Account();
		$account->setId(1);
		$account->setUserId('user1');

		$controller = $this->buildController($this->heldKeyMapper(10), $this->validationService);
		$this->service->method('findForAccounts')->willReturn($this->transaction());
		$this->service->method('findAccountById')->with(1)->willReturn($account);
		$this->attachmentService->method('listForTransaction')->with(10, 'user1')->willReturn([]);
		$this->attachmentService->expects($this->once())
			->method('upload')
			->with(10, 'user1', $this->uploads['photo']);
		$this->service->expects($this->never())->method('create');

		$this->assertSame(Http::STATUS_CREATED, $controller->create()->getStatus());
	}

	public function testAnOverlongKeyIsRejected(): void {
		$this->params = $this->captureParams(['idempotency_key' => str_repeat('k', 65)]);
		$this->service->expects($this->never())->method('create');

		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller->create()->getStatus());
	}

	// ── receipts ────────────────────────────────────────────────────

	public function testReceiptsReturnsSerializedAttachments(): void {
		$this->attachmentService->method('listForTransaction')->with(10, 'user1')->willReturn([
			['id' => 1, 'transactionId' => 10, 'fileId' => 85, 'fileName' => 'r.png', 'missing' => false, 'isImage' => true],
		]);

		$data = $this->controller->receipts(10)->getData();

		$this->assertCount(1, $data);
		$this->assertSame(85, $data[0]['file_id']);
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

		$this->uploads['file'] = [
			'name' => 'r.png', 'tmp_name' => '/tmp/r.png', 'error' => UPLOAD_ERR_OK, 'size' => 100,
		];
		$this->attachmentService->method('upload')->willReturn($attachment);

		$response = $this->controller->uploadReceipt(10);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertSame(85, $response->getData()['file_id']);
	}

	public function testUploadReceiptRequiresAFile(): void {
		$this->attachmentService->expects($this->never())->method('upload');

		$response = $this->controller->uploadReceipt(10);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('No file uploaded', $response->getData()['error']);
	}

	public function testUploadReceiptSurfacesTheRejectionReason(): void {
		$this->uploads['file'] = ['name' => 'r.txt', 'error' => UPLOAD_ERR_OK];
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
			$this->idempotencyKeys,
			$this->createMock(IL10N::class),
			null,
			$this->createMock(LoggerInterface::class)
		);

		$this->assertInstanceOf(ApiV1TransactionController::class, $controller);
	}
}
