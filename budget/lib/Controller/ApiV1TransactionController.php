<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\IdempotencyKey;
use OCA\Budget\Db\IdempotencyKeyMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionSplit;
use OCA\Budget\Exception\ReadOnlyShareException;
use OCA\Budget\Service\AttachmentService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\MoneyCalculator;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\UserClock;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Transactions over the public REST API (v1): read recent activity, record
 * a new transaction with an optional receipt photo, and edit or delete one.
 *
 * Editing and deleting came later (discussion 412), for full clients whose
 * users may never open the web UI. They run through the same services as
 * the web UI, with one difference: where the web UI warns before a change,
 * these refuse it with a 409 and an error_code, because a client has no
 * dialog to show. Moving a transaction to another account, its status and
 * its reconciled flag stay web-only.
 */
class ApiV1TransactionController extends OCSController {
	use ApiErrorHandlerTrait;
	use SharedAccessTrait;

	/** Page size ceiling. Requests above it are clamped, not rejected. */
	public const MAX_LIMIT = 200;
	public const DEFAULT_LIMIT = 50;

	/** How long a spent idempotency key answers with its transaction. */
	private const IDEM_RETENTION_DAYS = 7;

	/** Read by SharedAccessTrait. */
	protected string $userId;

	public function __construct(
		IRequest $request,
		private TransactionService $service,
		private AttachmentService $attachmentService,
		private TransactionSplitService $splitService,
		private ValidationService $validationService,
		GranularShareService $granularShareService,
		private IdempotencyKeyMapper $idempotencyKeys,
		private IL10N $l,
		?string $userId,
		LoggerInterface $logger,
		private ?UserClock $userClock = null,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->setLogger($logger);
		$this->setGranularShareService($granularShareService);
		// Null until the security middleware rejects the request — see
		// ApiV1Controller for why this must not be typed non-null.
		$this->userId = $userId ?? '';
	}

