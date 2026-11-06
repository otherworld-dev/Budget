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
use OCA\Budget\Exception\ReceiptUploadException;
use OCA\Budget\Service\AttachmentService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\MoneyCalculator;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Service\TransactionSplitService;
use OCA\Budget\Service\TransactionTagService;
use OCA\Budget\Service\UserClock;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\FileDisplayResponse;
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
 * dialog to show. Moving a transaction to another account and its status
 * stay web-only. The reconciled flag is not part of an edit: it has two calls
 * of its own, so a transaction sent back whole can never carry a stale one.
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

	/** @var array<int, string|null> account id => its currency, this request */
	private array $accountCurrencies = [];

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
		private ?TransactionTagService $tagService = null,
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

		$more = $this->readListFilters($categoryId !== null);
		if ($more instanceof DataResponse) {
			return $more;
		}

		try {
			$filters = $more + [
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
					$this->withReceipts($this->withTags(TransactionService::hideUnseenLinkedAccounts($result['transactions'], $visible))),
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
	 * Every filter GET /transactions takes, as GET /capabilities lists them.
	 * A server ignores a query parameter it has never heard of, so a client
	 * looks a filter up here before relying on it. Add a new one here too.
	 */
	public const FILTERS = [
		'accountId', 'categoryId', 'dateFrom', 'dateTo', 'search',
		'type', 'status', 'reconciled', 'uncategorized', 'tagId', 'amountMin', 'amountMax',
	];

	/** Most tags one request may set; far more than anyone puts on a row. */
	public const MAX_TAG_IDS = 200;

	/** What `status` may be asked for; NULL in the database reads as cleared. */
	private const STATUSES = ['cleared', 'scheduled', 'pending'];

	/**
	 * The list's other filters: type, status, reconciled, uncategorized, tagId,
	 * amountMin and amountMax, in the filter builder's own words. Only what was
	 * sent is returned.
	 *
	 * Read by hand and refused when unreadable. An older server ignores a
	 * filter it has never heard of, which a client can find out beforehand
	 * (filters_available); this one must never guess, or the answer is rows
	 * nobody asked for.
	 *
	 * @return array<string, mixed>|DataResponse the filters, or the 400
	 */
	private function readListFilters(bool $hasCategory): array|DataResponse {
		$raw = [];
		foreach (['type', 'status', 'reconciled', 'uncategorized', 'tagId', 'amountMin', 'amountMax'] as $name) {
			$value = $this->request->getParam($name);
			if ($value === null || $value === '') {
				continue;
			}
			if (!is_scalar($value)) {
				return $this->notASingleValue($name);
			}
			$raw[$name] = is_string($value) ? trim($value) : $value;
		}

		$filters = [];

		if (isset($raw['type'])) {
			if (!in_array($raw['type'], ['credit', 'debit'], true)) {
				return $this->badRequest($this->l->t('Invalid transaction type. Must be credit or debit'));
			}
			$filters['type'] = $raw['type'];
		}

		if (isset($raw['status'])) {
			if (!in_array($raw['status'], self::STATUSES, true)) {
				return $this->badRequest($this->l->t('Invalid status. Must be cleared, scheduled or pending'));
			}
			$filters['status'] = $raw['status'];
		}

		foreach (['reconciled', 'uncategorized'] as $name) {
			if (!isset($raw[$name])) {
				continue;
			}
			$flag = self::readBool($raw[$name]);
			if ($flag === null) {
				return $this->badRequest($this->l->t('%s must be true or false', [$name]));
			}
			if ($name === 'reconciled') {
				$filters['reconciled'] = $flag;
			} elseif ($flag) {
				if ($hasCategory) {
					return $this->badRequest($this->l->t('Use either categoryId or uncategorized, not both'));
				}
				$filters['category'] = 'uncategorized';
			} else {
				// The rest of the list, as reconciled=false is: a category of
				// its own, or split. Taken and ignored, it was the whole list.
				$filters['categorized'] = true;
			}
		}

		if (isset($raw['tagId'])) {
			$tagId = self::readId($raw['tagId']);
			if ($tagId === null) {
				return $this->badRequest($this->l->t('%s must be a whole number above zero', ['tagId']));
			}
			$filters['tagIds'] = [$tagId];
		}

		foreach (['amountMin', 'amountMax'] as $name) {
			if (!isset($raw[$name])) {
				continue;
			}
			// Plain digits only: is_numeric() also takes 1e3 and +5. And
			// always with decimals, as the filter builder reads a bare '0'
			// as no bound at all and answered with the whole list.
			$bound = is_bool($raw[$name]) ? '' : (string)$raw[$name];
			if (preg_match('/^\d+(\.\d+)?$/', $bound) !== 1) {
				return $this->badRequest($this->l->t('%s must be a number, zero or more', [$name]));
			}
			$filters[$name] = str_contains($bound, '.') ? $bound : $bound . '.00';
		}

		return $filters;
	}

	/**
	 * tag_ids from the wire: a JSON list of ids, or one in a string (so it
	 * can ride a multipart body with a photo, like splits). null when it was
	 * not sent; false when it cannot be read as a list of ids.
	 *
	 * @return int[]|null|false
	 */
	private function readTagIds(bool $forEdit = false): array|null|false {
		if (!array_key_exists('tag_ids', $this->request->getParams())) {
			return null;
		}
		$raw = $this->request->getParam('tag_ids');
		// On an edit, null and '' would read as "leave the tags" and "remove
		// them all", when category_id null clears: neither is a list. On a
		// create an empty multipart field simply means no tags.
		if ($raw === null || (is_string($raw) && trim($raw) === '')) {
			return $forEdit ? false : null;
		}
		if (is_string($raw)) {
			// Not decoded to arrays: a JSON object is not a list of ids
			$raw = json_decode($raw);
		}
		if (!is_array($raw) || !array_is_list($raw) || count($raw) > self::MAX_TAG_IDS) {
			return false;
		}
		$ids = [];
		foreach ($raw as $value) {
			$id = self::readId($value);
			if ($id === null) {
				return false;
			}
			$ids[] = $id;
		}

		return array_values(array_unique($ids));
	}

	private function badTagIds(): string {
		return $this->l->t('tag_ids must be a list of at most %d tag ids', [self::MAX_TAG_IDS]);
	}

	private function invalidTags(string $reason): DataResponse {
		return new DataResponse(['error' => $reason, 'error_code' => 'invalid_tags'], Http::STATUS_BAD_REQUEST);
	}

	/**
	 * An id from the wire: a whole number above zero, or null for anything
	 * else. A JSON true is not the id 1.
	 */
	private static function readId(mixed $value): ?int {
		if (is_bool($value) || !is_scalar($value)) {
			return null;
		}
		$id = filter_var($value, FILTER_VALIDATE_INT);

		return $id !== false && $id > 0 ? $id : null;
	}

	/** true, false, or null for anything that is neither. */
	private static function readBool(mixed $value): ?bool {
		if (is_bool($value)) {
			return $value;
		}

		return is_scalar($value) ? filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null;
	}

	private function badRequest(string $message): DataResponse {
		return new DataResponse(['error' => $message], Http::STATUS_BAD_REQUEST);
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
				$this->withReceipts(TransactionService::hideUnseenLinkedAccounts($result['transactions'], $visible)),
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

			return new DataResponse(['splits' => ApiSerializer::splits($parts, $this->currencyOf($transaction->getAccountId()))]);
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

		return ApiSerializer::transaction($this->withReceipts($this->withTags([$row]))[0], $this->currencyOf($transaction->getAccountId()));
	}

	/**
	 * Rows with whether each has a receipt (#455), read for the whole page in
	 * one query rather than the GET .../receipts per row a client made
	 * before. Scoped to the caller like that endpoint: a row in an account
	 * shared with them is left unknown (null), never false.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function withReceipts(array $rows): array {
		if ($rows === []) {
			return $rows;
		}

		$presence = $this->attachmentService->receiptPresence(array_map(static fn (array $row): int => (int)($row['id'] ?? 0), $rows), $this->userId);
		foreach ($rows as $i => $row) {
			$rows[$i]['hasReceipt'] = $presence[(int)($row['id'] ?? 0)] ?? null;
		}

		return $rows;
	}

	/** has_receipt for one transaction, as withReceipts() reads it. */
	private function hasReceipt(int $transactionId): ?bool {
		return $this->attachmentService->receiptPresence([$transactionId], $this->userId)[$transactionId] ?? null;
	}

	/**
	 * Rows with their tags (discussion 420), read for the whole page at once.
	 * Not scoped to the caller: a row in a shared account carries its owner's
	 * tags, and the rows are already ones the caller may see.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function withTags(array $rows): array {
		if ($rows === [] || $this->tagService === null) {
			return $rows;
		}

		$tags = $this->tagService->tagDetailsForTransactions(array_map(static fn (array $row): int => (int)($row['id'] ?? 0), $rows));
		foreach ($rows as $i => $row) {
			$rows[$i]['tags'] = $tags[(int)($row['id'] ?? 0)] ?? [];
		}

		return $rows;
	}

	/**
	 * The currency an account's money is in, so its amounts are written in
	 * that currency's places. A list row carries it; a single row is looked
	 * up, once per account per request.
	 */
	private function currencyOf(int $accountId): ?string {
		if (!array_key_exists($accountId, $this->accountCurrencies)) {
			try {
				$this->accountCurrencies[$accountId] = $this->service->findAccountById($accountId)->getCurrency();
			} catch (DoesNotExistException $e) {
				$this->accountCurrencies[$accountId] = null;
			}
		}

		return $this->accountCurrencies[$accountId];
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
	 * - tag_ids (optional): a JSON list of tag ids, or one in a string. A
	 *   refused tag leaves the transaction recorded, untagged, with tags_error.
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

		$listed = self::fieldWithAList($p, ['account_id', 'accountId', 'date', 'merchant', 'description', 'vendor', 'type', 'reference', 'notes', 'idempotency_key', 'transfer_account_id']);
		if ($listed !== null) {
			return $this->notASingleValue($listed);
		}

		$accountId = (int)($p['account_id'] ?? $p['accountId'] ?? 0);
		// The other account of a transfer; none (or '') for anything else
		$transferAccountId = null;
		if (($p['transfer_account_id'] ?? '') !== '') {
			$transferAccountId = self::readId($p['transfer_account_id']);
			if ($transferAccountId === null) {
				return $this->badRequest($this->l->t('%s must be a whole number above zero', ['transfer_account_id']));
			}
		}
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

		// A retry of a key that already recorded this transaction is answered
		// with it first. The account may have been closed since, or a
		// transfer's other account unshared or made read-only, and a 4xx for
		// that would tell the client nothing was recorded when it was. A key
		// in flight, reused for another purchase or new goes the usual way.
		if ($idemKey !== '') {
			$this->purgeExpiredKeys();
			$replay = $this->replayRecorded($idemKey, $accountId, $amount, $transferAccountId);
			if ($replay !== null) {
				return $replay;
			}
		}

		// Everything that can stop a transfer is settled before anything is
		// recorded or the key is spent, and so is a closed account
		$refusal = $transferAccountId !== null
			? $this->refuseTransfer($accountId, $transferAccountId)
			: $this->refuseClosed([$accountId]);
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			// Reserve the key BEFORE creating anything. Check-then-insert
			// raced: two overlapping retries both missed the lookup, both
			// created, and the ledger showed the purchase twice (reproduced
			// live). The unique index makes exactly one reservation win, so
			// exactly one request can ever reach the create below.
			$reservation = null;
			if ($idemKey !== '') {
				$acquired = $this->acquireIdempotencyKey($idemKey, $accountId, $amount, $transferAccountId);
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
				// can't use is never stored (its name would come back on every
				// read). The capture is kept, uncategorised, and the response
				// says why: refusing it lost the purchase for a client that
				// offers its own categories on a shared account.
				$categoryError = null;
				try {
					$this->requireOwnersCategory($effectiveUserId, $categoryId);
					// On someone else's account it must be one the caller can
					// see too, or the owner's unshared names came back by id
					$this->granularShareService->requireCategoryVisibleToWriter($effectiveUserId, $this->userId, $categoryId);
				} catch (\InvalidArgumentException $e) {
					$categoryError = $e->getMessage();
					$categoryId = null;
				}

				$details = [
					$date,
					$fields['description']['sanitized'],
					$amount,
					$type,
					$categoryId,
					isset($fields['vendor']) ? $fields['vendor']['sanitized'] : null,
					isset($fields['reference']) ? $fields['reference']['sanitized'] : null,
					isset($fields['notes']) ? $fields['notes']['sanitized'] : null,
				];
				// A transfer is both sides or neither, which only the service
				// can promise: it records the two in one database transaction
				$transaction = $transferAccountId !== null
					? $this->service->createTransfer($effectiveUserId, $this->userId, $accountId, $transferAccountId, $this->getEffectiveAccountIds(), ...$details)
					: $this->service->create($effectiveUserId, $accountId, ...$details);
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
			// A transfer answers with its link and the other account's name
			$out = $transferAccountId !== null
				? $this->serializeOne($transaction, $this->getEffectiveAccountIds())
				: ApiSerializer::transaction($transaction, $this->currencyOf($accountId));
			if ($categoryError !== null) {
				$out['category_error'] = $categoryError;
			}
			$photo = $this->request->getUploadedFile('photo');
			if ($photo) {
				try {
					$this->attachmentService->upload($transaction->getId(), $effectiveUserId, $photo, $this->userId);
				} catch (\Throwable $e) {
					$this->logger?->warning('Receipt attach during create failed: ' . $e->getMessage(), [
						'app' => Application::APP_ID,
					]);
					$out = array_merge($out, $this->photoError($e));
				}
			}
			// Read after the photo, so a receipt that just attached shows
			$out['has_receipt'] = $this->hasReceipt($transaction->getId());

			// Optional per-item splits, for a capture app that read a receipt
			// and had the user categorise each line. Handled like the photo
			// above: the transaction is already recorded and idempotency-keyed,
			// so a rejected split set reports itself rather than failing the
			// request — a retry would duplicate the very thing the key guards.
			// The total is unaffected either way, so the fallback state is a
			// correct unsplit transaction the user can split later. A splits
			// field that can't be read as parts is a rejected set too, not
			// one to drop without a word.
			// Whether a split or tags below moved updated_at past $out's
			$touched = false;
			try {
				$splits = $this->readSplitsParam();
				if ($splits !== null) {
					$created = $this->splitService->splitTransaction($transaction->getId(), $effectiveUserId, $splits);
					$out['splits'] = ApiSerializer::splits($created, $this->currencyOf($accountId));
					// The transaction was serialised before the split ran, so
					// its flags are stale: splitting sets is_split and clears
					// the category. Correct them rather than re-reading the
					// row — a client trusting is_split from this response
					// would otherwise believe the split never happened.
					$out['is_split'] = true;
					$out['category_id'] = null;
					$touched = true;
				}
			} catch (\Throwable $e) {
				// Stated rather than left to the snapshot: a client that
				// checks splits_error sees no parts next to it, ever
				$out['splits'] = [];
				$out['splits_error'] = $e instanceof \InvalidArgumentException
					? $e->getMessage()
					: $this->l->t('The transaction was recorded, but it could not be split');
			}

			// Tags last, on the transaction as it now is (after a split it has
			// no category, so only global tags fit). Like the photo and the
			// splits, a refusal is reported and never fails the capture.
			$tagIds = $this->readTagIds();
			if ($tagIds === false) {
				$out['tags_error'] = $this->badTagIds();
			} elseif ($tagIds !== null && $tagIds !== [] && $this->tagService !== null) {
				try {
					$final = $this->tagService->resolveTagsAs($this->userId, $effectiveUserId, $transaction->getId(), $out['category_id'], $tagIds);
					$this->tagService->replaceTransactionTags($transaction->getId(), $final);
					$touched = true;
					$out['tags'] = ApiSerializer::map(
						$this->tagService->tagDetailsForTransactions([$transaction->getId()])[$transaction->getId()] ?? [],
						[ApiSerializer::class, 'tag']
					);
				} catch (\Throwable $e) {
					// The transaction is recorded and its key spent: say what
					// went wrong rather than answer 500 and invite a second one
					$out['tags_error'] = $e instanceof \InvalidArgumentException
						? $e->getMessage()
						: $this->l->t('The transaction was recorded, but its tags could not be set');
					$out['tags'] = [];
				}
			}

			if ($touched) {
				$out['updated_at'] = $this->updatedAtNow($transaction->getId()) ?? $out['updated_at'];
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

	/**
	 * The row's updated_at as stored now, or null when it can't be read. A
	 * split or a change of tags moves it after the row was serialised, and a
	 * client syncing by it would otherwise keep a time already gone. Never
	 * fails the request: the transaction is recorded and its key spent.
	 */
	private function updatedAtNow(int $id): ?string {
		try {
			$fresh = $this->service->findTransaction($id);
		} catch (\Throwable $e) {
			return null;
		}

		return $fresh === null ? null : ApiSerializer::transaction($fresh)['updated_at'];
	}

	/**
	 * Why a transfer between these two accounts cannot be recorded, or null
	 * when it can. Asked before anything is written, so a refusal leaves no
	 * transaction behind and spends no idempotency key.
	 */
	private function refuseTransfer(int $accountId, int $transferAccountId): ?DataResponse {
		if ($transferAccountId === $accountId) {
			return $this->transferRefused(
				'transfer_same_account',
				$this->l->t('A transfer needs two different accounts')
			);
		}

		// A list or an object is refused by the split reader; here it only
		// matters that parts were sent at all
		$splits = $this->request->getParam('splits');
		if ($splits !== null && $splits !== '' && $splits !== [] && $splits !== '[]') {
			return $this->transferRefused(
				'transfer_split',
				$this->l->t('A transfer cannot be split')
			);
		}

		try {
			$own = $this->granularShareService->getOwnAccountIds($this->userId);
			foreach ([$accountId, $transferAccountId] as $id) {
				// One the caller can't see is not found, as everywhere else
				if (!in_array($id, $own, true) && !$this->canAccessEntity('account', $id)) {
					return $this->notFound($this->l->t('Account not found'));
				}
			}
			// The other side is written into this account, so a read-only
			// share is refused like any other write to it
			if (!in_array($transferAccountId, $own, true)) {
				$this->requireWriteAccess('account', $transferAccountId);
			}

			$closed = $this->refuseClosed([$accountId, $transferAccountId]);
			if ($closed !== null) {
				return $closed;
			}

			if ($this->currencyOf($accountId) !== $this->currencyOf($transferAccountId)) {
				return $this->transferRefused(
					'transfer_currency_mismatch',
					$this->l->t('The two accounts are in different currencies. Record this transfer in the web UI, where each side has its own amount')
				);
			}
		} catch (\Exception $e) {
			// A read-only share is a 403 here; anything else the usual answer
			return $this->handleError($e, $this->l->t('Failed to create transaction'));
		}

		return null;
	}

	/**
	 * The 400 for new activity in a closed account, or null when none of
	 * these is closed. A closed account stays at zero and nothing posts into
	 * it again (#372); reopening it is one click in the web UI.
	 *
	 * Only accounts the caller can see are looked at: one they can't is left
	 * to the 404 further on, so whether it is closed is never given away.
	 *
	 * @param int[] $accountIds
	 */
	private function refuseClosed(array $accountIds): ?DataResponse {
		$own = $this->granularShareService->getOwnAccountIds($this->userId);
		foreach ($accountIds as $id) {
			if (!in_array($id, $own, true) && !$this->canAccessEntity('account', $id)) {
				continue;
			}
			try {
				$closed = (bool)$this->service->findAccountById($id)->getClosed();
			} catch (DoesNotExistException $e) {
				continue;
			}
			if ($closed) {
				return new DataResponse([
					'error' => $this->l->t('This account is closed. Reopen it to record anything in it'),
					'error_code' => 'account_closed',
				], Http::STATUS_BAD_REQUEST);
			}
		}

		return null;
	}

	private function transferRefused(string $errorCode, string $message): DataResponse {
		return new DataResponse(['error' => $message, 'error_code' => $errorCode], Http::STATUS_BAD_REQUEST);
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
	private function acquireIdempotencyKey(string $idemKey, int $accountId, float $amount, ?int $transferAccountId = null): IdempotencyKey|DataResponse {
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

			if (!$this->isWhatTheKeyRecorded($transaction, $accountId, $amount, $transferAccountId)) {
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
	 * Retention housekeeping, before the key is looked up so a key past
	 * retention is never replayed. A purge hiccup must not disable
	 * idempotency for this request.
	 */
	private function purgeExpiredKeys(): void {
		try {
			$this->idempotencyKeys->purgeOlderThan(
				new \DateTimeImmutable('-' . self::IDEM_RETENTION_DAYS . ' days')
			);
		} catch (\Throwable $e) {
			$this->logger?->debug('Idempotency purge skipped: ' . $e->getMessage(), [
				'app' => Application::APP_ID,
			]);
		}
	}

	/**
	 * The 201 replay when this key already recorded the transaction asked
	 * for and the caller can still see it, or null to go the usual way.
	 *
	 * Only ever a replay: a key with nothing recorded yet, one whose
	 * transaction is gone or out of the caller's sight, and one reused for
	 * another purchase are all left to the refusals and the reservation, as
	 * before. So losing sight of the account the transaction lives in still
	 * answers 404 and gives nothing about it away.
	 */
	private function replayRecorded(string $idemKey, int $accountId, float $amount, ?int $transferAccountId): ?DataResponse {
		try {
			$existing = $this->idempotencyKeys->findByKey($this->userId, $idemKey);
			if ($existing->getTransactionId() === 0) {
				return null;
			}
			$transaction = $this->service->findForAccounts($existing->getTransactionId(), $this->getEffectiveAccountIds());
			if (!$this->isWhatTheKeyRecorded($transaction, $accountId, $amount, $transferAccountId)) {
				return null;
			}

			return new DataResponse($this->replayResponse($transaction), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			// Nothing recorded, or nothing the caller can see: the
			// reservation further on answers for it (and for a failure
			// here, which it meets again inside its error handling)
			return null;
		}
	}

	/**
	 * Whether the transaction a key recorded is the one asked for again.
	 *
	 * Same key, different purchase = a client bug worth surfacing, not
	 * silently answering with someone else's numbers. A transfer asked for is
	 * part of that: a 201 for a key that recorded a plain transaction, or a
	 * transfer into another account, would say a transfer exists that was
	 * never made. A plain retry is answered whatever became of the
	 * transaction since.
	 */
	private function isWhatTheKeyRecorded(Transaction $transaction, int $accountId, float $amount, ?int $transferAccountId): bool {
		return $transaction->getAccountId() === $accountId
			&& MoneyCalculator::equals($transaction->getAmount(), $amount)
			&& ($transferAccountId === null || $this->otherSideAccountOf($transaction) === $transferAccountId);
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
				$account = $this->service->findAccountById($transaction->getAccountId());
				// A replay answers whatever changed since, but adding a receipt
				// is a write: not into a closed account, nor through a share
				// that has become read-only
				if ($account->getClosed()) {
					throw new \RuntimeException('Account closed since the transaction was recorded');
				}
				$this->requireWriteAccess('account', $transaction->getAccountId());
				$ownerId = $account->getUserId();
				if ($this->attachmentService->listForTransaction($transaction->getId(), $ownerId) === []) {
					$this->attachmentService->upload($transaction->getId(), $ownerId, $photo, $this->userId);
					$out['has_receipt'] = $this->hasReceipt($transaction->getId());
				}
			} catch (\Throwable $e) {
				$this->logger?->warning('Receipt attach during replay failed: ' . $e->getMessage(), [
					'app' => Application::APP_ID,
				]);
				$out = array_merge($out, $this->photoError($e));
			}
		}

		return $out;
	}

	/**
	 * photo_error and photo_error_code for a receipt that didn't attach to a
	 * recorded transaction (#437). The code lets a client tell a login that
	 * can't reach Files (fixable only in the user's security settings) from
	 * a full quota or a bad file; anything unforeseen is upload_failed.
	 *
	 * @return array{photo_error: string, photo_error_code: string}
	 */
	private function photoError(\Throwable $e): array {
		$code = $e instanceof ReceiptUploadException ? $e->getErrorCode() : 'upload_failed';
		return [
			'photo_error' => $code === ReceiptUploadException::FILES_ACCESS_DENIED
				? $this->l->t('The transaction was recorded, but the photo could not be attached: this app password has no access to your Files')
				: $this->l->t('The transaction was recorded, but the photo could not be attached'),
			'photo_error_code' => $code,
		];
	}

	/** The account the other side of a transfer sits in, null when there is none. */
	private function otherSideAccountOf(Transaction $transaction): ?int {
		$linkedId = $transaction->getLinkedTransactionId();

		return $linkedId === null ? null : $this->service->findTransaction($linkedId)?->getAccountId();
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
		try {
			$splits = $this->readSplitsParam();
			if ($splits === null) {
				return $this->splitsRefused($this->unusableSplits()->getMessage());
			}

			// Splits belong to the ledger owner, like the transaction and its
			// receipts — a write on a shared account must not scope to the
			// acting user, or it lands in the wrong ledger (see #333/#334).
			[$transaction, $ownerId] = $this->findWritable($id);
			if ($ownerId !== $this->userId) {
				$kept = $this->categoriesOn($transaction, $ownerId);
				foreach ($splits as $split) {
					$this->granularShareService->requireCategoryVisibleToWriter($ownerId, $this->userId, $split['categoryId'] ?? null, $kept);
				}
			}
			$created = $this->splitService->splitTransaction($id, $ownerId, $splits);

			return new DataResponse(['splits' => ApiSerializer::splits($created, $this->currencyOf($transaction->getAccountId()))], Http::STATUS_CREATED);
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
	 * @return array|null null when the parameter is absent or an empty list
	 * @throws \InvalidArgumentException when it is sent but can't be read as parts
	 */
	private function readSplitsParam(): ?array {
		$raw = $this->request->getParam('splits');
		if ($raw === null || $raw === '') {
			return null;
		}

		if (is_string($raw)) {
			$raw = json_decode($raw, true);
		}
		if (!is_array($raw)) {
			throw $this->unusableSplits();
		}
		if ($raw === []) {
			return null;
		}

		$splits = [];
		foreach ($raw as $entry) {
			if (!is_array($entry) || !isset($entry['amount'])) {
				throw $this->unusableSplits();
			}
			$categoryId = $entry['category_id'] ?? $entry['categoryId'] ?? null;
			// A list or an object where one value belongs: cast, the
			// description read "Array" and the category id 1
			if (($categoryId !== null && !is_scalar($categoryId))
				|| (isset($entry['description']) && !is_scalar($entry['description']))) {
				throw $this->unusableSplits();
			}
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

	private function unusableSplits(): \InvalidArgumentException {
		return new \InvalidArgumentException($this->l->t('splits must be an array of {"amount", "category_id", "description"} objects'));
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
	 * The receipt file itself, as raw bytes rather than an OCS envelope
	 * (#436), so a client can hand it straight to an image view or PDF
	 * viewer. Owner-only like receipts(); a missing transaction, a foreign
	 * attachment or a deleted file are all the same 404.
	 *
	 * The type comes from Nextcloud's own detection of the file, not from
	 * the name. The user can swap a file's contents in Files after attaching
	 * it, so anything outside the image/PDF types an attachment may be is
	 * sent as an opaque download instead of being displayed.
	 */
	#[NoAdminRequired]
	public function downloadReceipt(int $id, int $attachmentId): FileDisplayResponse|DataResponse {
		try {
			$file = $this->attachmentService->fileForDownload($id, $this->userId, $attachmentId);
		} catch (DoesNotExistException $e) {
			return $this->notFound($this->l->t('Attachment not found'));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to load attachments'));
		}

		$mime = $file->getMimeType();
		$displayable = in_array($mime, AttachmentService::ALLOWED_MIMES, true);
		$response = new FileDisplayResponse($file);
		$response->addHeader('Content-Type', $displayable ? $mime : 'application/octet-stream');
		$response->addHeader('Content-Disposition', self::contentDisposition($displayable ? 'inline' : 'attachment', $file->getName()));
		// Personal financial documents: never in a shared cache.
		$response->cacheFor(0);

		return $response;
	}

	/**
	 * A Content-Disposition value that survives any filename: an ASCII
	 * fallback for old clients plus the exact name as RFC 5987 filename*.
	 * Receipt names carry the merchant, which can be anything.
	 */
	private static function contentDisposition(string $type, string $name): string {
		$ascii = preg_replace('/[^\x20-\x7E]|["\\\\]/', '_', $name) ?? 'receipt';
		return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $ascii, rawurlencode($name));
	}

	/**
	 * Upload a receipt photo as multipart/form-data under the field `file`.
	 * The file is stored in the user's own Files, in their receipts folder
	 * under <year>/<month>/ of the transaction's date.
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
		} catch (ReceiptUploadException $e) {
			// A refused write is the login's or the folder's permissions, not
			// the request: 403, with the same codes create() gives photo_error
			$code = $e->getErrorCode();
			$status = in_array($code, [ReceiptUploadException::FILES_ACCESS_DENIED, ReceiptUploadException::FOLDER_NOT_WRITABLE], true)
				? Http::STATUS_FORBIDDEN
				: Http::STATUS_BAD_REQUEST;
			return new DataResponse(['error' => $e->getMessage(), 'error_code' => $code], $status);
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
	 * - tag_ids: the whole set of tags, [] for none. A tag already on it may
	 *   stay; a new one is checked against the category this edit leaves, and
	 *   a refused one refuses the edit (400, error_code invalid_tags)
	 *
	 * The read shape can be sent back whole with a field edited. account_id,
	 * status and reconciled are refused only when they differ from what is
	 * stored, and the 409s below only fire on a real change. The reconciled
	 * flag is set through reconcile() and unreconcile(): taken from an edit,
	 * a stale value in a transaction sent back whole would quietly undo a
	 * reconciliation made in the web UI since it was read.
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
			$tagIds = $this->readTagIds(true);
			if ($tagIds === false) {
				return $this->invalidTags($this->badTagIds());
			}
			if ($updates === [] && $tagIds === null) {
				return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
			}

			$refusal = $this->refuseUpdate($transaction, $ownerId, $updates);
			if ($refusal !== null) {
				return $refusal;
			}

			if (array_key_exists('categoryId', $updates)) {
				$this->requireOwnersCategory($ownerId, $updates['categoryId']);
				$this->granularShareService->requireCategoryVisibleToWriter(
					$ownerId, $this->userId, $updates['categoryId'], [$transaction->getCategoryId()]
				);
			}

			// Checked before anything is written, against the category this
			// edit leaves the transaction in, so a refused tag writes nothing
			$finalTags = null;
			if ($tagIds !== null && $this->tagService !== null) {
				$category = array_key_exists('categoryId', $updates) ? $updates['categoryId'] : $transaction->getCategoryId();
				try {
					$finalTags = $this->tagService->resolveTagsAs($this->userId, $ownerId, $id, $category, $tagIds);
				} catch (\InvalidArgumentException $e) {
					return $this->invalidTags($e->getMessage());
				}
			}

			$updated = $updates === [] ? $transaction : $this->service->update($id, $ownerId, $updates, $this->userId);
			if ($finalTags !== null) {
				$this->tagService->replaceTransactionTags($id, $finalTags);
				// Setting the tags moves updated_at, so the row read before
				// it would answer with a time already gone
				$updated = $this->service->findTransaction($id) ?? $updated;
			}

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
				if ($ownerId !== $this->userId) {
					$this->granularShareService->requireCategoryVisibleToWriter(
						$ownerId, $this->userId, $categoryId, $this->categoriesOn($transaction, $ownerId)
					);
				}
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
	 * Mark a transaction reconciled, as Mark reconciled does in the web UI.
	 * One that already is comes back as it stands, so a retry is harmless.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function reconcile(int $id): DataResponse {
		return $this->setReconciled($id, true);
	}

	/**
	 * Mark a transaction unreconciled. It leaves the statement it was
	 * reconciled against, and the change is written to the audit log.
	 */
	#[NoAdminRequired]
	#[UserRateLimit(limit: 60, period: 60)]
	public function unreconcile(int $id): DataResponse {
		return $this->setReconciled($id, false);
	}

	private function setReconciled(int $id, bool $reconciled): DataResponse {
		try {
			[$transaction, $ownerId] = $this->findWritable($id);

			// It has not happened yet, so no statement can have it, and the
			// reconciliation screen never lets one be ticked. Reconciled
			// while scheduled it could not be ticked on the statement it
			// does appear on.
			if ($reconciled && ($transaction->getStatus() ?? 'cleared') === 'scheduled') {
				return new DataResponse([
					'error' => $this->l->t('A scheduled transaction cannot be reconciled, as it has not happened yet'),
					'error_code' => 'scheduled',
				], Http::STATUS_CONFLICT);
			}

			if ((bool)$transaction->getReconciled() !== $reconciled) {
				$transaction = $this->service->update($id, $ownerId, ['reconciled' => $reconciled], $this->userId);
			}

			return new DataResponse($this->serializeOne($transaction, $this->getEffectiveAccountIds()));
		} catch (DoesNotExistException $e) {
			return $this->notFound();
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

		$account = $this->service->findAccountById($accountId);
		$this->accountCurrencies[$accountId] = $account->getCurrency();

		return [$transaction, $account->getUserId()];
	}

	/**
	 * The categories a transaction carries now, its own and its parts'; the
	 * caller may keep these on it even when one was never shared with them.
	 *
	 * @return array<int|null>
	 */
	private function categoriesOn(Transaction $transaction, string $ownerId): array {
		$ids = [$transaction->getCategoryId()];
		foreach ($this->splitService->getSplits((int)$transaction->getId(), $ownerId) as $part) {
			$ids[] = $part->getCategoryId();
		}
		return $ids;
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
		// Not `splits`: the read shape, which may be sent back whole, has it
		// as a list
		$listed = self::fieldWithAList($p, ['date', 'type', 'description', 'vendor', 'reference', 'notes', 'merchant', 'account_id', 'status', 'reconciled']);
		if ($listed !== null) {
			return $this->notASingleValue($listed);
		}

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

	/**
	 * The first of $fields sent as a list or an object, where one value
	 * belongs, or null. Cast, a list read as the text "Array" (with a PHP
	 * warning) and as id 1.
	 *
	 * @param string[] $fields
	 */
	private static function fieldWithAList(array $p, array $fields): ?string {
		foreach ($fields as $field) {
			if (isset($p[$field]) && !is_scalar($p[$field])) {
				return $field;
			}
		}

		return null;
	}

	private function notASingleValue(string $field): DataResponse {
		return new DataResponse(
			['error' => $this->l->t('%s must be a single value', [$field])],
			Http::STATUS_BAD_REQUEST
		);
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
