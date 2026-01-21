<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class RecurringIncomeController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;

    private RecurringIncomeService $service;
    private ValidationService $validationService;
    private string $userId;

    public function __construct(
        IRequest $request,
        RecurringIncomeService $service,
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
            return new DataResponse($incomes);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve recurring income');
        }
    }

    /**
     * Get a single recurring income entry
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $income = $this->service->find($id, $this->userId);
            return new DataResponse($income);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Recurring income', ['incomeId' => $id]);
        }
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
        ?string $notes = null
    ): DataResponse {
        try {
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

            // Validate expectedDay range
            if ($expectedDay !== null && ($expectedDay < 1 || $expectedDay > 31)) {
                return new DataResponse(['error' => 'Expected day must be between 1 and 31'], Http::STATUS_BAD_REQUEST);
            }

            // Validate expectedMonth range
            if ($expectedMonth !== null && ($expectedMonth < 1 || $expectedMonth > 12)) {
                return new DataResponse(['error' => 'Expected month must be between 1 and 12'], Http::STATUS_BAD_REQUEST);
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

            $income = $this->service->create(
                $this->userId,
                $name,
                $amount,
                $frequency,
                $expectedDay,
                $expectedMonth,
                $categoryId,
                $accountId,
                $source,
                $autoDetectPattern,
                $notes
            );

            return new DataResponse($income, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to create recurring income');
        }
    }

    /**
     * Update a recurring income entry
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(int $id): DataResponse {
        try {
            $rawInput = file_get_contents('php://input');
            $data = json_decode($rawInput, true);

            if (!$data) {
                return new DataResponse(['error' => 'Invalid JSON data'], Http::STATUS_BAD_REQUEST);
            }

            // Validate name if provided
            if (isset($data['name'])) {
                $nameValidation = $this->validationService->validateName($data['name'], true);
                if (!$nameValidation['valid']) {
                    return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $data['name'] = $nameValidation['sanitized'];
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
                    return new DataResponse(['error' => 'Expected day must be between 1 and 31'], Http::STATUS_BAD_REQUEST);
                }
            }

            // Validate expectedMonth range if provided
            if (isset($data['expectedMonth']) && $data['expectedMonth'] !== null) {
                if ($data['expectedMonth'] < 1 || $data['expectedMonth'] > 12) {
                    return new DataResponse(['error' => 'Expected month must be between 1 and 12'], Http::STATUS_BAD_REQUEST);
                }
            }

            $income = $this->service->update($id, $this->userId, $data);
            return new DataResponse($income);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Recurring income', ['incomeId' => $id]);
        }
    }

    /**
     * Delete a recurring income entry
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(['message' => 'Recurring income deleted']);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Recurring income', ['incomeId' => $id]);
        }
    }

    /**
     * Get upcoming income entries
     * @NoAdminRequired
     */
    public function upcoming(?int $days = 30): DataResponse {
        try {
            $incomes = $this->service->findUpcoming($this->userId, $days);
            return new DataResponse($incomes);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve upcoming income');
        }
    }

    /**
     * Get income expected this month
     * @NoAdminRequired
     */
    public function expectedThisMonth(): DataResponse {
        try {
            $incomes = $this->service->findExpectedThisMonth($this->userId);
            return new DataResponse($incomes);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve expected income');
        }
    }

    /**
     * Get monthly summary of recurring income
     * @NoAdminRequired
     */
    public function summary(): DataResponse {
        try {
            $summary = $this->service->getMonthlySummary($this->userId);
            return new DataResponse($summary);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve income summary');
        }
    }

    /**
     * Mark income as received and advance to next expected date
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function markReceived(int $id, ?string $receivedDate = null): DataResponse {
        try {
            $income = $this->service->markReceived($id, $this->userId, $receivedDate);
            return new DataResponse($income);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Recurring income', ['incomeId' => $id]);
        }
    }
}