	/**
	 * Most recent transactions first, across every account the user can see.
	 *
	 * @param int|null $accountId Restrict to one account.
	 * @param int|null $categoryId Restrict to one category.
	 * @param string|null $dateFrom Inclusive lower bound, YYYY-MM-DD.
	 * @param string|null $dateTo Inclusive upper bound, YYYY-MM-DD.
	 * @param string|null $search Free-text match on description/vendor.
	 */
	#[NoAdminRequired]
	public function index(
		?int $accountId = null,
		?int $categoryId = null,
		?string $dateFrom = null,
		?string $dateTo = null,
		?string $search = null,
	): DataResponse {
		// limit and offset are read by hand, like recent()'s: Nextcloud 35
		// range-checks any bound parameter named `limit` (1-500) and answers
		// an empty 400 before this runs, where the docs promise a clamp
		$rawLimit = $this->request->getParam('limit');
		$rawOffset = $this->request->getParam('offset');
		$limit = max(1, min(is_numeric($rawLimit) ? (int)$rawLimit : self::DEFAULT_LIMIT, self::MAX_LIMIT));
		$offset = max(0, is_numeric($rawOffset) ? (int)$rawOffset : 0);

		foreach (['dateFrom' => $dateFrom, 'dateTo' => $dateTo] as $field => $value) {
			if ($value !== null && !$this->validationService->validateDate($value, $field, false)['valid']) {
				return new DataResponse(
					['error' => $this->l->t('Invalid date. Use the YYYY-MM-DD format')],
					Http::STATUS_BAD_REQUEST
				);
			}
		}

		try {
			$filters = [
				'accountId' => $accountId,
				'category' => $categoryId !== null ? (string)$categoryId : null,
				'dateFrom' => $dateFrom,
				'dateTo' => $dateTo,
				'search' => $search,
				'sort' => 'date',
				'direction' => 'desc',
			];

			$visible = $this->getEffectiveAccountIds();
			$result = $this->service->findWithFilters(
				$this->userId,
				$filters,
				$limit,
				$offset,
				$visible
			);

			return new DataResponse([
				'transactions' => ApiSerializer::map(
					TransactionService::hideUnseenLinkedAccounts($result['transactions'], $visible),
					[ApiSerializer::class, 'transaction']
				),
				'total' => (int)$result['total'],
				'limit' => $limit,
				'offset' => $offset,
			]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve transactions'));
		}
	}

	/**
	 * The capture app's glanceable list: the newest transactions across
	 * every visible account, flat and merchant-first, exactly as the app's
	 * handoff contract shapes them. GET /transactions remains the full
	 * filterable surface for everything else.
	 */
	#[NoAdminRequired]
	public function recent(): DataResponse {
		// Read by hand: the docs promise a nonsense limit is clamped, never
		// a 500 — framework int-binding would fatal on limit=abc.
		$rawLimit = $this->request->getParam('limit');
		$limit = is_numeric($rawLimit) ? (int)$rawLimit : self::DEFAULT_LIMIT;
		$limit = max(1, min($limit, self::MAX_LIMIT));

		try {
			$visible = $this->getEffectiveAccountIds();
			$result = $this->service->findWithFilters(
				$this->userId,
				[
					'sort' => 'date',
					'direction' => 'desc',
					// Recorded activity only: a glanceable capture list led
					// by next week's scheduled bills buries today's capture.
					// The user's today: a capture is cleared on their date,
					// which the server's UTC one hid until it caught up.
					'dateTo' => $this->userClock?->today($this->userId) ?? date('Y-m-d'),
				],
				$limit,
				0,
				$visible
			);

			return new DataResponse(ApiSerializer::map(
				TransactionService::hideUnseenLinkedAccounts($result['transactions'], $visible),
				[ApiSerializer::class, 'recentTransaction']
			));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve transactions'));
		}
	}

	#[NoAdminRequired]
	public function show(int $id): DataResponse {
		try {
			$visible = $this->getEffectiveAccountIds();
			$transaction = $this->service->findForAccounts($id, $visible);

			return new DataResponse($this->serializeOne($transaction, $visible));
		} catch (DoesNotExistException $e) {
			return $this->notFound();
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve transaction'));
		}
	}

	/**
	 * The parts of a split transaction (#408), [] when it isn't split. List
	 * rows already carry them inline; this is the same shape for one record.
	 */
	#[NoAdminRequired]
	public function splits(int $id): DataResponse {
		try {
			$transaction = $this->service->findForAccounts($id, $this->getEffectiveAccountIds());
			// Same guard as serializeOne(): parts left behind on a row that is
			// no longer split (kept on purpose, #356) are not its splits
			$parts = $transaction->getIsSplit() === false ? [] : $this->splitsOf($transaction);

			return new DataResponse(['splits' => ApiSerializer::splits($parts)]);
		} catch (DoesNotExistException $e) {
			return $this->notFound();
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve transaction'));
		}
	}

	/**
	 * One transaction with what a list row has joined in: its split parts
	 * (#408) and its transfer partner's account name (#407), the name only
	 * when the caller can see that account.
	 *
	 * @param int[] $visibleAccountIds
	 */
	private function serializeOne(Transaction $transaction, array $visibleAccountIds): array {
		$row = $transaction->jsonSerialize();
		// is_split is tri-state (#360): false means no parts, NULL predates
		// the column and is a split only if parts come back, as on list rows
		$parts = $transaction->getIsSplit() === false ? [] : $this->splitsOf($transaction);
		$row['splitCategories'] = $parts;
		$row['isSplit'] = $parts !== [];
		$row['linkedAccountName'] = $this->linkedAccountName($transaction, $visibleAccountIds);

		return ApiSerializer::transaction($row);
	}

	/**
	 * A transaction's split parts, read as its account's owner: getSplits()
	 * checks ownership against the user id it is given, so a recipient's id
	 * would find nothing on a shared account.
	 *
	 * @return TransactionSplit[]
	 */
	private function splitsOf(Transaction $transaction): array {
		$owner = $this->granularShareService->resolveOwner($this->userId, 'account', $transaction->getAccountId());
		if ($owner === null) {
			return [];
		}
		return $this->splitService->getSplits($transaction->getId(), $owner);
	}

	/** @param int[] $visibleAccountIds */
	private function linkedAccountName(Transaction $transaction, array $visibleAccountIds): ?string {
		$linkedId = $transaction->getLinkedTransactionId();
		if ($linkedId === null) {
			return null;
		}
		try {
			$linked = $this->service->findForAccounts($linkedId, $visibleAccountIds);
			return $this->service->findAccountById($linked->getAccountId())->getName();
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Record a transaction.
	 *
	 * Parameters are read by hand rather than auto-bound because the wire
	 * dialect is the capture app's handoff contract (snake_case, a single
	 * `merchant` field, an optional inline `photo`, an idempotency key),
	 * while the pre-handoff names are still accepted for anyone who scripted
	 * against the unreleased draft of this API:
	 *
	 * - account_id (int, required), category_id (int, optional)
	 * - date (YYYY-MM-DD, required; a future date is stored as scheduled)
	 * - merchant (string) — becomes both the description and the vendor.
	 *   description/vendor are still accepted separately and win over it.
	 * - amount (required; "24.31" or 24.31 — always positive, `type` carries
	 *   the direction and defaults to 'debit', which is what a capture
	 *   client records)
	 * - photo (multipart file, optional) — attached as a receipt after the
	 *   transaction is recorded. OMITTED entirely when there is none.
	 * - idempotency_key (or an Idempotency-Key header): a repeat of a key
	 *   seen in the last week answers with the transaction the first
	 *   attempt recorded instead of inserting twice. A mobile client that
	 *   times out cannot know whether the POST committed; this makes the
	 *   retry safe instead of a gamble.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function create(): DataResponse {
		$p = $this->request->getParams();

		$accountId = (int)($p['account_id'] ?? $p['accountId'] ?? 0);
		// An empty category field means "uncategorised" (stored as NULL) —
		// (int)'' would silently mean "category 0", which nothing can filter.
		$categoryRaw = $p['category_id'] ?? $p['categoryId'] ?? null;
		$categoryId = is_numeric($categoryRaw) && (int)$categoryRaw > 0 ? (int)$categoryRaw : null;
		$date = (string)($p['date'] ?? '');
		$merchant = isset($p['merchant']) ? trim((string)$p['merchant']) : '';
		$description = trim((string)($p['description'] ?? '')) ?: $merchant;
		$vendor = trim((string)($p['vendor'] ?? '')) ?: ($merchant !== '' ? $merchant : null);
		$type = (string)($p['type'] ?? 'debit');
		$reference = isset($p['reference']) ? (string)$p['reference'] : null;
		$notes = isset($p['notes']) ? (string)$p['notes'] : null;

		$amountRaw = $p['amount'] ?? null;
		if (!is_numeric(is_string($amountRaw) ? trim($amountRaw) : $amountRaw)) {
			return new DataResponse(['error' => $this->l->t('Amount must be a number')], Http::STATUS_BAD_REQUEST);
		}
		$amount = (float)$amountRaw;
		// The direction is `type`'s job. A negative amount stored the row
		// reversed; zero stays allowed, as in the web UI's form.
		if ($amount < 0) {
			return $this->badAmount($this->l->t('Amount cannot be negative. Use type to say which way the money went'));
		}

		if ($accountId <= 0) {
			return new DataResponse(['error' => $this->l->t('An account is required')], Http::STATUS_BAD_REQUEST);
		}

		$fields = [
			'description' => $this->validationService->validateDescription($description, true),
			'date' => $this->validationService->validateDate($date, $this->l->t('Date'), true),
		];
		foreach (['vendor' => $vendor, 'reference' => $reference, 'notes' => $notes] as $name => $value) {
			if ($value !== null) {
				$fields[$name] = match ($name) {
					'vendor' => $this->validationService->validateVendor($value),
					'reference' => $this->validationService->validateReference($value),
					'notes' => $this->validationService->validateNotes($value),
				};
			}
		}

		foreach ($fields as $result) {
			if (!$result['valid']) {
				return new DataResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
			}
		}

		if (!in_array($type, ['credit', 'debit'], true)) {
			return new DataResponse(
				['error' => $this->l->t('Invalid transaction type. Must be credit or debit')],
				Http::STATUS_BAD_REQUEST
			);
		}

		// The field wins over the header; an EMPTY field falls through to
		// the header rather than silently disabling idempotency.
		$idemKey = trim((string)($p['idempotency_key'] ?? ''));
		if ($idemKey === '') {
			$idemKey = trim($this->request->getHeader('Idempotency-Key'));
		}
		if (mb_strlen($idemKey) > 64) {
			return new DataResponse(['error' => $this->l->t('Idempotency key too long (64 characters maximum)')], Http::STATUS_BAD_REQUEST);
		}

		try {
			// Reserve the key BEFORE creating anything. Check-then-insert
			// raced: two overlapping retries both missed the lookup, both
			// created, and the ledger showed the purchase twice (reproduced
			// live). The unique index makes exactly one reservation win, so
			// exactly one request can ever reach the create below.
			$reservation = null;
			if ($idemKey !== '') {
				$acquired = $this->acquireIdempotencyKey($idemKey, $accountId, $amount);
				if ($acquired instanceof DataResponse) {
					return $acquired;
				}
				$reservation = $acquired;
			}

			try {
				// A shared account still belongs to whoever created it, so the
				// row must be written under the owner's id — writing it under
				// the acting user's id would orphan it from the account's
				// ledger. Checked inside this try: a refusal must release the
				// key like any other failure before the insert.
				$effectiveUserId = $this->userId;
				if (!in_array($accountId, $this->granularShareService->getOwnAccountIds($this->userId), true)) {
					// One the caller can't see is not found, as every other id
					// outside their accounts is; 403 is for a read-only share
					if (!$this->canAccessEntity('account', $accountId)) {
						throw new DoesNotExistException('Account not visible to the caller');
					}
					$this->requireWriteAccess('account', $accountId);
					$effectiveUserId = $this->service->findAccountById($accountId)->getUserId();
				}

				// The row lands in the owner's ledger: a category the owner
				// cannot see is refused rather than stored (its name would
				// come back on every read)
				$this->requireOwnersCategory($effectiveUserId, $categoryId);

				$transaction = $this->service->create(
					$effectiveUserId,
					$accountId,
					$date,
					$fields['description']['sanitized'],
					$amount,
					$type,
					$categoryId,
					isset($fields['vendor']) ? $fields['vendor']['sanitized'] : null,
					isset($fields['reference']) ? $fields['reference']['sanitized'] : null,
					isset($fields['notes']) ? $fields['notes']['sanitized'] : null,
				);
			} catch (\Throwable $e) {
				// The reservation must not outlive a failed create, or every
				// honest retry of this key would wait on a ghost.
				$this->releaseReservation($reservation);
				throw $e;
			}

			if ($reservation !== null) {
				try {
					$reservation->setTransactionId($transaction->getId());
					$this->idempotencyKeys->update($reservation);
				} catch (\Throwable $e) {
					$this->logger?->warning('Idempotency reservation not finalised: ' . $e->getMessage(), [
						'app' => Application::APP_ID,
					]);
				}
			}

			// The optional inline receipt, attached under the LEDGER OWNER —
			// like the transaction itself, and because receipts live in the
			// owner's Files (acting-user attach on a shared account can never
			// succeed). A failed attach must not fail the request: the
			// transaction is recorded, and a retry would duplicate the very
			// thing the key protects.
			$out = ApiSerializer::transaction($transaction);
			$photo = $this->request->getUploadedFile('photo');
			if ($photo) {
				try {
					$this->attachmentService->upload($transaction->getId(), $effectiveUserId, $photo, $this->userId);
				} catch (\Throwable $e) {
					$this->logger?->warning('Receipt attach during create failed: ' . $e->getMessage(), [
						'app' => Application::APP_ID,
					]);
					$out['photo_error'] = $this->l->t('The transaction was recorded, but the photo could not be attached');
				}
			}

			// Optional per-item splits, for a capture app that read a receipt
			// and had the user categorise each line. Handled like the photo
			// above: the transaction is already recorded and idempotency-keyed,
			// so a rejected split set reports itself rather than failing the
			// request — a retry would duplicate the very thing the key guards.
			// The total is unaffected either way, so the fallback state is a
			// correct unsplit transaction the user can split later.
			$splits = $this->readSplitsParam();
			if ($splits !== null) {
				try {
					$created = $this->splitService->splitTransaction($transaction->getId(), $effectiveUserId, $splits);
					$out['splits'] = ApiSerializer::splits($created);
					// The transaction was serialised before the split ran, so
					// its flags are stale: splitting sets is_split and clears
					// the category. Correct them rather than re-reading the
					// row — a client trusting is_split from this response
					// would otherwise believe the split never happened.
					$out['is_split'] = true;
					$out['category_id'] = null;
				} catch (\Throwable $e) {
					// Stated rather than left to the snapshot: a client that
					// checks splits_error sees no parts next to it, ever
					$out['splits'] = [];
					$out['splits_error'] = $e instanceof \InvalidArgumentException
						? $e->getMessage()
						: $this->l->t('The transaction was recorded, but it could not be split');
				}
			}

			return new DataResponse($out, Http::STATUS_CREATED);
		} catch (DoesNotExistException $e) {
			return $this->notFound($this->l->t('Account not found'));
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to create transaction'));
		}
	}

	/** How long and how often a loser waits for an in-flight winner. */
	private const IDEM_POLL_ATTEMPTS = 8;

	/**
	 * Claim the key, or answer for it.
	 *
	 * Returns the reservation row (transaction_id 0) when this request now
	 * owns the key and must proceed to create — or a ready DataResponse:
	 * a 201 replay of what the key already recorded, a 409 when the key is
	 * being reused for a DIFFERENT purchase, or a 409 when the winning
	 * request is still in flight and did not finish within the wait.
	 */
	private function acquireIdempotencyKey(string $idemKey, int $accountId, float $amount): IdempotencyKey|DataResponse {
		// Retention housekeeping, deliberately outside the reservation try:
		// a purge hiccup must not disable idempotency for this request.
		try {
			$this->idempotencyKeys->purgeOlderThan(
				new \DateTimeImmutable('-' . self::IDEM_RETENTION_DAYS . ' days')
			);
		} catch (\Throwable $e) {
			$this->logger?->debug('Idempotency purge skipped: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
			]);
		}

		for ($attempt = 0; $attempt <= self::IDEM_POLL_ATTEMPTS; $attempt++) {
			try {
				$row = new IdempotencyKey();
				$row->setUserId($this->userId);
				$row->setIdemKey($idemKey);
				$row->setTransactionId(0);
				$row->setCreatedAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));

				return $this->idempotencyKeys->insert($row);
			} catch (\Throwable $e) {
				// Lost the unique index — someone holds this key. Replay them.
			}

			try {
				$existing = $this->idempotencyKeys->findByKey($this->userId, $idemKey);
			} catch (DoesNotExistException $e) {
				// The holder rolled back between our insert and lookup —
				// loop and try to claim it ourselves.
				continue;
			}

			if ($existing->getTransactionId() === 0) {
				// The winner is still executing. Wait for it rather than
				// creating a duplicate — this is exactly the timeout-retry
				// the key exists for.
				$this->waitForInFlightWinner();
				continue;
			}

			try {
				$transaction = $this->service->findForAccounts(
					$existing->getTransactionId(),
					$this->getEffectiveAccountIds()
				);
			} catch (DoesNotExistException $e) {
				// The recorded transaction is gone (deleted in the web UI).
				// The client is recording it again on purpose: forget the
				// key and loop to claim it fresh.
				try {
					$this->idempotencyKeys->delete($existing);
				} catch (\Throwable $ignored) {
				}
				continue;
			}

			// Same key, different purchase = a client bug worth surfacing,
			// not silently answering with someone else's numbers.
			if ($transaction->getAccountId() !== $accountId
				|| !MoneyCalculator::equals($transaction->getAmount(), $amount)) {
				return new DataResponse([
					'error' => $this->l->t('This idempotency key was already used for a different transaction'),
					'error_code' => 'idempotency_key_conflict',
				], Http::STATUS_CONFLICT);
			}

			return new DataResponse($this->replayResponse($transaction), Http::STATUS_CREATED);
		}

