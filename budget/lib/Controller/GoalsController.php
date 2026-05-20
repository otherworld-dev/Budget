<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\GoalsService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCA\Budget\Traits\SharedAccessTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class GoalsController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;
    use SharedAccessTrait;

    private GoalsService $service;
    private ValidationService $validationService;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        GoalsService $service,
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
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $goals = $this->service->findAll($this->userId);

            // Merge shared savings goals
            $shared = $this->granularShareService->getSharedSavingsGoals($this->userId);
            if (!empty($shared)) {
                $goals = array_merge(
                    array_map(fn($g) => $g->jsonSerialize(), $goals),
                    $shared
                );
                return new DataResponse($goals);
            }

            return new DataResponse($goals);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve goals'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $goal = $this->service->find($id, $this->getEffectiveUserId());
            return new DataResponse($goal);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Goal'), ['goalId' => $id]);
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
        ?string $targetDate = null,
        ?string $accountId = null,
        ?int $tagId = null,
        ?string $color = null
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
                $dateValidation = $this->validationService->validateDate($targetDate, $this->l->t('Target date'), false);
                if (!$dateValidation['valid']) {
                    return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
                }
            } else {
                $targetDate = null;
            }

            // Validate targetAmount is positive
            if ($targetAmount <= 0) {
                return new DataResponse(['error' => $this->l->t('Target amount must be greater than zero')], Http::STATUS_BAD_REQUEST);
            }

            // Validate targetMonths if provided
            if ($targetMonths !== null && $targetMonths <= 0) {
                return new DataResponse(['error' => $this->l->t('Target months must be greater than zero')], Http::STATUS_BAD_REQUEST);
            }

            // Validate currentAmount is not negative
            if ($currentAmount < 0) {
                return new DataResponse(['error' => $this->l->t('Current amount cannot be negative')], Http::STATUS_BAD_REQUEST);
            }

            // Validate accountId if provided
            if ($accountId === '') {
                return new DataResponse(['error' => $this->l->t("Account id can't be empty")], Http::STATUS_BAD_REQUEST);
            }

            // Validate tagId if provided
            if ($tagId !== null && $tagId <= 0) {
                return new DataResponse(['error' => $this->l->t('Invalid tag ID')], Http::STATUS_BAD_REQUEST);
            }

            if ($color !== null) {
                $colorValidation = $this->validationService->validateColor($color);
                if (!$colorValidation['valid']) {
                    return new DataResponse(['error' => $colorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $color = $colorValidation['sanitized'];
            }

            $goal = $this->service->create(
                $this->getEffectiveUserId(),
                $name,
                $targetAmount,
                $targetMonths,
                $currentAmount,
                $description,
                $targetDate,
                $accountId,
                $tagId,
                $color,
            );
            return new DataResponse($goal, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to create goal'));
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(
        int $id,
        ?string $name = null,
        ?float $targetAmount = null,
        ?int $targetMonths = null,
        ?float $currentAmount = null,
        ?string $description = null,
        ?string $targetDate = null,
        ?string $accountId = null,
        ?int $tagId = null,
        ?string $color = null,
    ): DataResponse {
        try {
            $this->requireWriteAccess('savings_goal', $id);

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
                $dateValidation = $this->validationService->validateDate($targetDate, $this->l->t('Target date'), false);
                if (!$dateValidation['valid']) {
                    return new DataResponse(['error' => $dateValidation['error']], Http::STATUS_BAD_REQUEST);
                }
            }

            // Validate targetAmount if provided
            if ($targetAmount !== null && $targetAmount <= 0) {
                return new DataResponse(['error' => $this->l->t('Target amount must be greater than zero')], Http::STATUS_BAD_REQUEST);
            }

            // Validate targetMonths if provided
            if ($targetMonths !== null && $targetMonths <= 0) {
                return new DataResponse(['error' => $this->l->t('Target months must be greater than zero')], Http::STATUS_BAD_REQUEST);
            }

            // Validate currentAmount if provided
            if ($currentAmount !== null && $currentAmount < 0) {
                return new DataResponse(['error' => $this->l->t('Current amount cannot be negative')], Http::STATUS_BAD_REQUEST);
            }

            // Validate accountId if provided
            if ($accountId === '') {
                return new DataResponse(['error' => $this->l->t("Account id can't be empty")], Http::STATUS_BAD_REQUEST);
            }
            // Validate tagId if provided
            if ($tagId !== null && $tagId <= 0) {
                return new DataResponse(['error' => $this->l->t('Invalid tag ID')], Http::STATUS_BAD_REQUEST);
            }

            if ($color !== null) {
                $colorValidation = $this->validationService->validateColor($color);
                if (!$colorValidation['valid']) {
                    return new DataResponse(['error' => $colorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $color = $colorValidation['sanitized'];
            }

            // Detect if tagId was explicitly sent in the request body
            $params = $this->request->getParams();
            $updateTagId = array_key_exists('tagId', $params);

            $goal = $this->service->update(
                $id,
                $this->getEffectiveUserId(),
                $name,
                $targetAmount,
                $targetMonths,
                $currentAmount,
                $description,
                $targetDate,
                $accountId,
                $tagId,
                $color,
                $updateTagId
            );
            return new DataResponse($goal);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to update goal'), Http::STATUS_BAD_REQUEST, ['goalId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->requireWriteAccess('savings_goal', $id);
            $this->service->delete($id, $this->getEffectiveUserId());
            return new DataResponse(['message' => $this->l->t('Goal deleted successfully')]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to delete goal'), Http::STATUS_BAD_REQUEST, ['goalId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function progress(int $id): DataResponse {
        try {
            $progress = $this->service->getProgress($id, $this->getEffectiveUserId());
            return new DataResponse($progress);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve goal progress'), Http::STATUS_BAD_REQUEST, ['goalId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function forecast(int $id): DataResponse {
        try {
            $forecast = $this->service->getForecast($id, $this->getEffectiveUserId());
            return new DataResponse($forecast);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve goal forecast'), Http::STATUS_BAD_REQUEST, ['goalId' => $id]);
        }
    }
}