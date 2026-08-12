<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\Api\ApiSerializer;
use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\IdempotencyKey;
use OCA\Budget\Db\IdempotencyKeyMapper;
use OCA\Budget\Service\AttachmentService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\MoneyCalculator;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
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
 * Transactions over the public REST API (v1) — the capture half of the
 * surface: read recent activity, and record a new transaction with an
 * optional receipt photo.
 *
 * Editing and deleting are deliberately absent. A capture client only ever
 * appends, and every field it could get wrong is fixable in the web UI, so
 * keeping them out of v1 keeps the contract small enough to actually hold
 * still.
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
        int $limit = self::DEFAULT_LIMIT,
        int $offset = 0,
    ): DataResponse {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $offset = max(0, $offset);

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

            $result = $this->service->findWithFilters(
                $this->userId,
                $filters,
                $limit,
                $offset,
                $this->getEffectiveAccountIds()
            );

            return new DataResponse([
                'transactions' => ApiSerializer::map($result['transactions'], [ApiSerializer::class, 'transaction']),
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
            $result = $this->service->findWithFilters(
                $this->userId,
                [
                    'sort' => 'date',
                    'direction' => 'desc',
                    // Recorded activity only: a glanceable capture list led
                    // by next week's scheduled bills buries today's capture.
                    'dateTo' => date('Y-m-d'),
                ],
                $limit,
                0,
                $this->getEffectiveAccountIds()
            );

            return new DataResponse(
                ApiSerializer::map($result['transactions'], [ApiSerializer::class, 'recentTransaction'])
            );
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve transactions'));
        }
    }

    #[NoAdminRequired]
    public function show(int $id): DataResponse {
        try {
            $transaction = $this->service->findForAccounts($id, $this->getEffectiveAccountIds());

            return new DataResponse(ApiSerializer::transaction($transaction));
        } catch (DoesNotExistException $e) {
            return $this->notFound();
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve transaction'));
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

            // A shared account still belongs to whoever created it, so the row
            // must be written under the owner's id — writing it under the
            // acting user's id would orphan it from the account's ledger.
            $effectiveUserId = $this->userId;
            if (!in_array($accountId, $this->granularShareService->getOwnAccountIds($this->userId), true)) {
                $this->requireWriteAccess('account', $accountId);
                $effectiveUserId = $this->service->findAccountById($accountId)->getUserId();
            }

            try {
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
                    $this->attachmentService->upload($transaction->getId(), $effectiveUserId, $photo);
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
                    $out['splits_error'] = $e instanceof \InvalidArgumentException
                        ? $e->getMessage()
                        : $this->l->t('The transaction was recorded, but it could not be split');
                }
            }

            return new DataResponse($out, Http::STATUS_CREATED);
        } catch (DoesNotExistException $e) {
            return $this->notFound($this->l->t('Account not found'));
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
    private function replayResponse(\OCA\Budget\Db\Transaction $transaction): array {
        $out = ApiSerializer::transaction($transaction);

        $photo = $this->request->getUploadedFile('photo');
        if ($photo) {
            try {
                $ownerId = $this->service->findAccountById($transaction->getAccountId())->getUserId();
                if ($this->attachmentService->listForTransaction($transaction->getId(), $ownerId) === []) {
                    $this->attachmentService->upload($transaction->getId(), $ownerId, $photo);
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
            return new DataResponse(
                ['message' => $this->l->t('splits must be an array of {"amount", "category_id", "description"} objects')],
                Http::STATUS_BAD_REQUEST
            );
        }

        try {
            // Splits belong to the ledger owner, like the transaction and its
            // receipts — a write on a shared account must not scope to the
            // acting user, or it lands in the wrong ledger (see #333/#334).
            $transaction = $this->service->find($id, $this->getEffectiveUserId());
            $ownerId = $this->service->findAccountById($transaction->getAccountId())->getUserId();
            $created = $this->splitService->splitTransaction($id, $ownerId, $splits);

            return new DataResponse(['splits' => ApiSerializer::splits($created)], Http::STATUS_CREATED);
        } catch (DoesNotExistException $e) {
            return $this->notFound();
        } catch (\InvalidArgumentException $e) {
            // "must equal transaction amount", "at least 2 parts" — the
            // client's arithmetic, so the reason is safe and useful to return.
            return new DataResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to split the transaction'));
        }
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
                // The service sums these, so they must be numeric; a
                // non-numeric string would silently count as zero and let a
                // set of parts "reconcile" that does not.
                'amount' => (float)$entry['amount'],
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

    private function notFound(?string $message = null): DataResponse {
        return new DataResponse(
            ['error' => $message ?? $this->l->t('Transaction not found')],
            Http::STATUS_NOT_FOUND
        );
    }
}