		return new DataResponse([
			'error' => $this->l->t('This request is still being processed. Try again shortly'),
			'error_code' => 'request_in_flight',
		], Http::STATUS_CONFLICT);
	}

	/**
	 * The replayed transaction — healing the receipt on the way: if the
	 * retry carries a photo and the recorded transaction has none (the first
	 * attempt's attach failed, or died before it), attach it now. That turns
	 * "retry and the receipt is silently gone" into the recovery the client
	 * expects from an idempotent retry.
	 */
	private function replayResponse(Transaction $transaction): array {
		// With its parts, so replaying a split capture reports them like the
		// first response did
		$out = $this->serializeOne($transaction, $this->getEffectiveAccountIds());

		$photo = $this->request->getUploadedFile('photo');
		if ($photo) {
			try {
				$ownerId = $this->service->findAccountById($transaction->getAccountId())->getUserId();
				if ($this->attachmentService->listForTransaction($transaction->getId(), $ownerId) === []) {
					$this->attachmentService->upload($transaction->getId(), $ownerId, $photo, $this->userId);
				}
			} catch (\Throwable $e) {
				$this->logger?->warning('Receipt attach during replay failed: ' . $e->getMessage(), [
					'app' => Application::APP_ID,
				]);
				$out['photo_error'] = $this->l->t('The transaction was recorded, but the photo could not be attached');
			}
		}

		return $out;
	}

	/** Overridable so tests do not sleep. */
	protected function waitForInFlightWinner(): void {
		usleep(500000);
	}

	private function releaseReservation(?IdempotencyKey $reservation): void {
		if ($reservation === null) {
			return;
		}
		try {
			$this->idempotencyKeys->delete($reservation);
		} catch (\Throwable $e) {
			$this->logger?->debug('Idempotency reservation not released: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
			]);
		}
	}

	/**
	 * Split a transaction into per-category allocations.
	 *
	 * Added for capture apps that read a receipt and let the user categorise
	 * each item (#537 follow-up): a receipt's line items are exactly a set of
	 * splits, and without this the API could only ever record one category
	 * for a whole shop.
	 *
	 * Replaces any existing splits — this is a PUT in spirit, kept as POST to
	 * match the web route it shares a service with. The parts must sum to the
	 * transaction amount and there must be at least two; both are the split
	 * service's rules, not this endpoint's.
	 *
	 * Body: `splits` as a JSON array, either raw JSON or a form field, each
	 * entry `{"amount": "3.40", "category_id": 12, "description": "Flat White"}`.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function createSplits(int $id): DataResponse {
		$splits = $this->readSplitsParam();
		if ($splits === null) {
			return $this->splitsRefused($this->l->t('splits must be an array of {"amount", "category_id", "description"} objects'));
		}

		try {
			// Splits belong to the ledger owner, like the transaction and its
			// receipts — a write on a shared account must not scope to the
			// acting user, or it lands in the wrong ledger (see #333/#334).
			[, $ownerId] = $this->findWritable($id);
			$created = $this->splitService->splitTransaction($id, $ownerId, $splits);

			return new DataResponse(['splits' => ApiSerializer::splits($created)], Http::STATUS_CREATED);
		} catch (DoesNotExistException $e) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			// "must equal transaction amount", "at least 2 parts" — the
			// client's arithmetic, so the reason is safe and useful to return.
			return $this->splitsRefused($e->getMessage());
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to split the transaction'));
		}
	}

	/**
	 * A 400 from createSplits. `error` is the documented envelope every
	 * other endpoint uses; `message` is what this one sent before, kept so
	 * clients written against it still read the reason.
	 */
	private function splitsRefused(string $reason): DataResponse {
		return new DataResponse(['error' => $reason, 'message' => $reason], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * Read and normalise the `splits` parameter.
	 *
	 * Accepts a decoded array (JSON body) or a JSON string (multipart form,
	 * which is how a capture app posting a photo has to send it). Field names
	 * are snake_case on the wire like the rest of v1; camelCase is tolerated
	 * because the split service and the web UI already speak it.
	 *
	 * @return array|null null when the parameter is absent or unusable
	 */
	private function readSplitsParam(): ?array {
		$raw = $this->request->getParam('splits');
		if ($raw === null || $raw === '') {
			return null;
		}

		if (is_string($raw)) {
			$decoded = json_decode($raw, true);
			if (!is_array($decoded)) {
				return null;
			}
			$raw = $decoded;
		}

		if (!is_array($raw) || $raw === []) {
			return null;
		}

		$splits = [];
		foreach ($raw as $entry) {
			if (!is_array($entry)) {
				return null;
			}
			if (!isset($entry['amount'])) {
				return null;
			}
			$categoryId = $entry['category_id'] ?? $entry['categoryId'] ?? null;
			$splits[] = [
				// Anything that isn't a number goes on as sent: cast, it read
				// as zero. The split service refuses both before it touches
				// the parts already stored.
				'amount' => is_numeric($entry['amount']) ? (float)$entry['amount'] : $entry['amount'],
				'categoryId' => $categoryId === null || $categoryId === '' ? null : (int)$categoryId,
				'description' => isset($entry['description']) && $entry['description'] !== ''
					? mb_substr((string)$entry['description'], 0, 255)
					: null,
			];
		}

		return $splits;
	}

	/**
	 * Receipts attached to a transaction.
	 *
	 * Receipts live in the owner's Files, which a share recipient cannot
	 * resolve, so these two endpoints are owner-only — a shared transaction
	 * returns 404 here even though it is readable through show().
	 */
	#[NoAdminRequired]
	public function receipts(int $id): DataResponse {
		try {
			$attachments = $this->attachmentService->listForTransaction($id, $this->userId);

			return new DataResponse(ApiSerializer::map($attachments, [ApiSerializer::class, 'attachment']));
		} catch (DoesNotExistException $e) {
			return $this->notFound();
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to load attachments'));
		}
	}

	/**
	 * Upload a receipt photo as multipart/form-data under the field `file`.
	 * The file is stored in the user's own Files under Budget/Receipts/<year>.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 10, period: 60)]
	public function uploadReceipt(int $id): DataResponse {
		$uploadedFile = $this->request->getUploadedFile('file');
		if (!$uploadedFile) {
			return new DataResponse(['error' => $this->l->t('No file uploaded')], Http::STATUS_BAD_REQUEST);
		}

		try {
			$attachment = $this->attachmentService->upload($id, $this->userId, $uploadedFile);

			return new DataResponse(ApiSerializer::attachment($attachment), Http::STATUS_CREATED);
		} catch (DoesNotExistException $e) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to upload receipt'));
		}
	}

	/**
	 * Edit a transaction (discussion 412). Only the fields sent change: one
	 * left out keeps its value, and vendor, reference, notes or category_id
	 * sent as null (or '') is cleared.
	 *
	 * - date, amount, type, description, vendor, reference, notes, category_id
	 * - merchant: create()'s shorthand for description and vendor together;
	 *   either sent explicitly wins over it
	 * - confirm_reconciled: true to change the money on a reconciled row
	 *
	 * The read shape can be sent back whole with a field edited. account_id,
	 * status and reconciled are refused only when they differ from what is
	 * stored, and the 409s below only fire on a real change.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function update(int $id): DataResponse {
		try {
			[$transaction, $ownerId] = $this->findWritable($id);

			$updates = $this->readUpdates($this->request->getParams(), $transaction);
			if ($updates instanceof DataResponse) {
				return $updates;
			}
			if ($updates === []) {
				return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
			}

			$refusal = $this->refuseUpdate($transaction, $ownerId, $updates);
			if ($refusal !== null) {
				return $refusal;
			}

			if (array_key_exists('categoryId', $updates)) {
				$this->requireOwnersCategory($ownerId, $updates['categoryId']);
			}

			$updated = $this->service->update($id, $ownerId, $updates);

			return new DataResponse($this->serializeOne($updated, $this->getEffectiveAccountIds()));
		} catch (DoesNotExistException $e) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to update transaction'));
		}
	}

	/**
	 * Delete a transaction (discussion 412), with everything hanging off it
	 * (splits, tags, receipt links) and the balance recalculated.
	 *
	 * One side of a transfer goes on its own, as in the web UI: the other
	 * side stays as a plain transaction and its id comes back as
	 * unlinked_transaction_id. A retry after a lost response gets a 404,
	 * which means it is already gone.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function destroy(int $id): DataResponse {
		try {
			[$transaction, $ownerId] = $this->findWritable($id);

			if ($transaction->getReconciled() && !$this->reconciledChangeConfirmed()) {
				return $this->reconciledConflict();
			}

			$partnerId = $transaction->getLinkedTransactionId();
			$this->service->delete($id, $ownerId);

			return new DataResponse(ApiSerializer::deletion($id, $partnerId));
		} catch (DoesNotExistException $e) {
			return $this->notFound();
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to delete transaction'));
		}
	}

	/**
	 * Undo a split (discussion 412): the parts go, and the transaction takes
	 * category_id if one is sent, or none. A transaction that isn't split is
	 * returned as it stands, so retrying a successful call is harmless.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 30, period: 60)]
	public function unsplit(int $id): DataResponse {
		$categoryId = $this->readCategoryId($this->request->getParam('category_id'));
		if ($categoryId === false) {
			return $this->badCategory();
		}

		try {
			[$transaction, $ownerId] = $this->findWritable($id);
			if ($transaction->getIsSplit()) {
				$transaction = $this->splitService->unsplitTransaction($id, $ownerId, $categoryId);
			}

			return new DataResponse($this->serializeOne($transaction, $this->getEffectiveAccountIds()));
		} catch (DoesNotExistException $e) {
			return $this->notFound();
		} catch (\InvalidArgumentException $e) {
			return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to update transaction'));
		}
	}

	/**
	 * A transaction the caller may change, and the user its rows belong to.
	 *
	 * Found among every account the caller can see, and held to write access
	 * when that account is shared to them. The services scope every lookup to
	 * the account's owner, so the writes run as the owner, never the acting
	 * user (#333/#334).
	 *
	 * @return array{0: Transaction, 1: string}
	 * @throws DoesNotExistException
	 * @throws ReadOnlyShareException
	 */
	private function findWritable(int $id): array {
		$transaction = $this->service->findForAccounts($id, $this->getEffectiveAccountIds());
		$accountId = $transaction->getAccountId();
		if (!in_array($accountId, $this->granularShareService->getOwnAccountIds($this->userId), true)) {
			$this->requireWriteAccess('account', $accountId);
		}

		return [$transaction, $this->service->findAccountById($accountId)->getUserId()];
	}

	/**
	 * The category check every write makes, with a refusal a client can act
	 * on. The row lands in the account owner's ledger, so on an account
	 * shared with the caller their own categories are refused too, and a
	 * bare "Category not found" read as if the id were wrong.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function requireOwnersCategory(string $ownerId, ?int $categoryId): void {
		try {
			$this->granularShareService->requireUsableCategory($ownerId, $categoryId);
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException(
				$this->l->t("Category not found. It must be one of the account owner's categories"),
				0,
				$e
			);
		}
	}

	/** The service updates a PATCH body asks for, or the 400 that refuses it. */
	private function readUpdates(array $p, Transaction $current): array|DataResponse {
		$unchanged = [
			'account_id' => static fn ($v): bool => (int)$v === $current->getAccountId(),
			'status' => static fn ($v): bool => (string)$v === ($current->getStatus() ?? 'cleared'),
			'reconciled' => static fn ($v): bool => filter_var($v, FILTER_VALIDATE_BOOLEAN) === (bool)$current->getReconciled(),
		];
		foreach ($unchanged as $field => $isUnchanged) {
			if (array_key_exists($field, $p) && !$isUnchanged($p[$field])) {
				return new DataResponse([
					'error' => $this->l->t('%s cannot be changed through the API', [$field]),
					'error_code' => 'field_not_editable',
				], Http::STATUS_BAD_REQUEST);
			}
		}

		$updates = [];

		if (array_key_exists('date', $p)) {
			$date = (string)$p['date'];
			$result = $this->validationService->validateDate($date, $this->l->t('Date'), true);
			if (!$result['valid']) {
				return new DataResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
			}
			$updates['date'] = $date;
		}

		if (array_key_exists('amount', $p)) {
			$amount = $p['amount'];
			if (!is_numeric(is_string($amount) ? trim($amount) : $amount)) {
				return new DataResponse(['error' => $this->l->t('Amount must be a number')], Http::STATUS_BAD_REQUEST);
			}
			$amount = (float)$amount;
			// A negative amount reversed the row (and every split part) with a
			// 200. Only a real change is refused: a stored negative amount from
			// an import, sent back as read, still passes.
			if ($amount <= 0 && !MoneyCalculator::equals($current->getAmount(), $amount, '0.001')) {
				return $this->badAmount($this->l->t('Amount must be more than zero. Use type to say which way the money went'));
			}
			$updates['amount'] = $amount;
		}

		if (array_key_exists('type', $p)) {
			if (!in_array($p['type'], ['credit', 'debit'], true)) {
				return new DataResponse(
					['error' => $this->l->t('Invalid transaction type. Must be credit or debit')],
					Http::STATUS_BAD_REQUEST
				);
			}
			$updates['type'] = $p['type'];
		}

		$text = [];
		$merchant = isset($p['merchant']) ? trim((string)$p['merchant']) : '';
		foreach (['description', 'vendor', 'reference', 'notes'] as $field) {
			if (array_key_exists($field, $p)) {
				$text[$field] = $p[$field] === null ? null : (string)$p[$field];
			} elseif ($merchant !== '' && ($field === 'description' || $field === 'vendor')) {
				$text[$field] = $merchant;
			}
		}
		foreach ($text as $field => $value) {
			$result = match ($field) {
				'description' => $this->validationService->validateDescription($value, true),
				'vendor' => $this->validationService->validateVendor($value),
				'reference' => $this->validationService->validateReference($value),
				'notes' => $this->validationService->validateNotes($value),
			};
			if (!$result['valid']) {
				return new DataResponse(['error' => $result['error']], Http::STATUS_BAD_REQUEST);
			}
			$updates[$field] = $result['sanitized'];
		}

		if (array_key_exists('category_id', $p)) {
			$categoryId = $this->readCategoryId($p['category_id']);
			if ($categoryId === false) {
				return $this->badCategory();
			}
			$updates['categoryId'] = $categoryId;
		}

		return $updates;
	}

	/**
	 * The 409s that stand in for the web UI's warnings. Only a real change
	 * counts, so values sent back as they were read are never refused.
	 */
	private function refuseUpdate(Transaction $current, string $ownerId, array $updates): ?DataResponse {
		$moneyChanges = (array_key_exists('amount', $updates)
				&& !MoneyCalculator::equals($current->getAmount(), $updates['amount'], '0.001'))
			|| (array_key_exists('type', $updates) && $updates['type'] !== $current->getType())
			|| (array_key_exists('date', $updates) && $updates['date'] !== substr((string)$current->getDate(), 0, 10));

		// The other side would keep the old figure, and the transfer would
		// stop adding up without anything saying so
		if ($moneyChanges && $current->getLinkedTransactionId() !== null) {
			return new DataResponse([
				'error' => $this->l->t('This transaction is one side of a transfer, so its amount, type and date cannot be changed on their own'),
				'error_code' => 'transfer_leg',
			], Http::STATUS_CONFLICT);
		}

		if ($moneyChanges && $current->getReconciled() && !$this->reconciledChangeConfirmed()) {
			return $this->reconciledConflict();
		}

		// A split's categories live on its parts, and the service would
		// quietly drop this one. NULL predates is_split, so the parts decide
		// (#360).
		$categoryId = $updates['categoryId'] ?? null;
		if ($categoryId !== null && $categoryId !== $current->getCategoryId()
			&& $current->getIsSplit() !== false
			&& $this->splitService->getSplits($current->getId(), $ownerId) !== []) {
			return new DataResponse([
				'error' => $this->l->t('This transaction is split, so its categories are changed through its splits'),
				'error_code' => 'split',
			], Http::STATUS_CONFLICT);
		}

		return null;
	}

	/** The request's go-ahead for a change the web UI would have warned about. */
	private function reconciledChangeConfirmed(): bool {
		return filter_var($this->request->getParam('confirm_reconciled', false), FILTER_VALIDATE_BOOLEAN);
	}

	private function reconciledConflict(): DataResponse {
		return new DataResponse([
			'error' => $this->l->t('This transaction has been reconciled against a statement. Send confirm_reconciled to go ahead'),
			'error_code' => 'reconciled',
		], Http::STATUS_CONFLICT);
	}

	/**
	 * A category id from the wire: null for none (null, '' or 0), false when
	 * it isn't a number.
	 */
	private function readCategoryId(mixed $raw): int|null|false {
		if ($raw === null || $raw === '') {
			return null;
		}
		if (!is_numeric($raw)) {
			return false;
		}

		return (int)$raw > 0 ? (int)$raw : null;
	}

	private function badAmount(string $message): DataResponse {
		return new DataResponse(
			['error' => $message, 'error_code' => 'invalid_amount'],
			Http::STATUS_BAD_REQUEST
		);
	}

	private function badCategory(): DataResponse {
		return new DataResponse(
			['error' => $this->l->t('category_id must be a category id or null')],
			Http::STATUS_BAD_REQUEST
		);
	}

	private function notFound(?string $message = null): DataResponse {
		return new DataResponse(
			['error' => $message ?? $this->l->t('Transaction not found')],
			Http::STATUS_NOT_FOUND
		);
	}
}
