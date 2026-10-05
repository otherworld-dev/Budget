<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\Bill;
use OCA\Budget\Exception\ReconciledPaymentException;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\Export\CsvSafe;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\MoneyCalculator;
use OCA\Budget\Service\Report\MonthNames;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BillController extends Controller {
	use ApiErrorHandlerTrait;
	use InputValidationTrait;
	use SharedAccessTrait;

	private BillService $service;
	private ValidationService $validationService;
	private IL10N $l;
	private string $userId;

	public function __construct(
		IRequest $request,
		BillService $service,
		ValidationService $validationService,
		GranularShareService $granularShareService,
		private \OCA\Budget\Service\Bill\BillSuggestionService $suggestionService,
		private \OCA\Budget\Service\UpcomingBillsService $upcomingBills,
		IL10N $l,
		string $userId,
		LoggerInterface $logger,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->service = $service;
		$this->validationService = $validationService;
		$this->l = $l;
		$this->userId = $userId;
		$this->setLogger($logger);
		$this->setInputValidator($validationService);
		$this->setGranularShareService($granularShareService);
	}

	/**
	 * Get all bills or transfers
	 * @NoAdminRequired
	 * @param string|bool|null $activeOnly Filter by active status (null = all)
	 * @param string|bool|null $isTransfer Filter by type (null = all, true = only transfers, false = only bills)
	 * @param string|bool|null $revertibleToo When $activeOnly is not set, additionally
	 *                                        restrict to active bills PLUS inactive ones that still hold a stored payment
	 *                                        snapshot (canMarkUnpaid) — so the bills/transfers lists no longer have to fetch
	 *                                        every dead bill and filter client-side just to keep the Mark Unpaid ones (#365 follow-up).
	 */
	public function index($activeOnly = false, $isTransfer = null, $revertibleToo = false): DataResponse {
		try {
			// Convert string parameters to boolean
			$activeOnlyBool = $this->toBool($activeOnly);
			$isTransferBool = $isTransfer === null ? null : $this->toBool($isTransfer);
			$revertibleTooBool = $this->toBool($revertibleToo);

			// Use mapper's findByType if filtering by transfer status
			if ($isTransferBool !== null) {
				$isActive = $activeOnlyBool ? true : null;
				$bills = $this->service->findByType($this->userId, $isTransferBool, $isActive, $revertibleTooBool);
			} elseif ($activeOnlyBool) {
				$bills = $this->service->findActive($this->userId);
			} elseif ($revertibleTooBool) {
				$bills = $this->service->findByType($this->userId, null, null, true);
			} else {
				$bills = $this->service->findAll($this->userId);
			}
			$bills = $this->service->enrichBillsWithCurrency($bills, $this->userId);

			// Merge shared bills, held to the same filters as the user's own
			// (a shared transfer turned up on the Bills page and a shared
			// bill on the Transfers page) and priced in their own account's
			// currency, which they came without
			$shared = array_values(array_filter(
				$this->granularShareService->getSharedBills($this->userId),
				fn (array $bill) => $this->sharedBillMatches($bill, $activeOnlyBool, $isTransferBool, $revertibleTooBool)
			));
			if (!empty($shared)) {
				$shared = $this->service->enrichSharedBillsWithCurrency($shared);
				$bills = array_merge(
					array_map(fn ($b) => $b->jsonSerialize(), $bills),
					$shared
				);
				return new DataResponse($bills);
			}
			return new DataResponse($bills);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve bills'));
		}
	}

	/**
	 * Whether a shared bill belongs in a list fetched with these filters,
	 * the ones the user's own bills were fetched with.
	 */
	private function sharedBillMatches(array $bill, bool $activeOnly, ?bool $isTransfer, bool $revertibleToo): bool {
		if ($isTransfer !== null && (bool)($bill['isTransfer'] ?? false) !== $isTransfer) {
			return false;
		}
		$isActive = (bool)($bill['isActive'] ?? false);
		if ($activeOnly) {
			return $isActive;
		}
		if ($revertibleToo) {
			return $isActive || (bool)($bill['canMarkUnpaid'] ?? false);
		}
		return true;
	}

	/**
	 * Convert string/bool to boolean
	 */
	private function toBool($value): bool {
		if (is_bool($value)) {
			return $value;
		}
		return filter_var($value, FILTER_VALIDATE_BOOLEAN);
	}

	/**
	 * Get a single bill
	 * @NoAdminRequired
	 */
	public function show(int $id): DataResponse {
		try {
			$owner = $this->granularShareService->resolveOwner($this->userId, 'bill', $id);
			if ($owner === null) {
				return new DataResponse(
					['error' => $this->l->t('%1$s not found', [$this->l->t('Bill')])],
					Http::STATUS_NOT_FOUND
				);
			}

			$bill = $this->service->find($id, $owner);
			$this->service->enrichBillsWithCurrency([$bill], $owner);
			if ($owner === $this->userId) {
				return new DataResponse($bill);
			}
			// Named like the bills list: its category, and its split parts',
			// even one the owner didn't share with the user
			return new DataResponse($this->granularShareService->withCategoryNames([array_merge($bill->jsonSerialize(), [
				'_shared' => true,
				'_canWrite' => $this->granularShareService->canWrite($this->userId, 'bill', $id),
				'_canManage' => $this->granularShareService->canManage($this->userId, 'bill', $id),
			])])[0]);
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, $this->l->t('Bill'), ['billId' => $id]);
		}
	}

	/**
	 * The user id every lookup for bill $id must be scoped to.
	 *
	 * Granular sharing swaps visibility, not identity: getEffectiveUserId()
	 * returns the acting user, while every bill query is scoped to the bill's
	 * own user_id. Acting as themselves, a share recipient therefore got a
	 * DoesNotExistException from a bill requireWriteAccess() had just approved
	 * — "Failed to load bill", and the same for every payment action (#368).
	 *
	 * @throws DoesNotExistException when the bill is not visible to the user
	 */
	private function billOwner(int $id): string {
		$owner = $this->granularShareService->resolveOwner($this->userId, 'bill', $id);
		if ($owner === null) {
			throw new DoesNotExistException('Bill ' . $id . ' is not accessible to ' . $this->userId);
		}
		return $owner;
	}

	/**
	 * Refuse an account the acting user may not post new activity to.
	 *
	 * A bill's payments are booked as the owner of the account it names
	 * (#334), so the account is where the money lands whoever set the bill
	 * up. It has to be one the user owns or holds at write permission, the
	 * same rule TransactionController::create applies.
	 *
	 * @throws \OCA\Budget\Exception\ReadOnlyShareException
	 */
	private function requireWritableAccounts(?int ...$accountIds): void {
		foreach ($accountIds as $accountId) {
			if ($accountId !== null) {
				$this->requireWriteAccess('account', $accountId);
			}
		}
	}

	/**
	 * Paying, unpaying, skipping and recording a missed payment book into,
	 * or delete from, the bill's account (and a transfer's destination),
	 * pre-booked rows included. Someone the bill alone is shared with at
	 * write was let do that to an account hidden from them or shared with
	 * them read-only. The bill's owner is checked by BillService, with a
	 * message saying how to fix it; a bill with no account books nowhere.
	 *
	 * @throws \OCA\Budget\Exception\ReadOnlyShareException
	 */
	private function requireBillAccountsWritable(int $id): void {
		$owner = $this->billOwner($id);
		if ($owner === $this->userId) {
			return;
		}
		$bill = $this->service->find($id, $owner);
		$this->requireWritableAccounts(
			$bill->getAccountId(),
			($bill->getIsTransfer() ?? false) ? $bill->getDestinationAccountId() : null
		);
	}

	/**
	 * Refuse a new account for someone else's bill that its owner can't post
	 * to. Its payments are recorded as the owner, so a share recipient could
	 * move the owner's bill onto her own account and leave the owner unable
	 * to pay his own bill.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function requireAccountsUsableByOwner(string $billOwner, ?int ...$accountIds): void {
		if ($billOwner === $this->userId) {
			return;
		}
		foreach ($accountIds as $accountId) {
			if ($accountId !== null && !$this->granularShareService->canWrite($billOwner, 'account', $accountId)) {
				throw new \InvalidArgumentException($this->l->t('The owner of this bill can\'t use that account. Choose another one.'));
			}
		}
	}

	/**
	 * Create a new bill
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function create(): DataResponse {
		try {
			$data = $this->request->getParams();

			// Dynamic-amount bills resolve their amount server-side — no amount needed (#347)
			$amountType = $data['amountType'] ?? 'fixed';
			if (!in_array($amountType, ['fixed', 'statement', 'current_balance', 'minimum_payment'], true)) {
				return new DataResponse(['error' => $this->l->t('Invalid amount type')], Http::STATUS_BAD_REQUEST);
			}

			// Extract and validate required fields
			if (!isset($data['name']) || (!isset($data['amount']) && $amountType === 'fixed')) {
				return new DataResponse(['error' => $this->l->t('Name and amount are required')], Http::STATUS_BAD_REQUEST);
			}

			$name = $data['name'];
			$amount = (float)($data['amount'] ?? 0);
			$frequency = $data['frequency'] ?? 'monthly';
			$dueDay = isset($data['dueDay']) ? (int)$data['dueDay'] : null;
			$dueMonth = isset($data['dueMonth']) ? (int)$data['dueMonth'] : null;
			$categoryId = isset($data['categoryId']) ? (int)$data['categoryId'] : null;
			$accountId = isset($data['accountId']) ? (int)$data['accountId'] : null;
			$autoDetectPattern = $data['autoDetectPattern'] ?? null;
			$description = $data['description'] ?? null;
			$notes = $data['notes'] ?? null;
			$reminderDays = isset($data['reminderDays']) ? (int)$data['reminderDays'] : null;
			$customRecurrencePattern = $data['customRecurrencePattern'] ?? null;
			// Defaults to true: pre-booking the next occurrence is the app's
			// established behaviour, so omitting the field opts in (#311)
			$createTransaction = filter_var($data['createTransaction'] ?? true, FILTER_VALIDATE_BOOLEAN);
			$transactionDate = $data['transactionDate'] ?? null;
			// filter_var, not a bare cast: a string "false" (some clients/serializations
			// send booleans as strings) is truthy under (bool), which silently turned
			// auto-pay ON for bills created with the toggle off (#335).
			$autoPayEnabled = filter_var($data['autoPayEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
			$isTransfer = filter_var($data['isTransfer'] ?? false, FILTER_VALIDATE_BOOLEAN);
			$destinationAccountId = isset($data['destinationAccountId']) ? (int)$data['destinationAccountId'] : null;
			$transferDescriptionPattern = $data['transferDescriptionPattern'] ?? null;
			$tagIds = isset($data['tagIds']) && is_array($data['tagIds']) ? array_map('intval', $data['tagIds']) : [];
			$startDate = $data['startDate'] ?? null;
			$endDate = $data['endDate'] ?? null;
			$remainingPayments = isset($data['remainingPayments']) ? (int)$data['remainingPayments'] : null;
			$splitTemplate = isset($data['splitTemplate']) && is_array($data['splitTemplate']) ? $data['splitTemplate'] : null;
			$excludedFromForecast = filter_var($data['excludedFromForecast'] ?? false, FILTER_VALIDATE_BOOLEAN);

			// Validate split template if provided
			if ($splitTemplate !== null) {
				$splitValidation = $this->validateSplitTemplate($splitTemplate, $amount);
				if (!$splitValidation['valid']) {
					return new DataResponse(['error' => $splitValidation['error']], Http::STATUS_BAD_REQUEST);
				}
			}

			// Validate auto-pay requires account
			if ($autoPayEnabled && $accountId === null) {
				return new DataResponse(
					['error' => $this->l->t('Auto-pay requires an account to be set')],
					Http::STATUS_BAD_REQUEST
				);
			}

			// Validate transfer requires destination account
			if ($isTransfer && $destinationAccountId === null) {
				return new DataResponse(
					['error' => $this->l->t('Transfer requires a destination account')],
					Http::STATUS_BAD_REQUEST
				);
			}

			// Validate transfer cannot have same source and destination
			if ($isTransfer && $accountId !== null && $accountId === $destinationAccountId) {
				return new DataResponse(
					['error' => $this->l->t('Cannot transfer to the same account')],
					Http::STATUS_BAD_REQUEST
				);
			}

			$this->requireWritableAccounts($accountId, $destinationAccountId);

			// Validate name (required)
			$nameValidation = $this->validationService->validateName($name, true);
			if (!$nameValidation['valid']) {
				return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
			}
			$name = $nameValidation['sanitized'];

			// Validate frequency
			$frequencyValidation = $this->validationService->validateFrequency($frequency);
			if (!$frequencyValidation['valid']) {
				return new DataResponse(['error' => $frequencyValidation['error']], Http::STATUS_BAD_REQUEST);
			}
			$frequency = $frequencyValidation['formatted'];

			// Validate dueDay range
			if ($dueDay !== null && ($dueDay < 1 || $dueDay > 31)) {
				return new DataResponse(['error' => $this->l->t('Due day must be between 1 and 31')], Http::STATUS_BAD_REQUEST);
			}

			// Validate dueMonth range
			if ($dueMonth !== null && ($dueMonth < 1 || $dueMonth > 12)) {
				return new DataResponse(['error' => $this->l->t('Due month must be between 1 and 12')], Http::STATUS_BAD_REQUEST);
			}

			// Validate autoDetectPattern if provided
			if ($autoDetectPattern !== null && $autoDetectPattern !== '') {
				$patternValidation = $this->validationService->validatePattern($autoDetectPattern, false);
				if (!$patternValidation['valid']) {
					return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$autoDetectPattern = $patternValidation['sanitized'];
			}

			// Validate notes if provided
			if ($notes !== null && $notes !== '') {
				$notesValidation = $this->validationService->validateNotes($notes);
				if (!$notesValidation['valid']) {
					return new DataResponse(['error' => $notesValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$notes = $notesValidation['sanitized'];
			}

			// Validate reminderDays if provided
			if ($reminderDays !== null && ($reminderDays < 0 || $reminderDays > 30)) {
				return new DataResponse(['error' => $this->l->t('Reminder days must be between 0 and 30')], Http::STATUS_BAD_REQUEST);
			}

			// Validate customRecurrencePattern if provided
			if ($customRecurrencePattern !== null && $customRecurrencePattern !== '' && $frequency === 'custom') {
				$patternValidation = $this->validateCustomPattern($customRecurrencePattern);
				if (!$patternValidation['valid']) {
					return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
				}
			}

			// Validate transactionDate if provided
			if ($transactionDate !== null && $transactionDate !== '') {
				$dateValidation = $this->validationService->validateDate($transactionDate, $this->l->t('Transaction date'), false);
				if (!$dateValidation['valid']) {
					return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
				}
			}

			// Validate startDate if provided
			if ($startDate !== null && $startDate !== '') {
				$startDateValidation = $this->validationService->validateDate($startDate, $this->l->t('Start date'), false);
				if (!$startDateValidation['valid']) {
					return new DataResponse(['error' => $startDateValidation['error']], Http::STATUS_BAD_REQUEST);
				}
			} else {
				$startDate = null;
			}

			// Validate endDate if provided
			if ($endDate !== null && $endDate !== '') {
				$endDateValidation = $this->validationService->validateDate($endDate, $this->l->t('End date'), false);
				if (!$endDateValidation['valid']) {
					return new DataResponse(['error' => $endDateValidation['error']], Http::STATUS_BAD_REQUEST);
				}
			} else {
				$endDate = null;
			}

			// Start date must not be after end date
			if ($startDate !== null && $endDate !== null && $startDate > $endDate) {
				return new DataResponse(['error' => $this->l->t('Start date must be before the end date')], Http::STATUS_BAD_REQUEST);
			}

			// Validate remainingPayments if provided
			if ($remainingPayments !== null && $remainingPayments < 1) {
				return new DataResponse(['error' => $this->l->t('Remaining payments must be at least 1')], Http::STATUS_BAD_REQUEST);
			}

			$this->requireUsableCategories($this->getEffectiveUserId(), $categoryId, $splitTemplate);
			$this->requireSplitsUsableByAccountOwner($this->getEffectiveUserId(), $accountId, $splitTemplate);
			$this->requireCategoryUsableByAccountOwners(
				$this->getEffectiveUserId(),
				$categoryId,
				[$accountId, $isTransfer ? $destinationAccountId : null]
			);
			$this->requireUsableBillTags(
				$this->getEffectiveUserId(),
				$tagIds,
				$tagIds,
				[$accountId, $isTransfer ? $destinationAccountId : null]
			);

			$bill = $this->service->create(
				$this->getEffectiveUserId(),
				$name,
				$amount,
				$frequency,
				$dueDay,
				$dueMonth,
				$categoryId,
				$accountId,
				$autoDetectPattern,
				$description,
				$notes,
				$reminderDays,
				$customRecurrencePattern,
				$createTransaction,
				$transactionDate,
				$autoPayEnabled,
				$isTransfer,
				$destinationAccountId,
				$transferDescriptionPattern,
				$tagIds,
				$endDate,
				$remainingPayments,
				$splitTemplate,
				$startDate,
				$excludedFromForecast,
				$amountType
			);

			return new DataResponse($bill, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			// Validation that lives only in the service (the dynamic amount-type
			// rules) would otherwise reach the generic catch below and lose its
			// message, leaving the user with no way to tell what was wrong (#362).
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to create bill'));
		}
	}

	/**
	 * Update a bill
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function update(int $id): DataResponse {
		try {
			$this->requireWriteAccess('bill', $id);
			$ownerId = $this->billOwner($id);

			$data = $this->request->getParams();

			$updates = [];

			// Validate name if provided
			if (isset($data['name'])) {
				$nameValidation = $this->validationService->validateName($data['name'], false);
				if (!$nameValidation['valid']) {
					return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$updates['name'] = $nameValidation['sanitized'];
			}

			// Validate frequency if provided
			if (isset($data['frequency'])) {
				$frequencyValidation = $this->validationService->validateFrequency($data['frequency']);
				if (!$frequencyValidation['valid']) {
					return new DataResponse(['error' => $frequencyValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$updates['frequency'] = $frequencyValidation['formatted'];
			}

			// Validate dueDay if provided
			if (array_key_exists('dueDay', $data)) {
				if ($data['dueDay'] !== null) {
					if ($data['dueDay'] < 1 || $data['dueDay'] > 31) {
						return new DataResponse(['error' => $this->l->t('Due day must be between 1 and 31')], Http::STATUS_BAD_REQUEST);
					}
					$updates['dueDay'] = (int)$data['dueDay'];
				} else {
					$updates['dueDay'] = null;
				}
			}

			// Validate dueMonth if provided
			if (array_key_exists('dueMonth', $data)) {
				if ($data['dueMonth'] !== null) {
					if ($data['dueMonth'] < 1 || $data['dueMonth'] > 12) {
						return new DataResponse(['error' => $this->l->t('Due month must be between 1 and 12')], Http::STATUS_BAD_REQUEST);
					}
					$updates['dueMonth'] = (int)$data['dueMonth'];
				} else {
					$updates['dueMonth'] = null;
				}
			}

			// Validate autoDetectPattern if provided; null clears it (isset()
			// skipped a null, so a cleared pattern came back)
			if (array_key_exists('autoDetectPattern', $data)) {
				if ($data['autoDetectPattern'] !== null && $data['autoDetectPattern'] !== '') {
					$patternValidation = $this->validationService->validatePattern($data['autoDetectPattern'], false);
					if (!$patternValidation['valid']) {
						return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
					}
					$updates['autoDetectPattern'] = $patternValidation['sanitized'];
				} else {
					$updates['autoDetectPattern'] = null;
				}
			}

			// Validate notes if provided
			if (isset($data['notes'])) {
				if ($data['notes'] !== null && $data['notes'] !== '') {
					$notesValidation = $this->validationService->validateNotes($data['notes']);
					if (!$notesValidation['valid']) {
						return new DataResponse(['error' => $notesValidation['error']], Http::STATUS_BAD_REQUEST);
					}
					$updates['notes'] = $notesValidation['sanitized'];
				} else {
					$updates['notes'] = null;
				}
			}

			if (array_key_exists('description', $data)) {
				$updates['description'] = $data['description'] !== '' ? $data['description'] : null;
			}
			// Handle other fields
			if (isset($data['amount'])) {
				$updates['amount'] = (float)$data['amount'];
			}
			if (array_key_exists('categoryId', $data)) {
				$updates['categoryId'] = $data['categoryId'] !== null ? (int)$data['categoryId'] : null;
			}
			if (array_key_exists('accountId', $data)) {
				$updates['accountId'] = $data['accountId'] !== null ? (int)$data['accountId'] : null;
			}
			if (isset($data['active'])) {
				$updates['active'] = filter_var($data['active'], FILTER_VALIDATE_BOOLEAN);
			}
			if (array_key_exists('reminderDays', $data)) {
				if ($data['reminderDays'] !== null) {
					if ($data['reminderDays'] < 0 || $data['reminderDays'] > 30) {
						return new DataResponse(['error' => $this->l->t('Reminder days must be between 0 and 30')], Http::STATUS_BAD_REQUEST);
					}
					$updates['reminderDays'] = (int)$data['reminderDays'];
				} else {
					$updates['reminderDays'] = null;
				}
			}
			if (array_key_exists('lastPaidDate', $data)) {
				$updates['lastPaidDate'] = $data['lastPaidDate'];
			}
			if (array_key_exists('customRecurrencePattern', $data)) {
				if ($data['customRecurrencePattern'] !== null && $data['customRecurrencePattern'] !== '') {
					$patternValidation = $this->validateCustomPattern($data['customRecurrencePattern']);
					if (!$patternValidation['valid']) {
						return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
					}
					$updates['customRecurrencePattern'] = $data['customRecurrencePattern'];
				} else {
					$updates['customRecurrencePattern'] = null;
				}
			}
			if (isset($data['autoPayEnabled'])) {
				$updates['autoPayEnabled'] = filter_var($data['autoPayEnabled'], FILTER_VALIDATE_BOOLEAN);
			}
			if (isset($data['autoPayFailed'])) {
				$updates['autoPayFailed'] = filter_var($data['autoPayFailed'], FILTER_VALIDATE_BOOLEAN);
			}
			if (isset($data['isTransfer'])) {
				$updates['isTransfer'] = filter_var($data['isTransfer'], FILTER_VALIDATE_BOOLEAN);
			}
			if (array_key_exists('destinationAccountId', $data)) {
				$updates['destinationAccountId'] = $data['destinationAccountId'] !== null ? (int)$data['destinationAccountId'] : null;
			}
			if (array_key_exists('transferDescriptionPattern', $data)) {
				$updates['transferDescriptionPattern'] = $data['transferDescriptionPattern'];
			}
			if (array_key_exists('tagIds', $data)) {
				$tagIds = is_array($data['tagIds']) ? array_map('intval', $data['tagIds']) : [];
				$updates['tagIds'] = empty($tagIds) ? null : json_encode(array_values($tagIds));
			}
			if (array_key_exists('startDate', $data)) {
				if ($data['startDate'] !== null && $data['startDate'] !== '') {
					$startDateValidation = $this->validationService->validateDate($data['startDate'], $this->l->t('Start date'), false);
					if (!$startDateValidation['valid']) {
						return new DataResponse(['error' => $startDateValidation['error']], Http::STATUS_BAD_REQUEST);
					}
					$updates['startDate'] = $data['startDate'];
				} else {
					$updates['startDate'] = null;
				}
			}
			if (array_key_exists('endDate', $data)) {
				if ($data['endDate'] !== null && $data['endDate'] !== '') {
					$endDateValidation = $this->validationService->validateDate($data['endDate'], $this->l->t('End date'), false);
					if (!$endDateValidation['valid']) {
						return new DataResponse(['error' => $endDateValidation['error']], Http::STATUS_BAD_REQUEST);
					}
					$updates['endDate'] = $data['endDate'];
				} else {
					$updates['endDate'] = null;
				}
			}
			if (array_key_exists('remainingPayments', $data)) {
				if ($data['remainingPayments'] !== null && $data['remainingPayments'] !== '') {
					$remaining = (int)$data['remainingPayments'];
					if ($remaining < 1) {
						return new DataResponse(['error' => $this->l->t('Remaining payments must be at least 1')], Http::STATUS_BAD_REQUEST);
					}
					$updates['remainingPayments'] = $remaining;
				} else {
					$updates['remainingPayments'] = null;
				}
			}

			// Handle split template
			if (array_key_exists('splitTemplate', $data)) {
				if (!empty($data['splitTemplate']) && is_array($data['splitTemplate'])) {
					$billAmount = $updates['amount'] ?? null;
					if ($billAmount === null) {
						// Get existing bill amount for validation
						$existingBill = $this->service->find($id, $ownerId);
						$billAmount = $existingBill->getAmount();
					}
					$splitValidation = $this->validateSplitTemplate($data['splitTemplate'], $billAmount);
					if (!$splitValidation['valid']) {
						return new DataResponse(['error' => $splitValidation['error']], Http::STATUS_BAD_REQUEST);
					}
					$updates['splitTemplate'] = json_encode(array_values($data['splitTemplate']));
					// Splits define the categories — a bill-level category must
					// not coexist (create() enforces the same invariant)
					$updates['categoryId'] = null;
				} else {
					$updates['splitTemplate'] = null;
				}
			} elseif (isset($updates['amount'])) {
				// Amount changed without resending the split template: a stored
				// template whose sum no longer matches would silently fail at
				// payment time (the transaction imports unsplit and
				// uncategorized), so validate it against the new amount.
				$existingBill = $this->service->find($id, $ownerId);
				$storedSplits = $existingBill->getSplitTemplateArray();
				if (!empty($storedSplits)) {
					$splitValidation = $this->validateSplitTemplate($storedSplits, $updates['amount']);
					if (!$splitValidation['valid']) {
						return new DataResponse([
							'error' => $this->l->t('The new amount does not match the bill\'s split template. Update the splits together with the amount.'),
						], Http::STATUS_BAD_REQUEST);
					}
				}
			}

			// Handle exclude-from-forecast flag (#270)
			if (array_key_exists('excludedFromForecast', $data)) {
				$updates['excludedFromForecast'] = filter_var($data['excludedFromForecast'], FILTER_VALIDATE_BOOLEAN);
			}

			// Handle pre-create future transaction flag (#311)
			if (array_key_exists('createTransaction', $data)) {
				$updates['createTransaction'] = filter_var($data['createTransaction'], FILTER_VALIDATE_BOOLEAN);
			}

			// Handle amount type (#347) — deep validation (transfer +
			// card destination) happens in the service
			if (isset($data['amountType'])) {
				if (!in_array($data['amountType'], ['fixed', 'statement', 'current_balance', 'minimum_payment'], true)) {
					return new DataResponse(['error' => $this->l->t('Invalid amount type')], Http::STATUS_BAD_REQUEST);
				}
				$updates['amountType'] = $data['amountType'];
			}

			// Validate transfer constraints if being updated
			if (isset($updates['isTransfer']) && $updates['isTransfer']) {
				$destinationId = $updates['destinationAccountId'] ?? null;
				if ($destinationId === null) {
					// Check existing bill for destination account if not in updates
					try {
						$existingBill = $this->service->find($id, $ownerId);
						$destinationId = $existingBill->getDestinationAccountId();
					} catch (\Exception $e) {
						// Will be caught by outer try-catch
					}
				}
				if ($destinationId === null) {
					return new DataResponse(
						['error' => $this->l->t('Transfer requires a destination account')],
						Http::STATUS_BAD_REQUEST
					);
				}
			}

			// Validate cannot transfer to same account
			if (isset($updates['destinationAccountId']) || isset($updates['accountId'])) {
				$accountId = $updates['accountId'] ?? null;
				$destinationId = $updates['destinationAccountId'] ?? null;

				// If only one is being updated, get the other from existing bill
				if ($accountId === null || $destinationId === null) {
					try {
						$existingBill = $this->service->find($id, $ownerId);
						if ($accountId === null) {
							$accountId = $existingBill->getAccountId();
						}
						if ($destinationId === null) {
							$destinationId = $existingBill->getDestinationAccountId();
						}
					} catch (\Exception $e) {
						// Will be caught by outer try-catch
					}
				}

				if ($accountId !== null && $destinationId !== null && $accountId === $destinationId) {
					return new DataResponse(
						['error' => $this->l->t('Cannot transfer to the same account')],
						Http::STATUS_BAD_REQUEST
					);
				}
			}

			// Auto-pay has nothing to pay from without an account. create()
			// has always refused that pairing; update() did not, so a client
			// that dropped the account while leaving auto-pay on left the bill
			// in a state the create path calls invalid (#370). Only whichever
			// side the payload omits is read back off the stored bill.
			$autoPayInUpdates = array_key_exists('autoPayEnabled', $updates);
			$accountInUpdates = array_key_exists('accountId', $updates);
			if ($autoPayInUpdates || $accountInUpdates) {
				$autoPayOn = $autoPayInUpdates ? (bool)$updates['autoPayEnabled'] : null;
				$effectiveAccountId = $accountInUpdates ? $updates['accountId'] : null;
				$accountKnown = $accountInUpdates;

				if ($autoPayOn === null || !$accountKnown) {
					try {
						$existingBill = $this->service->find($id, $ownerId);
					} catch (\Exception $e) {
						// A missing bill is reported by the update call below
						$existingBill = null;
					}
					if ($existingBill !== null) {
						if ($autoPayOn === null) {
							$autoPayOn = (bool)$existingBill->getAutoPayEnabled();
						}
						if (!$accountKnown) {
							$effectiveAccountId = $existingBill->getAccountId();
							$accountKnown = true;
						}
					}
				}

				if ($autoPayOn === true && $accountKnown && $effectiveAccountId === null) {
					return new DataResponse(
						['error' => $this->l->t('Auto-pay requires an account to be set')],
						Http::STATUS_BAD_REQUEST
					);
				}
			}

			// Only an account that changes is a new place to post money to. A
			// shared bill's form sends back the stored account even when it was
			// never shared with the editor (#370), and that has to keep saving.
			$destinationInUpdates = array_key_exists('destinationAccountId', $updates);
			if ($accountInUpdates || $destinationInUpdates) {
				$storedBill = $this->service->find($id, $ownerId);
				$changedAccounts = [
					$accountInUpdates && $updates['accountId'] !== $storedBill->getAccountId()
						? $updates['accountId'] : null,
					$destinationInUpdates && $updates['destinationAccountId'] !== $storedBill->getDestinationAccountId()
						? $updates['destinationAccountId'] : null,
				];
				$this->requireWritableAccounts(...$changedAccounts);
				$this->requireAccountsUsableByOwner($ownerId, ...$changedAccounts);
			}

			// A dynamic amount is read off the destination card, and the bill
			// then shows it as its amount: switching a shared bill to one let
			// a recipient read the balance of a card never shared with them.
			// Only a change needs it; a form that sends back the stored type
			// keeps saving.
			if (isset($updates['amountType']) && $updates['amountType'] !== 'fixed') {
				$storedBill = $storedBill ?? $this->service->find($id, $ownerId);
				$destinationId = array_key_exists('destinationAccountId', $updates)
					? $updates['destinationAccountId'] : $storedBill->getDestinationAccountId();
				if ($updates['amountType'] !== ($storedBill->getAmountType() ?? 'fixed')
					&& $destinationId !== null && !$this->canAccessEntity('account', (int)$destinationId)) {
					return new DataResponse(
						['error' => $this->l->t('This amount is read from an account that isn\'t shared with you. Choose a fixed amount.')],
						Http::STATUS_BAD_REQUEST
					);
				}
			}

			if (empty($updates)) {
				return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
			}

			$this->requireUsableCategories(
				$ownerId,
				array_key_exists('categoryId', $updates) ? $updates['categoryId'] : null,
				isset($data['splitTemplate']) && is_array($data['splitTemplate']) ? $data['splitTemplate'] : null
			);
			if ($ownerId !== $this->userId
				&& (array_key_exists('categoryId', $updates) || (isset($data['splitTemplate']) && is_array($data['splitTemplate'])))) {
				$storedBill = $storedBill ?? $this->service->find($id, $ownerId);
				$this->requireCategoriesVisibleToEditor(
					$ownerId,
					$storedBill,
					array_key_exists('categoryId', $updates) ? $updates['categoryId'] : null,
					isset($data['splitTemplate']) && is_array($data['splitTemplate']) ? $data['splitTemplate'] : []
				);
			}
			if (isset($data['splitTemplate']) || array_key_exists('accountId', $updates)) {
				$storedBill = $storedBill ?? $this->service->find($id, $ownerId);
				$this->requireSplitsUsableByAccountOwner(
					$ownerId,
					array_key_exists('accountId', $updates) ? $updates['accountId'] : $storedBill->getAccountId(),
					isset($data['splitTemplate']) && is_array($data['splitTemplate']) ? $data['splitTemplate'] : $storedBill->getSplitTemplateArray()
				);
			}
			if (array_key_exists('categoryId', $updates) || $accountInUpdates || $destinationInUpdates
				|| array_key_exists('isTransfer', $updates)) {
				$storedBill = $storedBill ?? $this->service->find($id, $ownerId);
				$this->requireChangedCategoryUsableByAccountOwners($ownerId, $storedBill, $updates);
			}
			if (array_key_exists('tagIds', $updates) || $accountInUpdates || $destinationInUpdates
				|| array_key_exists('isTransfer', $updates)) {
				$storedBill = $storedBill ?? $this->service->find($id, $ownerId);
				$updates = $this->keepTagsTheEditorCannotSee($ownerId, $storedBill, $updates);
				$this->requireChangedBillTagsUsable($ownerId, $storedBill, $updates);
			}

			$bill = $this->service->update($id, $ownerId, $updates);
			return new DataResponse($bill);
		} catch (\InvalidArgumentException $e) {
			// Same as create(): service-only validation keeps its message (#362).
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to update bill'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		}
	}

	/**
	 * Delete a bill
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroy(int $id): DataResponse {
		try {
			$owner = $this->billOwner($id);
			// Editing a shared bill needs write; deleting it needs Full control
			if ($owner !== $this->userId && !$this->granularShareService->canManage($this->userId, 'bill', $id)) {
				return new DataResponse(['error' => $this->l->t('Deleting a shared item needs Full control from its owner')], Http::STATUS_FORBIDDEN);
			}
			$this->service->delete($id, $owner);
			return new DataResponse(['status' => 'success']);
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, $this->l->t('Bill'), ['billId' => $id]);
		}
	}

	/**
	 * Find existing transactions that might match a bill payment.
	 * @NoAdminRequired
	 */
	public function findMatchingTransactions(int $id): DataResponse {
		try {
			$candidates = $this->service->findMatchingTransactions($id, $this->billOwner($id), $this->userId);
			return new DataResponse($candidates);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to find matching transactions'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		}
	}

	/**
	 * Bills recently marked paid without a recorded transaction (#274)
	 * @NoAdminRequired
	 */
	public function unrecordedPayments(): DataResponse {
		try {
			$items = $this->service->findUnrecordedPayments($this->getEffectiveUserId());
			return new DataResponse(['items' => $items, 'count' => count($items)]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to check for unrecorded payments'));
		}
	}

	/**
	 * Record the missing transaction for a payment marked paid without one (#274)
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function recordMissedPayment(int $id): DataResponse {
		try {
			$this->requireWriteAccess('bill', $id);
			$this->requireBillAccountsWritable($id);
			$result = $this->service->recordMissedPayment($id, $this->billOwner($id));
			return new DataResponse($result);
		} catch (\InvalidArgumentException $e) {
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to record the payment'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		}
	}

	/**
	 * Dismiss a payment from the unrecorded-payments card: the missing
	 * transaction is deliberate (#394). Stored against the same user the
	 * card is worked out for.
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function dismissUnrecordedPayment(int $id): DataResponse {
		try {
			$this->requireWriteAccess('bill', $id);
			$this->service->dismissUnrecordedPayment($id, $this->getEffectiveUserId());
			return new DataResponse(['status' => 'success']);
		} catch (\InvalidArgumentException $e) {
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to dismiss the payment'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		}
	}

	/**
	 * Mark a bill as paid
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function markPaid(int $id, ?string $paidDate = null): DataResponse {
		try {
			$this->requireWriteAccess('bill', $id);
			$this->requireBillAccountsWritable($id);
			$params = $this->request->getParams();
			// Whether to record the payment itself. It used to be called
			// createNextTransaction, which is what it also did until #376
			// separated the two; the old name still works so a bundle cached
			// from before the rename does not silently record nothing.
			// filter_var, not a cast: (bool)"false" is true
			$recordPayment = filter_var($params['recordPayment'] ?? $params['createNextTransaction'] ?? false, FILTER_VALIDATE_BOOLEAN);
			$existingTransactionId = isset($params['existingTransactionId']) ? (int)$params['existingTransactionId'] : null;
			// The occurrence the page showed, so a second click or a stale
			// tab is refused instead of paying it twice
			$expectedDueDate = is_string($params['dueDate'] ?? null) ? $params['dueDate'] : null;

			$result = $this->service->markPaid($id, $this->billOwner($id), $paidDate, $recordPayment, $existingTransactionId, $expectedDueDate, $this->userId);
			return new DataResponse($result);
		} catch (\InvalidArgumentException $e) {
			// Service-only validation keeps its message (#362)
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to mark bill as paid'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		}
	}

	/**
	 * Undo a mark-paid action (the toast's Undo)
	 *
	 * Reverts from the snapshot markPaid stored on the bill, exactly like
	 * markUnpaid. Whatever undo data the client sends back is ignored: its
	 * transaction ids used to be deleted as their account owner with nothing
	 * tying them to this bill, so any user could delete any transaction on
	 * the instance by id.
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function undoPaid(int $id, bool $confirmReconciled = false): DataResponse {
		try {
			$this->requireWriteAccess('bill', $id);
			$this->requireBillAccountsWritable($id);
			$bill = $this->service->markUnpaid($id, $this->billOwner($id), $confirmReconciled);
			return new DataResponse($bill);
		} catch (ReconciledPaymentException $e) {
			return $this->reconciledPaymentResponse($e);
		} catch (\InvalidArgumentException $e) {
			// Service-only validation keeps its message (#362)
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to undo mark as paid'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		}
	}

	/**
	 * Durable "mark as unpaid": revert the last payment from the snapshot
	 * persisted on the bill (#365)
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function markUnpaid(int $id, bool $confirmReconciled = false): DataResponse {
		try {
			$this->requireWriteAccess('bill', $id);
			$this->requireBillAccountsWritable($id);
			$bill = $this->service->markUnpaid($id, $this->billOwner($id), $confirmReconciled);
			return new DataResponse($bill);
		} catch (ReconciledPaymentException $e) {
			return $this->reconciledPaymentResponse($e);
		} catch (\InvalidArgumentException $e) {
			// Service-only validation keeps its message (#362)
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to mark bill as unpaid'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		}
	}

	/**
	 * The revert would delete a payment reconciled against a bank statement.
	 * The code lets the client warn and send it again with confirmReconciled.
	 */
	private function reconciledPaymentResponse(ReconciledPaymentException $e): DataResponse {
		return new DataResponse(
			['error' => $e->getMessage(), 'code' => 'reconciled'],
			Http::STATUS_CONFLICT
		);
	}

	/**
	 * Skip a bill payment, advancing to the next due date
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function skipPayment(int $id): DataResponse {
		try {
			$this->requireWriteAccess('bill', $id);
			$this->requireBillAccountsWritable($id);
			$result = $this->service->skipPayment($id, $this->billOwner($id));
			return new DataResponse($result);
		} catch (\InvalidArgumentException $e) {
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to skip bill payment'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		}
	}

	/**
	 * Undo a skipped bill payment
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function undoSkip(int $id): DataResponse {
		try {
			$this->requireWriteAccess('bill', $id);
			$this->requireBillAccountsWritable($id);
			$params = $this->request->getParams();
			$previousNextDueDate = $params['previousNextDueDate'] ?? null;

			if ($previousNextDueDate === null) {
				return new DataResponse(['error' => $this->l->t('Missing previous due date')], Http::STATUS_BAD_REQUEST);
			}

			$bill = $this->service->undoSkip($id, $this->billOwner($id), $previousNextDueDate);
			return new DataResponse($bill);
		} catch (\InvalidArgumentException $e) {
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to undo skip'), Http::STATUS_BAD_REQUEST, ['billId' => $id]);
		}
	}

	/**
	 * Get upcoming bills (next 30 days, sorted by due date)
	 * @NoAdminRequired
	 */
	public function upcoming(int $days = 30): DataResponse {
		try {
			// Own and shared bills, as the Bills page and the public API list
			// them; the dashboard tile has a setting to leave shared ones out
			return new DataResponse($this->upcomingBills->upcoming($this->getEffectiveUserId(), $days));
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve upcoming bills'));
		}
	}

	/**
	 * Get bills due this month
	 * @NoAdminRequired
	 */
	public function dueThisMonth(): DataResponse {
		try {
			$bills = $this->service->findDueThisMonth($this->getEffectiveUserId());
			$bills = $this->service->enrichBillsWithCurrency($bills, $this->getEffectiveUserId());
			return new DataResponse($bills);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve bills due this month'));
		}
	}

	/**
	 * Get overdue bills
	 * @NoAdminRequired
	 */
	public function overdue(): DataResponse {
		try {
			$bills = $this->service->findOverdue($this->getEffectiveUserId());
			$bills = $this->service->enrichBillsWithCurrency($bills, $this->getEffectiveUserId());
			return new DataResponse($bills);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve overdue bills'));
		}
	}

	/**
	 * Get monthly summary of bills, or of transfers for the Transfers page
	 * @NoAdminRequired
	 * @param string|bool $isTransfer true for the Transfers page's cards
	 */
	public function summary($isTransfer = false): DataResponse {
		try {
			// The cards count the shared bills the list under them shows
			$summary = $this->service->getMonthlySummary(
				$this->getEffectiveUserId(),
				$this->granularShareService->getSharedBillEntities($this->getEffectiveUserId()),
				$this->toBool($isTransfer)
			);
			return new DataResponse($summary);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve bill summary'));
		}
	}

	/**
	 * Get bill status for a specific month (paid/unpaid)
	 * @NoAdminRequired
	 */
	public function statusForMonth(?string $month = null): DataResponse {
		// Checked here: anything but YYYY-MM made date() throw a TypeError in
		// the service, which the catch below doesn't take, so a 500
		if ($month === '') {
			$month = null;
		} elseif ($month !== null && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
			return new DataResponse(
				['error' => $this->l->t('Invalid month format. Use YYYY-MM')],
				Http::STATUS_BAD_REQUEST
			);
		}

		try {
			$status = $this->service->getBillStatusForMonth($this->getEffectiveUserId(), $month);
			return new DataResponse($status);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve bill status'));
		}
	}

	/**
	 * Auto-detect recurring bills from transaction history. Find Transfers
	 * sends transfers=true to also see debits linked to a transfer's other
	 * leg; Detect Bills leaves those out.
	 * @NoAdminRequired
	 */
	public function detect(int $months = 6, $transfers = 'false'): DataResponse {
		try {
			$detected = $this->service->detectRecurringBills($this->getEffectiveUserId(), $months, $this->toBool($transfers));
			return new DataResponse($detected);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to detect recurring bills'));
		}
	}

	/**
	 * Proactive recurring-bill suggestions: new candidates only (not billed,
	 * not dismissed, confident).
	 * @NoAdminRequired
	 */
	public function suggestions(int $limit = 5): DataResponse {
		try {
			$result = $this->suggestionService->getSuggestions($this->getEffectiveUserId(), $limit);
			return new DataResponse($result);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to load bill suggestions'));
		}
	}

	/**
	 * Dismiss a suggestion so it never reappears.
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function dismissSuggestion(string $patternKey): DataResponse {
		try {
			if (trim($patternKey) === '') {
				return new DataResponse(['error' => $this->l->t('Invalid request data')], Http::STATUS_BAD_REQUEST);
			}
			$this->suggestionService->dismiss($this->getEffectiveUserId(), $patternKey);
			return new DataResponse(['status' => 'success']);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to dismiss suggestion'));
		}
	}

	/**
	 * Create bills from detected patterns
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 10, period: 60)]
	public function createFromDetected(): DataResponse {
		try {
			$data = $this->request->getParams();
			if (!isset($data['bills'])) {
				return new DataResponse(['error' => $this->l->t('Invalid request data')], Http::STATUS_BAD_REQUEST);
			}

			$items = [];
			foreach ((array)$data['bills'] as $item) {
				if (!is_array($item)) {
					return new DataResponse(['error' => $this->l->t('Invalid request data')], Http::STATUS_BAD_REQUEST);
				}
				$this->requireWritableAccounts(
					isset($item['accountId']) ? (int)$item['accountId'] : null,
					isset($item['destinationAccountId']) ? (int)$item['destinationAccountId'] : null
				);
				$this->requireUsableCategories($this->getEffectiveUserId(), $item['categoryId'] ?? null, null);
				$this->requireCategoryUsableByAccountOwners($this->getEffectiveUserId(), $item['categoryId'] ?? null, [
					isset($item['accountId']) ? (int)$item['accountId'] : null,
					isset($item['destinationAccountId']) ? (int)$item['destinationAccountId'] : null,
				]);

				// Checked like a bill created by hand, every item before any is
				// created: a candidate whose description cleaned away to nothing
				// was saved with no name
				$error = $this->validateDetectedItem($item);
				if ($error !== null) {
					return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
				}
				$items[] = $item;
			}

			$created = $this->service->createFromDetected($this->getEffectiveUserId(), $items);
			return new DataResponse([
				'created' => count($created),
				'bills' => $created,
			], Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return $this->handleValidationError($e);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to create bills from detected patterns'));
		}
	}

	/**
	 * Validate one detected bill or transfer the way create() validates a
	 * form, writing back the cleaned values. Its name is the first of name,
	 * suggestedName and description that isn't blank.
	 *
	 * @return string|null The error, or null when the item is fine
	 */
	private function validateDetectedItem(array &$item): ?string {
		$name = '';
		foreach (['name', 'suggestedName', 'description'] as $field) {
			if (is_string($item[$field] ?? null) && trim($item[$field]) !== '') {
				$name = $item[$field];
				break;
			}
		}
		$nameValidation = $this->validationService->validateName($name, true);
		if (!$nameValidation['valid']) {
			return $nameValidation['error'];
		}
		$item['name'] = $nameValidation['sanitized'];

		$frequencyValidation = $this->validationService->validateFrequency((string)($item['frequency'] ?? 'monthly'));
		if (!$frequencyValidation['valid']) {
			return $frequencyValidation['error'];
		}
		$item['frequency'] = $frequencyValidation['formatted'];

		if (isset($item['dueDay']) && ((int)$item['dueDay'] < 1 || (int)$item['dueDay'] > 31)) {
			return $this->l->t('Due day must be between 1 and 31');
		}
		if (isset($item['dueMonth']) && ((int)$item['dueMonth'] < 1 || (int)$item['dueMonth'] > 12)) {
			return $this->l->t('Due month must be between 1 and 12');
		}

		if (isset($item['startDate']) && $item['startDate'] !== '') {
			$startDateValidation = $this->validationService->validateDate((string)$item['startDate'], $this->l->t('Start date'), false);
			if (!$startDateValidation['valid']) {
				return $startDateValidation['error'];
			}
		}

		if (isset($item['autoDetectPattern']) && $item['autoDetectPattern'] !== '') {
			$patternValidation = $this->validationService->validatePattern((string)$item['autoDetectPattern'], false);
			if (!$patternValidation['valid']) {
				return $patternValidation['error'];
			}
			$item['autoDetectPattern'] = $patternValidation['sanitized'];
		}

		return null;
	}

	/**
	 * Get annual overview of bills
	 * @NoAdminRequired
	 */
	public function annualOverview(?int $year = null, $includeTransfers = 'false', ?string $billStatus = 'active', ?int $accountId = null): DataResponse {
		try {
			// Default to current year if not specified
			$year = $year ?? (int)date('Y');

			// Validate year
			if ($year < 2000 || $year > 2100) {
				return new DataResponse(['error' => $this->l->t('Invalid year')], Http::STATUS_BAD_REQUEST);
			}

			// Convert string parameters to boolean
			$includeTransfersBool = $this->toBool($includeTransfers);

			// Validate bill status
			$validStatuses = ['active', 'inactive', 'all'];
			if (!in_array($billStatus, $validStatuses)) {
				$billStatus = 'active';
			}

			$overview = $this->service->getAnnualOverview($this->getEffectiveUserId(), $year, $includeTransfersBool, $billStatus, $accountId);
			return new DataResponse($overview);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to generate annual overview'));
		}
	}

	/**
	 * Export bills calendar as CSV or PDF.
	 * @NoAdminRequired
	 */
	public function exportCalendar(
		string $format = 'csv',
		?int $year = null,
		$includeTransfers = 'false',
		?string $billStatus = 'active',
		?int $accountId = null,
	): DataDownloadResponse|DataResponse {
		try {
			$year = $year ?? (int)date('Y');
			if ($year < 2000 || $year > 2100) {
				return new DataResponse(['error' => $this->l->t('Invalid year')], Http::STATUS_BAD_REQUEST);
			}

			$includeTransfersBool = $this->toBool($includeTransfers);
			$validStatuses = ['active', 'inactive', 'all'];
			if (!in_array($billStatus, $validStatuses)) {
				$billStatus = 'active';
			}

			$data = $this->service->getAnnualOverview($this->getEffectiveUserId(), $year, $includeTransfersBool, $billStatus, $accountId);

			if ($format === 'pdf') {
				$result = $this->exportCalendarToPdf($data);
			} else {
				$result = $this->exportCalendarToCsv($data);
			}

			return new DataDownloadResponse(
				$result['stream'],
				$result['filename'],
				$result['contentType']
			);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to export bills calendar'));
		}
	}

	private function exportCalendarToCsv(array $data): array {
		$csv = fopen('php://memory', 'w');
		$year = $data['year'] ?? date('Y');

		// Header
		$header = [$this->l->t('Bill'), $this->l->t('Amount'), $this->l->t('Frequency')];
		for ($m = 1; $m <= 12; $m++) {
			$header[] = MonthNames::short($this->l, $m);
		}
		$header[] = $this->l->t('Annual Total');
		CsvSafe::put($csv, $header);

		// Bill rows
		foreach ($data['bills'] ?? [] as $bill) {
			$row = [
				$bill['name'] ?? '',
				$bill['amount'] ?? 0,
				$bill['frequency'] ?? '',
			];

			$annualTotal = 0;
			for ($m = 1; $m <= 12; $m++) {
				$occurs = !empty($bill['occurrences'][$m]);
				$row[] = $occurs ? number_format((float)($bill['amount'] ?? 0), 2) : '';
				if ($occurs) {
					$annualTotal += (float)($bill['amount'] ?? 0);
				}
			}
			$row[] = number_format($annualTotal, 2);
			CsvSafe::put($csv, $row);
		}

		// Monthly totals row
		$totalsRow = [$this->l->t('Total'), '', ''];
		$grandTotal = 0;
		for ($m = 1; $m <= 12; $m++) {
			$total = $data['monthlyTotals'][$m] ?? 0;
			$totalsRow[] = number_format($total, 2);
			$grandTotal += $total;
		}
		$totalsRow[] = number_format($grandTotal, 2);
		CsvSafe::put($csv, $totalsRow);

		$balanceRow = $this->projectedBalanceRow($data);
		if ($balanceRow !== null) {
			CsvSafe::put($csv, $balanceRow);
		}

		rewind($csv);
		$content = stream_get_contents($csv);
		fclose($csv);

		return [
			'stream' => $content,
			'contentType' => 'text/csv',
			'filename' => "bills_calendar_{$year}_" . date('Y-m-d') . '.csv',
		];
	}

	/**
	 * The calendar's projected balance row for an export, in the CSV's
	 * column order: label, today's balance in the Amount column, a blank for
	 * Frequency, then the balance at the end of each month (blank for the
	 * months already gone), and a blank Annual Total. Null when no account
	 * was picked or the year is not this one, since there is then no
	 * balance to start from (#393).
	 *
	 * @return string[]|null
	 */
	private function projectedBalanceRow(array $data): ?array {
		if (empty($data['account']) || !is_array($data['projectedBalance'] ?? null)) {
			return null;
		}
		$row = [$this->l->t('Projected balance'), number_format((float)$data['account']['balance'], 2), ''];
		for ($m = 1; $m <= 12; $m++) {
			$left = $data['projectedBalance'][$m] ?? null;
			$row[] = $left === null ? '' : number_format((float)$left, 2);
		}
		$row[] = '';
		return $row;
	}

	private function exportCalendarToPdf(array $data): array {
		if (!class_exists('TCPDF')) {
			return $this->exportCalendarToCsv($data);
		}

		$year = $data['year'] ?? date('Y');
		$title = $this->l->t('Bills Calendar %s', [$year]);

		$pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
		$pdf->SetCreator('Nextcloud Budget');
		$pdf->SetTitle($title);
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(true);
		$pdf->SetMargins(10, 10, 10);
		$pdf->SetAutoPageBreak(true, 20);
		$pdf->AddPage();

		$pdf->SetFont('dejavusans', 'B', 14);
		$pdf->Cell(0, 8, $title, 0, 1, 'C');
		$pdf->Ln(3);

		// Column widths for landscape A4 (277mm usable)
		$nameW = 45;
		$amtW = 20;
		$monthW = 16;
		$totalW = 20;

		// Header
		$pdf->SetFont('dejavusans', 'B', 7);
		$pdf->Cell($nameW, 5, $this->l->t('Bill'), 1, 0, 'L');
		$pdf->Cell($amtW, 5, $this->l->t('Amount'), 1, 0, 'R');
		for ($m = 1; $m <= 12; $m++) {
			$pdf->Cell($monthW, 5, MonthNames::short($this->l, $m), 1, 0, 'C');
		}
		$pdf->Cell($totalW, 5, $this->l->t('Annual'), 1, 1, 'R');

		// Data rows
		$pdf->SetFont('dejavusans', '', 7);
		foreach ($data['bills'] ?? [] as $bill) {
			$pdf->Cell($nameW, 5, $bill['name'] ?? '', 1, 0, 'L');
			$pdf->Cell($amtW, 5, number_format((float)($bill['amount'] ?? 0), 2), 1, 0, 'R');

			$annualTotal = 0;
			for ($m = 1; $m <= 12; $m++) {
				$occurs = !empty($bill['occurrences'][$m]);
				$pdf->Cell($monthW, 5, $occurs ? number_format((float)($bill['amount'] ?? 0), 2) : '', 1, 0, 'R');
				if ($occurs) {
					$annualTotal += (float)($bill['amount'] ?? 0);
				}
			}
			$pdf->Cell($totalW, 5, number_format($annualTotal, 2), 1, 1, 'R');
		}

		// Totals row
		$pdf->SetFont('dejavusans', 'B', 7);
		$pdf->Cell($nameW, 5, $this->l->t('Total'), 1, 0, 'L');
		$pdf->Cell($amtW, 5, '', 1, 0, 'R');
		$grandTotal = 0;
		for ($m = 1; $m <= 12; $m++) {
			$total = $data['monthlyTotals'][$m] ?? 0;
			$pdf->Cell($monthW, 5, number_format($total, 2), 1, 0, 'R');
			$grandTotal += $total;
		}
		$pdf->Cell($totalW, 5, number_format($grandTotal, 2), 1, 1, 'R');

		$balanceRow = $this->projectedBalanceRow($data);
		if ($balanceRow !== null) {
			$pdf->Cell($nameW, 5, $balanceRow[0], 1, 0, 'L');
			$pdf->Cell($amtW, 5, $balanceRow[1], 1, 0, 'R');
			for ($m = 1; $m <= 12; $m++) {
				$pdf->Cell($monthW, 5, $balanceRow[2 + $m], 1, 0, 'R');
			}
			$pdf->Cell($totalW, 5, '', 1, 1, 'R');
		}

		return [
			'stream' => $pdf->Output('', 'S'),
			'contentType' => 'application/pdf',
			'filename' => "bills_calendar_{$year}_" . date('Y-m-d') . '.pdf',
		];
	}

	/**
	 * Validate custom recurrence pattern JSON.
	 *
	 * @param string $pattern JSON pattern string
	 * @return array ['valid' => bool, 'error' => string|null]
	 */
	/**
	 * Validate a split template array against the bill amount.
	 */
	/**
	 * The bill's category and every split-template category must be ones the
	 * bill's owner can see; any other id was stored as-is and its name came
	 * back through the listing joins. Empty / 0 means uncategorised.
	 *
	 * @throws \InvalidArgumentException
	 */
	/**
	 * A split bill paying from another user's account (shared with its
	 * owner) splits its payments in that user's ledger, so every split
	 * category has to be one they can see. With the bill owner's own
	 * categories every payment landed unsplit and uncategorised, silently.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function requireSplitsUsableByAccountOwner(string $billOwner, ?int $accountId, ?array $splitTemplate): void {
		if ($accountId === null || empty($splitTemplate)) {
			return;
		}
		$accountOwner = $this->granularShareService->resolveOwner($this->userId, 'account', $accountId);
		if ($accountOwner === null || $accountOwner === $billOwner) {
			return;
		}
		try {
			$this->requireUsableCategories($accountOwner, null, $splitTemplate);
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException($this->l->t('This account belongs to someone else, who cannot see one of the split categories. Split with categories shared with them, or pay from one of your own accounts.'));
		}
	}

	/**
	 * A bill's own category is booked into the ledger of the account it pays
	 * from, and for a transfer the account it pays into, as that account's
	 * owner. When one of them belongs to someone else (shared with the
	 * bill's owner), they have to be able to see the category too: with
	 * the bill owner's own category, its name reached a ledger it was never
	 * shared with.
	 *
	 * @param array<int|null> $accountIds
	 * @throws \InvalidArgumentException
	 */
	private function requireCategoryUsableByAccountOwners(string $billOwner, mixed $categoryId, array $accountIds): void {
		$categoryId = ($categoryId === null || $categoryId === '' || (int)$categoryId <= 0) ? null : (int)$categoryId;
		if ($categoryId === null) {
			return;
		}
		foreach (array_unique(array_filter($accountIds, static fn ($id) => $id !== null)) as $accountId) {
			$accountOwner = $this->granularShareService->resolveOwner($billOwner, 'account', (int)$accountId);
			if ($accountOwner === null || $accountOwner === $billOwner) {
				continue;
			}
			try {
				$this->granularShareService->requireUsableCategory($accountOwner, $categoryId);
			} catch (\InvalidArgumentException $e) {
				throw new \InvalidArgumentException($this->l->t('This account belongs to someone else, who cannot see this category. Choose a category shared with them, or no category.'));
			}
		}
	}

	/**
	 * requireCategoryUsableByAccountOwners() for an edit, once the category
	 * or an account actually changes: a shared bill's form sends back what
	 * is stored, and an unchanged bill has to keep saving (#370).
	 *
	 * @param array<string, mixed> $updates
	 */
	private function requireChangedCategoryUsableByAccountOwners(string $billOwner, Bill $stored, array $updates): void {
		$categoryId = array_key_exists('categoryId', $updates) ? $updates['categoryId'] : $stored->getCategoryId();
		$accountId = array_key_exists('accountId', $updates) ? $updates['accountId'] : $stored->getAccountId();
		$isTransfer = array_key_exists('isTransfer', $updates) ? (bool)$updates['isTransfer'] : (bool)$stored->getIsTransfer();
		$destinationId = array_key_exists('destinationAccountId', $updates) ? $updates['destinationAccountId'] : $stored->getDestinationAccountId();

		$changed = $categoryId !== $stored->getCategoryId()
			|| $accountId !== $stored->getAccountId()
			|| $isTransfer !== (bool)$stored->getIsTransfer()
			|| ($isTransfer && $destinationId !== $stored->getDestinationAccountId());
		if ($changed) {
			$this->requireCategoryUsableByAccountOwners($billOwner, $categoryId, [$accountId, $isTransfer ? $destinationId : null]);
		}
	}

	/**
	 * A bill's tags go onto every payment it books, in the ledger of the
	 * account it pays from and, for a transfer, the one it pays into. Tag
	 * ids were stored unchecked and read back off those payments with their
	 * names, so anyone could read every user's tags by walking the ids.
	 *
	 * A tag new to the bill ($added) must be one the person setting it can
	 * see and the bill's owner can use. Like the bill's category, every tag
	 * it carries ($tagIds) must also be usable by the owner of an account it
	 * books into when that is someone else.
	 *
	 * @param int[] $added
	 * @param int[] $tagIds
	 * @param array<int|null> $accountIds
	 * @throws \InvalidArgumentException
	 */
	private function requireUsableBillTags(string $billOwner, array $added, array $tagIds, array $accountIds): void {
		$this->granularShareService->requireUsableTags($this->userId, $added);
		if ($billOwner !== $this->userId) {
			$this->granularShareService->requireUsableTags($billOwner, $added);
		}
		if ($tagIds === []) {
			return;
		}
		foreach (array_unique(array_filter($accountIds, static fn ($id) => $id !== null)) as $accountId) {
			$accountOwner = $this->granularShareService->resolveOwner($billOwner, 'account', (int)$accountId);
			if ($accountOwner === null || $accountOwner === $billOwner) {
				continue;
			}
			try {
				$this->granularShareService->requireUsableTags($accountOwner, $tagIds);
			} catch (\InvalidArgumentException $e) {
				throw new \InvalidArgumentException($this->l->t('This account belongs to someone else, who cannot see one of the tags. Choose tags from a category shared with them, or no tags.'));
			}
		}
	}

	/**
	 * The tags of a shared bill as someone other than its owner saves it.
	 *
	 * The bill form only lists the tags its user can see and saves exactly
	 * the ticked ones, so a recipient's save silently stripped the owner's
	 * own tags off the bill. The tags the editor can't see stay as they are;
	 * the ones they can see are replaced by what they sent. The owner's save
	 * still replaces the whole list.
	 *
	 * @param array<string, mixed> $updates
	 * @return array<string, mixed>
	 */
	private function keepTagsTheEditorCannotSee(string $billOwner, Bill $stored, array $updates): array {
		if ($billOwner === $this->userId || !array_key_exists('tagIds', $updates)) {
			return $updates;
		}
		$storedTags = $stored->getTagIdsArray();
		$hidden = array_diff($storedTags, $this->granularShareService->getUsableTagIds($this->userId, $storedTags));
		if ($hidden === []) {
			return $updates;
		}
		$decoded = $updates['tagIds'] === null ? [] : json_decode((string)$updates['tagIds'], true);
		$sent = array_map('intval', is_array($decoded) ? $decoded : []);
		$updates['tagIds'] = json_encode(array_values(array_unique(array_merge($sent, $hidden))));
		return $updates;
	}

	/**
	 * requireUsableBillTags() for an edit: the tags a form sends back as
	 * stored keep saving, and the account owners are asked again only when
	 * the tags or an account change.
	 *
	 * @param array<string, mixed> $updates
	 */
	private function requireChangedBillTagsUsable(string $billOwner, Bill $stored, array $updates): void {
		$storedTags = $stored->getTagIdsArray();
		$tagIds = $storedTags;
		if (array_key_exists('tagIds', $updates)) {
			$decoded = $updates['tagIds'] === null ? [] : json_decode((string)$updates['tagIds'], true);
			$tagIds = array_map('intval', is_array($decoded) ? $decoded : []);
		}
		$added = array_values(array_diff($tagIds, $storedTags));

		$accountId = array_key_exists('accountId', $updates) ? $updates['accountId'] : $stored->getAccountId();
		$isTransfer = array_key_exists('isTransfer', $updates) ? (bool)$updates['isTransfer'] : (bool)$stored->getIsTransfer();
		$destinationId = array_key_exists('destinationAccountId', $updates) ? $updates['destinationAccountId'] : $stored->getDestinationAccountId();
		$accountsChanged = $accountId !== $stored->getAccountId()
			|| $isTransfer !== (bool)$stored->getIsTransfer()
			|| ($isTransfer && $destinationId !== $stored->getDestinationAccountId());

		$this->requireUsableBillTags(
			$billOwner,
			$added,
			$added !== [] || $accountsChanged ? $tagIds : [],
			[$accountId, $isTransfer ? $destinationId : null]
		);
	}

	/**
	 * Someone the bill is shared with may only give it, or its split, a
	 * category they can see: with the owner's check alone they could put one
	 * of the owner's categories that was never shared with them on it, by id,
	 * and read its name off the payments it books. Categories the bill
	 * already uses may stay, so an unchanged bill keeps saving.
	 *
	 * @param array<array-key, mixed> $splitTemplate
	 * @throws \InvalidArgumentException
	 */
	private function requireCategoriesVisibleToEditor(string $billOwner, Bill $stored, mixed $categoryId, array $splitTemplate): void {
		$normalise = static fn (mixed $raw): ?int
			=> ($raw === null || $raw === '' || !is_numeric($raw) || (int)$raw <= 0) ? null : (int)$raw;
		$kept = [$stored->getCategoryId()];
		foreach ($stored->getSplitTemplateArray() as $split) {
			$kept[] = is_array($split) ? $normalise($split['categoryId'] ?? null) : null;
		}
		$this->granularShareService->requireCategoryVisibleToWriter($billOwner, $this->userId, $normalise($categoryId), $kept);
		foreach ($splitTemplate as $split) {
			if (is_array($split)) {
				$this->granularShareService->requireCategoryVisibleToWriter(
					$billOwner, $this->userId, $normalise($split['categoryId'] ?? null), $kept
				);
			}
		}
	}

	private function requireUsableCategories(string $ownerId, mixed $categoryId, ?array $splitTemplate): void {
		$normalise = static fn (mixed $raw): ?int
			=> ($raw === null || $raw === '' || (int)$raw <= 0) ? null : (int)$raw;

		$this->granularShareService->requireUsableCategory($ownerId, $normalise($categoryId));
		foreach ($splitTemplate ?? [] as $split) {
			if (is_array($split)) {
				$this->granularShareService->requireUsableCategory($ownerId, $normalise($split['categoryId'] ?? null));
			}
		}
	}

	private function validateSplitTemplate(array $splits, float $billAmount): array {
		if (count($splits) < 2) {
			return ['valid' => false, 'error' => $this->l->t('Split template must have at least 2 splits')];
		}

		// Added through MoneyCalculator, never float += (#274); scale 8 so a
		// crypto amount is not truncated before the comparison
		$total = '0';
		foreach ($splits as $split) {
			if (!isset($split['amount']) || !is_numeric($split['amount']) || (float)$split['amount'] <= 0) {
				return ['valid' => false, 'error' => $this->l->t('Each split must have a positive amount')];
			}
			$total = MoneyCalculator::add($total, (float)$split['amount'], 8);
		}

		if (!MoneyCalculator::equals($total, $billAmount, '0.01')) {
			return ['valid' => false, 'error' => $this->l->t('Split amounts must equal the bill amount')];
		}

		return ['valid' => true, 'error' => null];
	}

	private function validateCustomPattern(string $pattern): array {
		$decoded = json_decode($pattern, true);

		if (json_last_error() !== JSON_ERROR_NONE) {
			return [
				'valid' => false,
				'error' => $this->l->t('Invalid JSON in custom recurrence pattern'),
			];
		}

		if (!is_array($decoded)) {
			return [
				'valid' => false,
				'error' => $this->l->t('Custom recurrence pattern must be a JSON object'),
			];
		}

		// Validate months pattern: {"months": [1, 6, 7]}
		if (isset($decoded['months'])) {
			if (!is_array($decoded['months'])) {
				return [
					'valid' => false,
					'error' => $this->l->t('Months must be an array'),
				];
			}

			if (empty($decoded['months'])) {
				return [
					'valid' => false,
					'error' => $this->l->t('At least one month must be specified'),
				];
			}

			foreach ($decoded['months'] as $month) {
				if (!is_int($month) || $month < 1 || $month > 12) {
					return [
						'valid' => false,
						'error' => $this->l->t('Each month must be a number between 1 and 12'),
					];
				}
			}

			return ['valid' => true, 'error' => null];
		}

		// Validate dates pattern: {"dates": [{"month": 1, "day": 15}, ...]}
		if (isset($decoded['dates'])) {
			if (!is_array($decoded['dates'])) {
				return [
					'valid' => false,
					'error' => $this->l->t('Dates must be an array'),
				];
			}

			if (empty($decoded['dates'])) {
				return [
					'valid' => false,
					'error' => $this->l->t('At least one date must be specified'),
				];
			}

			foreach ($decoded['dates'] as $date) {
				if (!is_array($date) || !isset($date['month']) || !isset($date['day'])) {
					return [
						'valid' => false,
						'error' => $this->l->t('Each date must have "month" and "day" fields'),
					];
				}

				if (!is_int($date['month']) || $date['month'] < 1 || $date['month'] > 12) {
					return [
						'valid' => false,
						'error' => $this->l->t('Month must be a number between 1 and 12'),
					];
				}

				if (!is_int($date['day']) || $date['day'] < 1 || $date['day'] > 31) {
					return [
						'valid' => false,
						'error' => $this->l->t('Day must be a number between 1 and 31'),
					];
				}
			}

			return ['valid' => true, 'error' => null];
		}

		return [
			'valid' => false,
			'error' => $this->l->t('Custom pattern must contain either "months" or "dates" field'),
		];
	}
}
