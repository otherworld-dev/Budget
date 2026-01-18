<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Service\PensionProjector;
use OCA\Budget\Service\PensionService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class PensionController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;

    private PensionService $service;
    private PensionProjector $projector;
    private ValidationService $validationService;
    private ?string $userId;

    public function __construct(
        IRequest $request,
        PensionService $service,
        PensionProjector $projector,
        ValidationService $validationService,
        ?string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->projector = $projector;
        $this->validationService = $validationService;
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setInputValidator($validationService);
    }

    /**
     * Get the current user ID or throw an error if not authenticated.
     */
    private function getUserId(): string {
        if ($this->userId === null) {
            throw new \RuntimeException('User not authenticated');
        }
        return $this->userId;
    }

    // =====================
    // Pension Account CRUD
    // =====================

    /**
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $pensions = $this->service->findAll($this->getUserId());
            return new DataResponse($pensions);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve pensions');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $pension = $this->service->find($id, $this->getUserId());
            return new DataResponse($pension);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Pension', ['pensionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function create(): DataResponse {
        try {
            // Get JSON data from request body
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);

            if (!$data) {
                return new DataResponse(['error' => 'Invalid JSON data'], Http::STATUS_BAD_REQUEST);
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

            // Validate required fields
            if (!$name || !$type) {
                return new DataResponse(['error' => 'Name and type are required'], Http::STATUS_BAD_REQUEST);
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
                    'error' => 'Invalid pension type. Must be one of: ' . implode(', ', PensionAccount::VALID_TYPES)
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
                return new DataResponse(['error' => 'Currency must be a 3-letter code'], Http::STATUS_BAD_REQUEST);
            }

            // Validate numeric fields
            if ($currentBalance !== null && $currentBalance < 0) {
                return new DataResponse(['error' => 'Current balance cannot be negative'], Http::STATUS_BAD_REQUEST);
            }
            if ($monthlyContribution !== null && $monthlyContribution < 0) {
                return new DataResponse(['error' => 'Monthly contribution cannot be negative'], Http::STATUS_BAD_REQUEST);
            }
            if ($expectedReturnRate !== null && ($expectedReturnRate < 0 || $expectedReturnRate > 1)) {
                return new DataResponse(['error' => 'Expected return rate must be between 0 and 1'], Http::STATUS_BAD_REQUEST);
            }
            if ($retirementAge !== null && ($retirementAge < 18 || $retirementAge > 100)) {
                return new DataResponse(['error' => 'Retirement age must be between 18 and 100'], Http::STATUS_BAD_REQUEST);
            }
            if ($annualIncome !== null && $annualIncome < 0) {
                return new DataResponse(['error' => 'Annual income cannot be negative'], Http::STATUS_BAD_REQUEST);
            }
            if ($transferValue !== null && $transferValue < 0) {
                return new DataResponse(['error' => 'Transfer value cannot be negative'], Http::STATUS_BAD_REQUEST);
            }

            $pension = $this->service->create(
                $this->getUserId(),
                $name,
                $type,
                $provider,
                $currency,
                $currentBalance,
                $monthlyContribution,
                $expectedReturnRate,
                $retirementAge,
                $annualIncome,
                $transferValue
            );
            return new DataResponse($pension, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to create pension');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(int $id): DataResponse {
        try {
            // Get JSON data from request body
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);

            if (!$data) {
                return new DataResponse(['error' => 'Invalid JSON data'], Http::STATUS_BAD_REQUEST);
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
                    'error' => 'Invalid pension type. Must be one of: ' . implode(', ', PensionAccount::VALID_TYPES)
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
                return new DataResponse(['error' => 'Currency must be a 3-letter code'], Http::STATUS_BAD_REQUEST);
            }

            // Validate numeric fields
            if ($currentBalance !== null && $currentBalance < 0) {
                return new DataResponse(['error' => 'Current balance cannot be negative'], Http::STATUS_BAD_REQUEST);
            }
            if ($monthlyContribution !== null && $monthlyContribution < 0) {
                return new DataResponse(['error' => 'Monthly contribution cannot be negative'], Http::STATUS_BAD_REQUEST);
            }
            if ($expectedReturnRate !== null && ($expectedReturnRate < 0 || $expectedReturnRate > 1)) {
                return new DataResponse(['error' => 'Expected return rate must be between 0 and 1'], Http::STATUS_BAD_REQUEST);
            }
            if ($retirementAge !== null && ($retirementAge < 18 || $retirementAge > 100)) {
                return new DataResponse(['error' => 'Retirement age must be between 18 and 100'], Http::STATUS_BAD_REQUEST);
            }
            if ($annualIncome !== null && $annualIncome < 0) {
                return new DataResponse(['error' => 'Annual income cannot be negative'], Http::STATUS_BAD_REQUEST);
            }
            if ($transferValue !== null && $transferValue < 0) {
                return new DataResponse(['error' => 'Transfer value cannot be negative'], Http::STATUS_BAD_REQUEST);
            }

            $pension = $this->service->update(
                $id,
                $this->getUserId(),
                $name,
                $type,
                $provider,
                $currency,
                $currentBalance,
                $monthlyContribution,
                $expectedReturnRate,
                $retirementAge,
                $annualIncome,
                $transferValue
            );
            return new DataResponse($pension);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to update pension', Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->getUserId());
            return new DataResponse(['message' => 'Pension deleted successfully']);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to delete pension', Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
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
            $snapshots = $this->service->getSnapshots($id, $this->getUserId());
            return new DataResponse($snapshots);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve snapshots', Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function createSnapshot(int $id): DataResponse {
        try {
            // Get JSON data from request body
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);

            if (!$data) {
                return new DataResponse(['error' => 'Invalid JSON data'], Http::STATUS_BAD_REQUEST);
            }

            $balance = isset($data['balance']) ? (float)$data['balance'] : null;
            $date = $data['date'] ?? null;

            if ($balance === null || $date === null) {
                return new DataResponse(['error' => 'Balance and date are required'], Http::STATUS_BAD_REQUEST);
            }

            // Validate balance
            if ($balance < 0) {
                return new DataResponse(['error' => 'Balance cannot be negative'], Http::STATUS_BAD_REQUEST);
            }

            // Validate date
            $dateValidation = $this->validationService->validateDate($date, 'Date', true);
            if (!$dateValidation['valid']) {
                return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
            }

            $snapshot = $this->service->createSnapshot($id, $this->getUserId(), $balance, $date);
            return new DataResponse($snapshot, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to create snapshot', Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroySnapshot(int $snapshotId): DataResponse {
        try {
            $this->service->deleteSnapshot($snapshotId, $this->getUserId());
            return new DataResponse(['message' => 'Snapshot deleted successfully']);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to delete snapshot', Http::STATUS_BAD_REQUEST, ['snapshotId' => $snapshotId]);
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
            $contributions = $this->service->getContributions($id, $this->getUserId());
            return new DataResponse($contributions);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve contributions', Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function createContribution(int $id): DataResponse {
        try {
            // Get JSON data from request body
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);

            if (!$data) {
                return new DataResponse(['error' => 'Invalid JSON data'], Http::STATUS_BAD_REQUEST);
            }

            $amount = isset($data['amount']) ? (float)$data['amount'] : null;
            $date = $data['date'] ?? null;
            $note = $data['note'] ?? null;

            if ($amount === null || $date === null) {
                return new DataResponse(['error' => 'Amount and date are required'], Http::STATUS_BAD_REQUEST);
            }

            // Validate amount
            if ($amount <= 0) {
                return new DataResponse(['error' => 'Amount must be greater than zero'], Http::STATUS_BAD_REQUEST);
            }

            // Validate date
            $dateValidation = $this->validationService->validateDate($date, 'Date', true);
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

            $contribution = $this->service->createContribution($id, $this->getUserId(), $amount, $date, $note);
            return new DataResponse($contribution, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to create contribution', Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroyContribution(int $contributionId): DataResponse {
        try {
            $this->service->deleteContribution($contributionId, $this->getUserId());
            return new DataResponse(['message' => 'Contribution deleted successfully']);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to delete contribution', Http::STATUS_BAD_REQUEST, ['contributionId' => $contributionId]);
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
            $summary = $this->service->getSummary($this->getUserId());
            return new DataResponse($summary);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve pension summary');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function projection(int $id, ?int $currentAge = null): DataResponse {
        try {
            $projection = $this->projector->getProjection($id, $this->getUserId(), $currentAge);
            return new DataResponse($projection);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve pension projection', Http::STATUS_BAD_REQUEST, ['pensionId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function combinedProjection(?int $currentAge = null): DataResponse {
        try {
            $projection = $this->projector->getCombinedProjection($this->getUserId(), $currentAge);
            return new DataResponse($projection);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve combined pension projection');
        }
    }
}
