<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\PensionProjector;
use OCA\Budget\Service\PensionRecurringService;
use OCA\Budget\Service\PensionService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\Accounts\IAccountManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class PensionController extends Controller {
	use ApiErrorHandlerTrait;
	use InputValidationTrait;
	use SharedAccessTrait;

	private PensionService $service;
	private PensionProjector $projector;
	private ValidationService $validationService;
	private IAccountManager $accountManager;
	private IUserManager $userManager;
	private IL10N $l;
	private ?string $userId;

	public function __construct(
		IRequest $request,
		PensionService $service,
		PensionProjector $projector,
		ValidationService $validationService,
		GranularShareService $granularShareService,
		IAccountManager $accountManager,
		IUserManager $userManager,
		IL10N $l,
		?string $userId,
		LoggerInterface $logger,
		private PensionRecurringService $recurringService,
	) {
		parent::__construct(Application::APP_ID, $request);
		$this->service = $service;
		$this->projector = $projector;
		$this->validationService = $validationService;
		$this->accountManager = $accountManager;
		$this->userManager = $userManager;
		$this->l = $l;
		$this->userId = $userId;
		$this->setLogger($logger);
		$this->setInputValidator($validationService);
		$this->setGranularShareService($granularShareService);
	}

	/**
	 * Get the current user ID or throw an error if not authenticated.
	 */
	private function getUserId(): string {
		if ($this->getEffectiveUserId() === null) {
			throw new \RuntimeException('User not authenticated');
		}
		return $this->getEffectiveUserId();
	}

	// =====================
	// Pension Account CRUD
	// =====================

	/**
	 * @NoAdminRequired
	 */
	public function index(): DataResponse {
		try {
			$pensions = $this->service->findAll($this->getEffectiveUserId());
			return new DataResponse($pensions);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve pensions'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function show(int $id): DataResponse {
		try {
			$pension = $this->service->find($id, $this->getEffectiveUserId());
			return new DataResponse($pension);
		} catch (\Exception $e) {
			return $this->handleNotFoundError($e, $this->l->t('Pension'), ['pensionId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function create(): DataResponse {
		try {
			// Get JSON data from request body
			$data = $this->request->getParams();

			if (empty($data)) {
				return new DataResponse(['error' => $this->l->t('Invalid JSON data')], Http::STATUS_BAD_REQUEST);
			}

			$name = $data['name'] ?? null;
			$type = $data['type'] ?? null;
			$provider = $data['provider'] ?? null;
			$currency = $data['currency'] ?? null;
			$currentBalance = isset($data['currentBalance']) ? (float)$data['currentBalance'] : null;
			$monthlyContribution = isset($data['monthlyContribution']) ? (float)$data['monthlyContribution'] : null;
			$expectedReturnRate = isset($data['expectedReturnRate']) ? (float)$data['expectedReturnRate'] : null;
			$retirementAge = isset($data['retirementAge']) ? (int)$data['retirementAge'] : null;
			$annualIncome = isset($data['annualIncome']) ? (float)$data['annualIncome'] : null;
			$transferValue = isset($data['transferValue']) ? (float)$data['transferValue'] : null;
			$projectionTarget = isset($data['projectionTarget']) && $data['projectionTarget'] !== '' ? (float)$data['projectionTarget'] : null;

			// Validate required fields
			if (!$name || !$type) {
				return new DataResponse(['error' => $this->l->t('Name and type are required')], Http::STATUS_BAD_REQUEST);
			}

			// Validate name
			$nameValidation = $this->validationService->validateName($name, true);
			if (!$nameValidation['valid']) {
				return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
			}
			$name = $nameValidation['sanitized'];

			// Validate pension type
			if (!in_array($type, PensionAccount::VALID_TYPES, true)) {
				return new DataResponse([
					'error' => $this->l->t('Invalid pension type. Must be one of: %1$s', [implode(', ', PensionAccount::VALID_TYPES)])
				], Http::STATUS_BAD_REQUEST);
			}

			// Validate provider if provided
			if ($provider !== null && $provider !== '') {
				$providerValidation = $this->validationService->validateName($provider, false);
				if (!$providerValidation['valid']) {
					return new DataResponse(['error' => $providerValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$provider = $providerValidation['sanitized'];
			}

			// Validate currency if provided
			if ($currency !== null && strlen($currency) !== 3) {
				return new DataResponse(['error' => $this->l->t('Currency must be a 3-letter code')], Http::STATUS_BAD_REQUEST);
			}

			// Validate numeric fields
			if ($currentBalance !== null && $currentBalance < 0) {
				return new DataResponse(['error' => $this->l->t('Current balance cannot be negative')], Http::STATUS_BAD_REQUEST);
			}
			if ($monthlyContribution !== null && $monthlyContribution < 0) {
				return new DataResponse(['error' => $this->l->t('Monthly contribution cannot be negative')], Http::STATUS_BAD_REQUEST);
			}
			if ($expectedReturnRate !== null && ($expectedReturnRate < 0 || $expectedReturnRate > 1)) {
				return new DataResponse(['error' => $this->l->t('Expected return rate must be between 0%% and 100%%')], Http::STATUS_BAD_REQUEST);
			}
			if ($retirementAge !== null && ($retirementAge < 18 || $retirementAge > 100)) {
				return new DataResponse(['error' => $this->l->t('Retirement age must be between 18 and 100')], Http::STATUS_BAD_REQUEST);
			}
			if ($annualIncome !== null && $annualIncome < 0) {
				return new DataResponse(['error' => $this->l->t('Annual income cannot be negative')], Http::STATUS_BAD_REQUEST);
			}
			if ($transferValue !== null && $transferValue < 0) {
				return new DataResponse(['error' => $this->l->t('Transfer value cannot be negative')], Http::STATUS_BAD_REQUEST);
			}
			if ($projectionTarget !== null && $projectionTarget < 0) {
				return new DataResponse(['error' => $this->l->t('Projection target cannot be negative')], Http::STATUS_BAD_REQUEST);
			}

			$pension = $this->service->create(
				$this->getEffectiveUserId(),
				$name,
				$type,
				$provider,
				$currency,
				$currentBalance,
				$monthlyContribution,
				$expectedReturnRate,
				$retirementAge,
				$annualIncome,
				$transferValue,
				$projectionTarget
			);
			return new DataResponse($pension, Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to create pension'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function update(int $id): DataResponse {
		try {
			// Get JSON data from request body
			$data = $this->request->getParams();

			if (empty($data)) {
				return new DataResponse(['error' => $this->l->t('Invalid JSON data')], Http::STATUS_BAD_REQUEST);
			}

			$name = $data['name'] ?? null;
			$type = $data['type'] ?? null;
			$provider = $data['provider'] ?? null;
			$currency = $data['currency'] ?? null;
			$currentBalance = isset($data['currentBalance']) ? (float)$data['currentBalance'] : null;
			$monthlyContribution = isset($data['monthlyContribution']) ? (float)$data['monthlyContribution'] : null;
			$expectedReturnRate = isset($data['expectedReturnRate']) ? (float)$data['expectedReturnRate'] : null;
			$retirementAge = isset($data['retirementAge']) ? (int)$data['retirementAge'] : null;
			$annualIncome = isset($data['annualIncome']) ? (float)$data['annualIncome'] : null;
			$transferValue = isset($data['transferValue']) ? (float)$data['transferValue'] : null;
			$projectionTarget = isset($data['projectionTarget']) && $data['projectionTarget'] !== '' ? (float)$data['projectionTarget'] : null;

			// Validate name if provided
			if ($name !== null) {
				$nameValidation = $this->validationService->validateName($name, false);
				if (!$nameValidation['valid']) {
					return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$name = $nameValidation['sanitized'];
			}

			// Validate pension type if provided
			if ($type !== null && !in_array($type, PensionAccount::VALID_TYPES, true)) {
				return new DataResponse([
					'error' => $this->l->t('Invalid pension type. Must be one of: %1$s', [implode(', ', PensionAccount::VALID_TYPES)])
				], Http::STATUS_BAD_REQUEST);
			}

			// Validate provider if provided
			if ($provider !== null && $provider !== '') {
				$providerValidation = $this->validationService->validateName($provider, false);
				if (!$providerValidation['valid']) {
					return new DataResponse(['error' => $providerValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$provider = $providerValidation['sanitized'];
			}

			// Validate currency if provided
			if ($currency !== null && strlen($currency) !== 3) {
				return new DataResponse(['error' => $this->l->t('Currency must be a 3-letter code')], Http::STATUS_BAD_REQUEST);
			}

			// Validate numeric fields
			if ($currentBalance !== null && $currentBalance < 0) {
				return new DataResponse(['error' => $this->l->t('Current balance cannot be negative')], Http::STATUS_BAD_REQUEST);
			}
			if ($monthlyContribution !== null && $monthlyContribution < 0) {
				return new DataResponse(['error' => $this->l->t('Monthly contribution cannot be negative')], Http::STATUS_BAD_REQUEST);
			}
			if ($expectedReturnRate !== null && ($expectedReturnRate < 0 || $expectedReturnRate > 1)) {
				return new DataResponse(['error' => $this->l->t('Expected return rate must be between 0%% and 100%%')], Http::STATUS_BAD_REQUEST);
			}
			if ($retirementAge !== null && ($retirementAge < 18 || $retirementAge > 100)) {
				return new DataResponse(['error' => $this->l->t('Retirement age must be between 18 and 100')], Http::STATUS_BAD_REQUEST);
			}
			if ($annualIncome !== null && $annualIncome < 0) {
				return new DataResponse(['error' => $this->l->t('Annual income cannot be negative')], Http::STATUS_BAD_REQUEST);
			}
			if ($transferValue !== null && $transferValue < 0) {
				return new DataResponse(['error' => $this->l->t('Transfer value cannot be negative')], Http::STATUS_BAD_REQUEST);
			}

			$pension = $this->service->update(
				$id,
				$this->getEffectiveUserId(),
				$name,
				$type,
				$provider,
				$currency,
				$currentBalance,
				$monthlyContribution,
				$expectedReturnRate,
				$retirementAge,
				$annualIncome,
				$transferValue,
				$projectionTarget
			);
			return new DataResponse($pension);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to update pension'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroy(int $id): DataResponse {
		try {
			$this->service->delete($id, $this->getEffectiveUserId());
			return new DataResponse(['message' => $this->l->t('Pension deleted successfully')]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to delete pension'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	// =====================
	// Snapshot Endpoints
	// =====================

	/**
	 * @NoAdminRequired
	 */
	public function snapshots(int $id): DataResponse {
		try {
			$snapshots = $this->service->getSnapshots($id, $this->getEffectiveUserId());
			return new DataResponse($snapshots);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve snapshots'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function createSnapshot(int $id): DataResponse {
		try {
			// Get JSON data from request body
			$data = $this->request->getParams();

			if (empty($data)) {
				return new DataResponse(['error' => $this->l->t('Invalid JSON data')], Http::STATUS_BAD_REQUEST);
			}

			$balance = isset($data['balance']) ? (float)$data['balance'] : null;
			$date = $data['date'] ?? null;

			if ($balance === null || $date === null) {
				return new DataResponse(['error' => $this->l->t('Balance and date are required')], Http::STATUS_BAD_REQUEST);
			}

			// Validate balance
			if ($balance < 0) {
				return new DataResponse(['error' => $this->l->t('Balance cannot be negative')], Http::STATUS_BAD_REQUEST);
			}

			// Validate date
			$dateValidation = $this->validationService->validateDate($date, $this->l->t('Date'), true);
			if (!$dateValidation['valid']) {
				return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
			}

			$snapshot = $this->service->createSnapshot($id, $this->getEffectiveUserId(), $balance, $date);
			return new DataResponse($snapshot, Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to create snapshot'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroySnapshot(int $snapshotId): DataResponse {
		try {
			$this->service->deleteSnapshot($snapshotId, $this->getEffectiveUserId());
			return new DataResponse(['message' => $this->l->t('Snapshot deleted successfully')]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to delete snapshot'), Http::STATUS_BAD_REQUEST, ['snapshotId' => $snapshotId]);
		}
	}

	// =====================
	// Contribution Endpoints
	// =====================

	/**
	 * @NoAdminRequired
	 */
	public function contributions(int $id): DataResponse {
		try {
			$contributions = $this->service->getContributions($id, $this->getEffectiveUserId());
			return new DataResponse($contributions);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve contributions'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function createContribution(int $id): DataResponse {
		try {
			// Get JSON data from request body
			$data = $this->request->getParams();

			if (empty($data)) {
				return new DataResponse(['error' => $this->l->t('Invalid JSON data')], Http::STATUS_BAD_REQUEST);
			}

			$amount = isset($data['amount']) ? (float)$data['amount'] : null;
			$date = $data['date'] ?? null;
			$note = $data['note'] ?? null;
			// Optional: a bank account this contribution was transferred from (#304).
			$sourceAccountId = isset($data['sourceAccountId']) && $data['sourceAccountId'] !== '' && $data['sourceAccountId'] !== null
				? (int)$data['sourceAccountId']
				: null;

			if ($amount === null || $date === null) {
				return new DataResponse(['error' => $this->l->t('Amount and date are required')], Http::STATUS_BAD_REQUEST);
			}

			// Validate amount
			if ($amount <= 0) {
				return new DataResponse(['error' => $this->l->t('Amount must be greater than zero')], Http::STATUS_BAD_REQUEST);
			}

			// Validate date
			$dateValidation = $this->validationService->validateDate($date, $this->l->t('Date'), true);
			if (!$dateValidation['valid']) {
				return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
			}

			// Validate note if provided
			if ($note !== null && $note !== '') {
				$noteValidation = $this->validationService->validateDescription($note, false);
				if (!$noteValidation['valid']) {
					return new DataResponse(['error' => $noteValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$note = $noteValidation['sanitized'];
			}

			if ($sourceAccountId !== null) {
				$contribution = $this->service->createContributionWithTransfer($id, $this->getEffectiveUserId(), $amount, $date, $sourceAccountId, $note);
			} else {
				$contribution = $this->service->createContribution($id, $this->getEffectiveUserId(), $amount, $date, $note);
			}
			return new DataResponse($contribution, Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to create contribution'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	/**
	 * Record a withdrawal/drawdown (optionally paid into a bank account).
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function createWithdrawal(int $id): DataResponse {
		try {
			$data = $this->request->getParams();

			$amount = isset($data['amount']) ? (float)$data['amount'] : null;
			$date = $data['date'] ?? null;
			$note = $data['note'] ?? null;
			$destAccountId = isset($data['destAccountId']) && $data['destAccountId'] !== '' && $data['destAccountId'] !== null
				? (int)$data['destAccountId']
				: null;

			if ($amount === null || $date === null) {
				return new DataResponse(['error' => $this->l->t('Amount and date are required')], Http::STATUS_BAD_REQUEST);
			}
			if ($amount <= 0) {
				return new DataResponse(['error' => $this->l->t('Amount must be greater than zero')], Http::STATUS_BAD_REQUEST);
			}

			$dateValidation = $this->validationService->validateDate($date, $this->l->t('Date'), true);
			if (!$dateValidation['valid']) {
				return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
			}

			if ($note !== null && $note !== '') {
				$noteValidation = $this->validationService->validateDescription($note, false);
				if (!$noteValidation['valid']) {
					return new DataResponse(['error' => $noteValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$note = $noteValidation['sanitized'];
			}

			if ($destAccountId !== null) {
				$withdrawal = $this->service->createWithdrawalWithTransfer($id, $this->getEffectiveUserId(), $amount, $date, $destAccountId, $note);
			} else {
				$withdrawal = $this->service->createWithdrawal($id, $this->getEffectiveUserId(), $amount, $date, $note);
			}
			return new DataResponse($withdrawal, Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to record withdrawal'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroyContribution(int $contributionId): DataResponse {
		try {
			$this->service->deleteContribution($contributionId, $this->getEffectiveUserId());
			return new DataResponse(['message' => $this->l->t('Contribution deleted successfully')]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to delete contribution'), Http::STATUS_BAD_REQUEST, ['contributionId' => $contributionId]);
		}
	}

	// =====================
	// Charts & Activity
	// =====================

	/**
	 * Snapshot balance series for the detail-panel chart.
	 *
	 * @NoAdminRequired
	 */
	public function balanceHistory(int $id): DataResponse {
		try {
			$history = $this->service->getBalanceHistory($id, $this->getEffectiveUserId());
			return new DataResponse($history);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve balance history'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	/**
	 * Merged contributions/withdrawals/snapshots timeline for the detail panel.
	 *
	 * @NoAdminRequired
	 */
	public function activity(int $id): DataResponse {
		try {
			$activity = $this->service->getActivity($id, $this->getEffectiveUserId());
			return new DataResponse($activity);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve pension activity'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	// =====================
	// Recurring Contributions (#251)
	// =====================

	/**
	 * @NoAdminRequired
	 */
	public function recurring(int $id): DataResponse {
		try {
			$schedules = $this->recurringService->findByPension($id, $this->getEffectiveUserId());
			return new DataResponse($schedules);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve recurring contributions'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function createRecurring(int $id): DataResponse {
		try {
			$data = $this->request->getParams();

			$amount = isset($data['amount']) ? (float)$data['amount'] : null;
			$frequency = $data['frequency'] ?? null;
			$nextDueDate = $data['nextDueDate'] ?? null;
			$sourceAccountId = isset($data['sourceAccountId']) && $data['sourceAccountId'] !== '' && $data['sourceAccountId'] !== null
				? (int)$data['sourceAccountId']
				: null;
			$autoPostEnabled = filter_var($data['autoPostEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
			$note = $data['note'] ?? null;

			if ($amount === null || $frequency === null || $nextDueDate === null) {
				return new DataResponse(['error' => $this->l->t('Amount, frequency and next date are required')], Http::STATUS_BAD_REQUEST);
			}
			if ($amount <= 0) {
				return new DataResponse(['error' => $this->l->t('Amount must be greater than zero')], Http::STATUS_BAD_REQUEST);
			}

			$dateValidation = $this->validationService->validateDate($nextDueDate, $this->l->t('Next date'), true);
			if (!$dateValidation['valid']) {
				return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
			}

			if ($note !== null && $note !== '') {
				$noteValidation = $this->validationService->validateDescription($note, false);
				if (!$noteValidation['valid']) {
					return new DataResponse(['error' => $noteValidation['error']], Http::STATUS_BAD_REQUEST);
				}
				$note = $noteValidation['sanitized'];
			}

			$recur = $this->recurringService->create(
				$id,
				$this->getEffectiveUserId(),
				$amount,
				(string)$frequency,
				$sourceAccountId,
				$autoPostEnabled,
				$nextDueDate,
				$note
			);
			return new DataResponse($recur, Http::STATUS_CREATED);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to create recurring contribution'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function updateRecurring(int $recurId): DataResponse {
		try {
			$data = $this->request->getParams();
			$recur = $this->recurringService->update($recurId, $this->getEffectiveUserId(), $data);
			return new DataResponse($recur);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to update recurring contribution'), Http::STATUS_BAD_REQUEST, ['recurId' => $recurId]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 20, period: 60)]
	public function destroyRecurring(int $recurId): DataResponse {
		try {
			$this->recurringService->delete($recurId, $this->getEffectiveUserId());
			return new DataResponse(['message' => $this->l->t('Recurring contribution deleted')]);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to delete recurring contribution'), Http::STATUS_BAD_REQUEST, ['recurId' => $recurId]);
		}
	}

	/**
	 * Post a scheduled contribution now (#251 "by hand").
	 *
	 * @NoAdminRequired
	 */
	#[UserRateLimit(limit: 30, period: 60)]
	public function postRecurring(int $recurId): DataResponse {
		try {
			$recur = $this->recurringService->postNow($recurId, $this->getEffectiveUserId());
			return new DataResponse($recur);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to post contribution'), Http::STATUS_BAD_REQUEST, ['recurId' => $recurId]);
		}
	}

	// =====================
	// Summary & Projections
	// =====================

	/**
	 * @NoAdminRequired
	 */
	public function summary(): DataResponse {
		try {
			$summary = $this->service->getSummary($this->getEffectiveUserId());
			return new DataResponse($summary);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve pension summary'));
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function projection(int $id, ?int $currentAge = null): DataResponse {
		try {
			$age = $currentAge ?? $this->getAgeFromProfile();
			$projection = $this->projector->getProjection($id, $this->getEffectiveUserId(), $age);
			return new DataResponse($projection);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve pension projection'), Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
		}
	}

	/**
	 * @NoAdminRequired
	 */
	public function combinedProjection(?int $currentAge = null): DataResponse {
		try {
			$age = $currentAge ?? $this->getAgeFromProfile();
			$projection = $this->projector->getCombinedProjection($this->getEffectiveUserId(), $age);
			return new DataResponse($projection);
		} catch (\Exception $e) {
			return $this->handleError($e, $this->l->t('Failed to retrieve combined pension projection'));
		}
	}

	/**
	 * Calculate current age from the user's Nextcloud profile birthdate.
	 */
	private function getAgeFromProfile(): ?int {
		try {
			$user = $this->userManager->get($this->getEffectiveUserId());
			if ($user === null) {
				return null;
			}
			$account = $this->accountManager->getAccount($user);
			$birthdate = $account->getProperty(IAccountManager::PROPERTY_BIRTHDATE)->getValue();
			if (empty($birthdate)) {
				return null;
			}
			$dob = new \DateTime($birthdate);
			$now = new \DateTime();
			return (int)$dob->diff($now)->y;
		} catch (\Exception $e) {
			return null;
		}
	}
}
