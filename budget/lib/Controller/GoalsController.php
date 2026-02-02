<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\GoalsService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class GoalsController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;

    private GoalsService $service;
    private ValidationService $validationService;
    private string $userId;

    public function __construct(
        IRequest $request,
        GoalsService $service,
        ValidationService $validationService,
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->validationService = $validationService;
        $this->userId = $userId;
        $this->setLogger($logger);
        $this->setInputValidator($validationService);
    }

    /**
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $goals = $this->service->findAll($this->userId);
            return new DataResponse($goals);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve goals');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $goal = $this->service->find($id, $this->userId);
            return new DataResponse($goal);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Goal', ['goalId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function create(
        string $name,
        float $targetAmount,
        float $currentAmount = 0.0,
        ?int $targetMonths = null,
        ?string $description = null,
        ?string $targetDate = null
    ): DataResponse {
        try {
            // Validate name (required)
            $nameValidation = $this->validationService->validateName($name, true);
            if (!$nameValidation['valid']) {
                return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $name = $nameValidation['sanitized'];

            // Validate description if provided
            if (!empty($description)) {
                $descValidation = $this->validationService->validateDescription($description, false);
                if (!$descValidation['valid']) {
                    return new DataResponse(['error' => $descValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $description = $descValidation['sanitized'];
            }

            // Validate targetDate if provided
            if ($targetDate !== null && $targetDate !== '') {
                $dateValidation = $this->validationService->validateDate($targetDate, 'Target date', false);
                if (!$dateValidation['valid']) {
                    return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
                }
            } else {
                $targetDate = null;
            }

            // Validate targetAmount is positive
            if ($targetAmount <= 0) {
                return new DataResponse(['error' => 'Target amount must be greater than zero'], Http::STATUS_BAD_REQUEST);
            }

            // Validate targetMonths if provided
            if ($targetMonths !== null && $targetMonths <= 0) {
                return new DataResponse(['error' => 'Target months must be greater than zero'], Http::STATUS_BAD_REQUEST);
            }

            // Validate currentAmount is not negative
            if ($currentAmount < 0) {
                return new DataResponse(['error' => 'Current amount cannot be negative'], Http::STATUS_BAD_REQUEST);
            }

            $goal = $this->service->create(
                $this->userId,
                $name,
                $targetAmount,
                $targetMonths,
                $currentAmount,
                $description,
                $targetDate
            );
            return new DataResponse($goal, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to create goal');
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(
        int $id,
        string $name = null,
        float $targetAmount = null,
        int $targetMonths = null,
        float $currentAmount = null,
        string $description = null,
        string $targetDate = null
    ): DataResponse {
        try {
            // Validate name if provided
            if ($name !== null) {
                $nameValidation = $this->validationService->validateName($name, false);
                if (!$nameValidation['valid']) {
                    return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $name = $nameValidation['sanitized'];
            }

            // Validate description if provided
            if ($description !== null) {
                $descValidation = $this->validationService->validateDescription($description, false);
                if (!$descValidation['valid']) {
                    return new DataResponse(['error' => $descValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $description = $descValidation['sanitized'];
            }

            // Validate targetDate if provided
            if ($targetDate !== null) {
                $dateValidation = $this->validationService->validateDate($targetDate, 'Target date', false);
                if (!$dateValidation['valid']) {
                    return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
                }
            }

            // Validate targetAmount if provided
            if ($targetAmount !== null && $targetAmount <= 0) {
                return new DataResponse(['error' => 'Target amount must be greater than zero'], Http::STATUS_BAD_REQUEST);
            }

            // Validate targetMonths if provided
            if ($targetMonths !== null && $targetMonths <= 0) {
                return new DataResponse(['error' => 'Target months must be greater than zero'], Http::STATUS_BAD_REQUEST);
            }

            // Validate currentAmount if provided
            if ($currentAmount !== null && $currentAmount < 0) {
                return new DataResponse(['error' => 'Current amount cannot be negative'], Http::STATUS_BAD_REQUEST);
            }

            $goal = $this->service->update(
                $id,
                $this->userId,
                $name,
                $targetAmount,
                $targetMonths,
                $currentAmount,
                $description,
                $targetDate
            );
            return new DataResponse($goal);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to update goal', Http::STATUS_BAD_REQUEST, ['goalId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(['message' => 'Goal deleted successfully']);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to delete goal', Http::STATUS_BAD_REQUEST, ['goalId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function progress(int $id): DataResponse {
        try {
            $progress = $this->service->getProgress($id, $this->userId);
            return new DataResponse($progress);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve goal progress', Http::STATUS_BAD_REQUEST, ['goalId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function forecast(int $id): DataResponse {
        try {
            $forecast = $this->service->getForecast($id, $this->userId);
            return new DataResponse($forecast);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve goal forecast', Http::STATUS_BAD_REQUEST, ['goalId' => $id]);
        }
    }
}