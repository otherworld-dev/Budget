<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\GranularShareService;
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
        LoggerInterface $logger
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

            // Merge shared recurring income
            $shared = $this->granularShareService->getSharedRecurringIncome($this->userId);
            if (!empty($shared)) {
                $incomes = array_merge(
                    array_map(fn($i) => $i->jsonSerialize(), $incomes),
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
            if ($owner === $this->userId) {
                return new DataResponse($income);
            }
            return new DataResponse(array_merge($income->jsonSerialize(), [
                '_shared' => true,
                '_canWrite' => $this->granularShareService->canWrite($this->userId, 'recurring_income', $id),
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
        ?string $startDate = null
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

            // Coerce the forecast-exclude flag to a real boolean (#270)
            if (array_key_exists('excludedFromForecast', $data)) {
                $data['excludedFromForecast'] = filter_var($data['excludedFromForecast'], FILTER_VALIDATE_BOOLEAN);
            }

            $income = $this->service->update($id, $this->incomeOwner($id), $data);
            return new DataResponse($income);
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
            $this->requireWriteAccess('recurring_income', $id);
            $this->service->delete($id, $this->incomeOwner($id));
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
     * Get monthly summary of recurring income
     * @NoAdminRequired
     */
    public function summary(): DataResponse {
        try {
            $summary = $this->service->getMonthlySummary($this->getEffectiveUserId());
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
            $createTransaction = (bool) ($params['createTransaction'] ?? false);

            $income = $this->service->markReceived($id, $this->incomeOwner($id), $receivedDate, $createTransaction);
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

            $created = $this->service->createFromDetected($this->getEffectiveUserId(), $data['incomes']);
            return new DataResponse([
                'created' => count($created),
                'incomes' => $created,
            ], Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create recurring income from detected patterns'));
        }
    }
}
