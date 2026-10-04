<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class RecurringIncomeController extends Controller {
	use ApiErrorHandlerTrait;
	use InputValidationTrait;
	use SharedAccessTrait;

	private RecurringIncomeService $service;
	private ValidationService $validationService;
	private IL10N $l;
	private string $userId;

	public function __construct(
		IRequest $request,
		RecurringIncomeService $service,
		ValidationService $validationService,
		GranularShareService $granularShareService,
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
	 * Get all recurring income entries
	 * @NoAdminRequired
	 */
	public function index(?bool $activeOnly = false): DataResponse {
		try {
			if ($activeOnly) {
				$incomes = $this->service->findActive($this->userId);
			} else {
				$incomes = $this->service->findAll($this->userId);
			}
			// Each row in its account's currency; it had none, so a euro
			// salary showed with the default currency's symbol
			$this->service->enrichWithCurrency($incomes, $this->userId);

			// Merge shared recurring income
			$shared = $this->granularShareService->getSharedRecurringIncome($this->userId);
			if (!empty($shared)) {
				$shared = $this->service->enrichSharedWithCurrency($shared);
				$incomes = array_merge(
					array_map(fn ($i) => $i->jsonSerialize(), $incomes),
					$shared
				);
				return new DataResponse($incomes);
			}

			return new DataResponse($incomes);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve recurring income'));
		}
	}

	/**
	 * Get a single recurring income entry
	 * @NoAdminRequired
	 */
	public function show(int $id): DataResponse {
		try {
			$owner = $this->granularShareService->resolveOwner($this->userId, 'recurring_income', $id);
			if ($owner === null) {
				return new DataResponse(
					['error' => $this->l->t('%1$s not found', [$this->l->t('Recurring income')])],
					Http::STATUS_NOT_FOUND
				);
			}

			$income = $this->service->find($id, $owner);
			$this->service->enrichWithCurrency([$income], $owner);
			if ($owner === $this->userId) {
				return new DataResponse($income);
			}
			return new DataResponse(array_merge($income->jsonSerialize(), [
				'_shared' => true,
				'_canWrite' => $this->granularShareService->canWrite($this->userId, 'recurring_income', $id),
				'_canManage' => $this->granularShareService->canManage($this->userId, 'recurring_income', $id),
			]));
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, $this->l->t('Recurring income'), ['incomeId' => $id]);
		}
	}

	/**
	 * The user id every lookup for recurring income $id must be scoped to.
	 * Same trap as bills: sharing swaps visibility, not identity, so an entry
	 * shared to this user has to be read and written under its OWNER (#368).
	 *
	 * @throws DoesNotExistException when the entry is not visible to the user
	 */
	private function incomeOwner(int $id): string {
		$owner = $this->granularShareService->resolveOwner($this->userId, 'recurring_income', $id);
		if ($owner === null) {
			throw new DoesNotExistException('Recurring income ' . $id . ' is not accessible to ' . $this->userId);
		}
		return $owner;
	}

	/**
	 * Income is booked into the ledger of the account it arrives in, as that
	 * account's owner. When the account belongs to someone else (shared with
	 * the income's owner), they have to be able to see its category too, or
	 * the owner's category name reached a ledger it was never shared with.
	 *
	 * @throws \InvalidArgumentException
	 */
	private function requireCategoryUsableByAccountOwner(string $incomeOwner, ?int $categoryId, ?int $accountId): void {
		if ($categoryId === null || $categoryId <= 0 || $accountId === null) {
			return;
		}
		$accountOwner = $this->granularShareService->resolveOwner($incomeOwner, 'account', $accountId);
		if ($accountOwner === null || $accountOwner === $incomeOwner) {
			return;
		}
		try {
			$this->granularShareService->requireUsableCategory($accountOwner, $categoryId);
		} catch (\InvalidArgumentException $e) {
			throw new \InvalidArgumentException($this->l->t('This account belongs to someone else, who cannot see this category. Choose a category shared with them, or no category.'));
		}
	}

	/**
	 * A new account for an income has to be one the acting user may post to,
	 * as for a bill, and one the income's owner may post to, since it is
	 * received as them. Nothing was checked: a share recipient could point
	 * the owner's income at the owner's private account and book money into
	 * it, or move it onto her own account, which the owner then couldn't use.
	 *
	 * @throws \OCA\Budget\Exception\ReadOnlyShareException
	 * @throws \InvalidArgumentException
	 */
	private function requireUsableAccount(string $incomeOwner, int $accountId): void {
		$this->requireWriteAccess('account', $accountId);
		if ($incomeOwner !== $this->userId
			&& !$this->granularShareService->canWrite($incomeOwner, 'account', $accountId)) {
			throw new \InvalidArgumentException($this->l->t('The owner of this income can\'t use that account. Choose another one.'));
		}
	}

	/** A client id, with null, empty and 0 meaning none */
	private static function idOrNull(mixed $raw): ?int {
		return ($raw === null || $raw === '' || (int)$raw <= 0) ? null : (int)$raw;
	}

	/**
	 * Create a new recurring income entry
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function create(
		string $name,
		float $amount,
		string $frequency = 'monthly',
		?int $expectedDay = null,
		?int $expectedMonth = null,
		?int $categoryId = null,
		?int $accountId = null,
		?string $source = null,
		?string $autoDetectPattern = null,
		?string $notes = null,
		bool $autoCreateEnabled = false,
		?string $description = null,
		bool $excludedFromForecast = false,
		?string $startDate = null,
	): DataResponse {
		try {
			// Validate name (required)
			$nameValidation = $this->validationService->validateName($name, true);
			if (!$nameValidation['valid']) {
				return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
			}
			$name = $nameValidation['sanitized'];

			// Validate description if provided
			if ($description !== null && $description !== '') {
				$descriptionValidation = $this->validationService->validateDescription($description, false);
				if (!$descriptionValidation['valid']) {
					return new DataResponse(['error' => $descriptionValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$description = $descriptionValidation['sanitized'];
			} else {
				$description = null;
			}

			// Validate frequency
			$frequencyValidation = $this->validationService->validateFrequency($frequency);
			if (!$frequencyValidation['valid']) {
				return new DataResponse(['error' => $frequencyValidation['error']], Http::STATUS_BAD_REQUEST);
			}
			$frequency = $frequencyValidation['formatted'];

			// Validate expectedDay range
			if ($expectedDay !== null && ($expectedDay < 1 || $expectedDay > 31)) {
				return new DataResponse(['error' => $this->l->t('Expected day must be between 1 and 31')], Http::STATUS_BAD_REQUEST);
			}

			// Validate expectedMonth range
			if ($expectedMonth !== null && ($expectedMonth < 1 || $expectedMonth > 12)) {
				return new DataResponse(['error' => $this->l->t('Expected month must be between 1 and 12')], Http::STATUS_BAD_REQUEST);
			}

			// Validate source if provided
			if ($source !== null) {
				$sourceValidation = $this->validationService->validateName($source, false);
				if (!$sourceValidation['valid']) {
					return new DataResponse(['error' => $sourceValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$source = $sourceValidation['sanitized'];
			}

			// Validate autoDetectPattern if provided
			if ($autoDetectPattern !== null) {
				$patternValidation = $this->validationService->validatePattern($autoDetectPattern, false);
				if (!$patternValidation['valid']) {
					return new DataResponse(['error' => $patternValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$autoDetectPattern = $patternValidation['sanitized'];
			}

			// Validate startDate if provided (#363)
			if ($startDate !== null && $startDate !== '') {
				$startDateValidation = $this->validationService->validateDate($startDate, $this->l->t('Start date'), false);
				if (!$startDateValidation['valid']) {
					return new DataResponse(['error' => $startDateValidation['error']], Http::STATUS_BAD_REQUEST);
				}
			} else {
				$startDate = null;
			}

			if (self::idOrNull($accountId) !== null) {
				$this->requireUsableAccount($this->getEffectiveUserId(), (int)$accountId);
			}

			// A category the owner cannot see is refused, not stored: its
			// name would otherwise come back through the listing joins
			$this->granularShareService->requireUsableCategory(
				$this->getEffectiveUserId(),
				$categoryId !== null && $categoryId > 0 ? $categoryId : null
			);
			$this->requireCategoryUsableByAccountOwner($this->getEffectiveUserId(), $categoryId, $accountId);

			$income = $this->service->create(
				$this->getEffectiveUserId(),
				$name,
				$amount,
				$frequency,
				$expectedDay,
				$expectedMonth,
				$categoryId,
				$accountId,
				$source,
				$autoDetectPattern,
				$notes,
				$autoCreateEnabled,
				$description,
				$excludedFromForecast,
				$startDate
			);

			return new DataResponse($income, Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return $this->handleValidationError($e);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to create recurring income'));
		}
	}

	/**
	 * Update a recurring income entry
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function update(int $id): DataResponse {
		try {
			$this->requireWriteAccess('recurring_income', $id);

			// Use request params (php://input is consumed by the framework)
			$data = $this->request->getParams();

			// Allowlist updatable fields — the service applies any key with a
			// matching entity setter, so passing the raw body through would
			// let a crafted payload set userId/createdAt/id (mass assignment)
			$allowed = [
				'name', 'description', 'amount', 'frequency', 'expectedDay',
				'expectedMonth', 'categoryId', 'accountId', 'source',
				'autoDetectPattern', 'notes', 'autoCreateEnabled', 'isActive',
				'lastReceivedDate', 'excludedFromForecast', 'startDate',
			];
			$data = array_intersect_key($data, array_flip($allowed));

			if (empty($data)) {
				return new DataResponse(['error' => $this->l->t('No data provided')], Http::STATUS_BAD_REQUEST);
			}

			// Validate name if provided
			if (isset($data['name'])) {
				$nameValidation = $this->validationService->validateName($data['name'], true);
				if (!$nameValidation['valid']) {
					return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$data['name'] = $nameValidation['sanitized'];
			}

			// Validate description if provided (non-null)
			if (isset($data['description']) && $data['description'] !== null && $data['description'] !== '') {
				$descriptionValidation = $this->validationService->validateDescription($data['description'], false);
				if (!$descriptionValidation['valid']) {
					return new DataResponse(['error' => $descriptionValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$data['description'] = $descriptionValidation['sanitized'];
			}

			// Validate frequency if provided
			if (isset($data['frequency'])) {
				$frequencyValidation = $this->validationService->validateFrequency($data['frequency']);
				if (!$frequencyValidation['valid']) {
					return new DataResponse(['error' => $frequencyValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$data['frequency'] = $frequencyValidation['formatted'];
			}

			// Validate expectedDay range if provided
			if (isset($data['expectedDay']) && $data['expectedDay'] !== null) {
				if ($data['expectedDay'] < 1 || $data['expectedDay'] > 31) {
					return new DataResponse(['error' => $this->l->t('Expected day must be between 1 and 31')], Http::STATUS_BAD_REQUEST);
				}
			}

			// Validate expectedMonth range if provided
			if (isset($data['expectedMonth']) && $data['expectedMonth'] !== null) {
				if ($data['expectedMonth'] < 1 || $data['expectedMonth'] > 12) {
					return new DataResponse(['error' => $this->l->t('Expected month must be between 1 and 12')], Http::STATUS_BAD_REQUEST);
				}
			}

			// Validate startDate if provided; empty string clears it (#363)
			if (array_key_exists('startDate', $data)) {
				if ($data['startDate'] !== null && $data['startDate'] !== '') {
					$startDateValidation = $this->validationService->validateDate($data['startDate'], $this->l->t('Start date'), false);
					if (!$startDateValidation['valid']) {
						return new DataResponse(['error' => $startDateValidation['error']], Http::STATUS_BAD_REQUEST);
					}
				} else {
					$data['startDate'] = null;
				}
			}

			// Coerce the flags to real booleans (#270): the setters took the
			// string "false" as true
			foreach (['excludedFromForecast', 'autoCreateEnabled', 'isActive'] as $flag) {
				if (array_key_exists($flag, $data)) {
					$data[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
				}
			}

			$ownerId = $this->incomeOwner($id);
			if (array_key_exists('categoryId', $data)) {
				$raw = $data['categoryId'];
				$this->granularShareService->requireUsableCategory(
					$ownerId,
					($raw === null || $raw === '' || (int)$raw <= 0) ? null : (int)$raw
				);
			}

			// Only once the category or the account changes: a shared entry's
			// form sends back what is stored, and that has to keep saving
			if (array_key_exists('categoryId', $data) || array_key_exists('accountId', $data)) {
				$stored = $this->service->find($id, $ownerId);
				$categoryId = array_key_exists('categoryId', $data) ? self::idOrNull($data['categoryId']) : $stored->getCategoryId();
				// Someone it's shared with may only choose a category they can
				// see (an unshared one of the owner's came back by name); the
				// one the income already has may stay
				$this->granularShareService->requireCategoryVisibleToWriter($ownerId, $this->userId, $categoryId, [$stored->getCategoryId()]);
				$accountId = array_key_exists('accountId', $data) ? self::idOrNull($data['accountId']) : $stored->getAccountId();
				if ($accountId !== null && $accountId !== $stored->getAccountId()) {
					$this->requireUsableAccount($ownerId, $accountId);
				}
				if ($categoryId !== $stored->getCategoryId() || $accountId !== $stored->getAccountId()) {
					$this->requireCategoryUsableByAccountOwner($ownerId, $categoryId, $accountId);
				}
			}

			$income = $this->service->update($id, $ownerId, $data);
			return new DataResponse($income);
		} catch (\InvalidArgumentException $e) {
			return $this->handleValidationError($e);
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, $this->l->t('Recurring income'), ['incomeId' => $id]);
		}
	}

	/**
	 * Delete a recurring income entry
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function destroy(int $id): DataResponse {
		try {
			$owner = $this->incomeOwner($id);
			// Editing shared income needs write; deleting it needs Full control
			if ($owner !== $this->userId && !$this->granularShareService->canManage($this->userId, 'recurring_income', $id)) {
				return new DataResponse(['error' => $this->l->t('Deleting a shared item needs Full control from its owner')], Http::STATUS_FORBIDDEN);
			}
			$this->service->delete($id, $owner);
			return new DataResponse(['message' => $this->l->t('Recurring income deleted')]);
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, $this->l->t('Recurring income'), ['incomeId' => $id]);
		}
	}

	/**
	 * Get upcoming income entries
	 * @NoAdminRequired
	 */
	public function upcoming(?int $days = 30): DataResponse {
		try {
			$incomes = $this->service->findUpcoming($this->getEffectiveUserId(), $days);
			return new DataResponse($incomes);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve upcoming income'));
		}
	}

	/**
	 * Get income expected this month
	 * @NoAdminRequired
	 */
	public function expectedThisMonth(): DataResponse {
		try {
			$incomes = $this->service->findExpectedThisMonth($this->getEffectiveUserId());
			return new DataResponse($incomes);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve expected income'));
		}
	}

	/**
	 * Get monthly summary of recurring income, of one account's when asked
	 * @NoAdminRequired
	 */
	public function summary(?int $accountId = null): DataResponse {
		try {
			$summary = $this->service->getMonthlySummary($this->getEffectiveUserId(), $accountId);
			return new DataResponse($summary);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve income summary'));
		}
	}

	/**
	 * Mark income as received and advance to next expected date
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function markReceived(int $id, ?string $receivedDate = null): DataResponse {
		try {
			$this->requireWriteAccess('recurring_income', $id);
			$params = $this->request->getParams();
			// filter_var, not a cast: (bool)"false" is true
			$createTransaction = filter_var($params['createTransaction'] ?? false, FILTER_VALIDATE_BOOLEAN);
			// The occurrence the page showed, so a second click or a stale tab
			// is refused instead of booking the money twice
			$expectedDate = is_string($params['expectedDate'] ?? null) ? $params['expectedDate'] : null;

			$income = $this->service->markReceived($id, $this->incomeOwner($id), $receivedDate, $createTransaction, $expectedDate, $this->userId);
			return new DataResponse($income);
		} catch (\InvalidArgumentException $e) {
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['incomeId' => $id]);
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, $this->l->t('Recurring income'), ['incomeId' => $id]);
		}
	}

	/**
	 * Revert the last Mark Received: dates, active state and the credit it booked
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function markUnreceived(int $id): DataResponse {
		try {
			$this->requireWriteAccess('recurring_income', $id);
			$income = $this->service->markUnreceived($id, $this->incomeOwner($id), $this->userId);
			return new DataResponse($income);
		} catch (\InvalidArgumentException $e) {
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['incomeId' => $id]);
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, $this->l->t('Recurring income'), ['incomeId' => $id]);
		}
	}

	/**
	 * Skip the next expected payment, advancing to the one after (#396)
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function skipPayment(int $id): DataResponse {
		try {
			$this->requireWriteAccess('recurring_income', $id);
			$result = $this->service->skipPayment($id, $this->incomeOwner($id));
			return new DataResponse($result);
		} catch (\InvalidArgumentException $e) {
			return $this->handleError($e, $e->getMessage(), Http::STATUS_BAD_REQUEST, ['incomeId' => $id]);
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, $this->l->t('Recurring income'), ['incomeId' => $id]);
		}
	}

	/**
	 * Undo a skipped income payment
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function undoSkip(int $id): DataResponse {
		try {
			$this->requireWriteAccess('recurring_income', $id);
			$previous = $this->request->getParams()['previousNextExpectedDate'] ?? null;
			$previous = is_string($previous) ? $previous : null;
			$validation = $this->validationService->validateDate($previous, $this->l->t('Previous expected date'), true);
			if (!$validation['valid']) {
				return new DataResponse(['error' => $validation['error']], Http::STATUS_BAD_REQUEST);
			}

			$income = $this->service->undoSkip($id, $this->incomeOwner($id), (string)$previous);
			return new DataResponse($income);
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, $this->l->t('Recurring income'), ['incomeId' => $id]);
		}
	}

	/**
	 * Auto-detect recurring income from transaction history
	 * @NoAdminRequired
	 */
	public function detect(int $months = 24, ?bool $debug = false): DataResponse {
		try {
			$detected = $this->service->detectRecurringIncome($this->getEffectiveUserId(), $months, $debug);
			return new DataResponse($detected);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to detect recurring income'));
		}
	}

	/**
	 * Create recurring income entries from detected patterns
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 10, period: 60)]
	public function createFromDetected(): DataResponse {
		try {
			$data = $this->request->getParams();
			unset($data['_route']);
			if (!is_array($data) || !isset($data['incomes'])) {
				return new DataResponse(['error' => $this->l->t('Invalid request data')], Http::STATUS_BAD_REQUEST);
			}

			$items = [];
			foreach ((array)$data['incomes'] as $item) {
				if (!is_array($item)) {
					return new DataResponse(['error' => $this->l->t('Invalid request data')], Http::STATUS_BAD_REQUEST);
				}
				$detectedAccount = self::idOrNull($item['accountId'] ?? null);
				if ($detectedAccount !== null) {
					$this->requireUsableAccount($this->getEffectiveUserId(), $detectedAccount);
				}
				$raw = $item['categoryId'] ?? null;
				$this->granularShareService->requireUsableCategory(
					$this->getEffectiveUserId(),
					($raw === null || $raw === '' || (int)$raw <= 0) ? null : (int)$raw
				);
				$this->requireCategoryUsableByAccountOwner(
					$this->getEffectiveUserId(),
					self::idOrNull($raw),
					self::idOrNull($item['accountId'] ?? null)
				);

				// Checked like an income created by hand, every item before any
				// is created: a candidate whose description cleaned away to
				// nothing was saved with no name
				$error = $this->validateDetectedItem($item);
				if ($error !== null) {
					return new DataResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
				}
				$items[] = $item;
			}

			$created = $this->service->createFromDetected($this->getEffectiveUserId(), $items);
			return new DataResponse([
				'created' => count($created),
				'incomes' => $created,
			], Http::STATUS_CREATED);
		} catch (\InvalidArgumentException $e) {
			return $this->handleValidationError($e);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to create recurring income from detected patterns'));
		}
	}

	/**
	 * Validate one detected income the way create() validates a form,
	 * writing back the cleaned values. Its name is the first of name,
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

		if (isset($item['expectedDay']) && ((int)$item['expectedDay'] < 1 || (int)$item['expectedDay'] > 31)) {
			return $this->l->t('Expected day must be between 1 and 31');
		}
		if (isset($item['expectedMonth']) && ((int)$item['expectedMonth'] < 1 || (int)$item['expectedMonth'] > 12)) {
			return $this->l->t('Expected month must be between 1 and 12');
		}

		if (isset($item['startDate']) && $item['startDate'] !== '') {
			$startDateValidation = $this->validationService->validateDate((string)$item['startDate'], $this->l->t('Start date'), false);
			if (!$startDateValidation['valid']) {
				return $startDateValidation['error'];
			}
		}

		if (isset($item['source']) && $item['source'] !== '') {
			$sourceValidation = $this->validationService->validateName((string)$item['source'], false);
			if (!$sourceValidation['valid']) {
				return $sourceValidation['error'];
			}
			$item['source'] = $sourceValidation['sanitized'];
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
}
