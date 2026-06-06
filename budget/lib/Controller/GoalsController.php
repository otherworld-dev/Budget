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
            $goals = array_map(
                fn($g) => $g->jsonSerialize(),
                $this->service->findAll($this->userId)
            );

            // Merge in goals shared with this user, flagging write permission
            $sharedIds = $this->granularShareService->getSharedSavingsGoalIds($this->userId);
            $shared = $this->service->findShared($sharedIds);
            foreach ($shared as &$goal) {
                $goal['_canWrite'] = $this->granularShareService->canWrite(
                    $this->userId,
                    'savings_goal',
                    (int) $goal['id']
                );
            }
            unset($goal);

            return new DataResponse(array_merge($goals, $shared));
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve goals'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $owner = $this->granularShareService->resolveOwner($this->userId, 'savings_goal', $id);
            if ($owner === null) {
                return new DataResponse(
                    ['error' => $this->l->t('%1$s not found', [$this->l->t('Goal')])],
                    Http::STATUS_NOT_FOUND
                );
            }

            $goal = $this->service->find($id, $owner)->jsonSerialize();
            if ($owner !== $this->userId) {
                $goal['_shared'] = true;
                $goal['_canWrite'] = $this->granularShareService->canWrite($this->userId, 'savings_goal', $id);
            }
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
        ?int $tagId = null,
        ?int $accountId = null,
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

            // Validate tagId if provided
            if ($tagId !== null && $tagId <= 0) {
                return new DataResponse(['error' => $this->l->t('Invalid tag ID')], Http::STATUS_BAD_REQUEST);
            }

            // Validate accountId if provided
            if ($accountId !== null && $accountId <= 0) {
                return new DataResponse(['error' => $this->l->t('Invalid account ID')], Http::STATUS_BAD_REQUEST);
            }

            // Validate color if provided
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
                $tagId,
                $accountId,
                $color
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
        ?int $tagId = null,
        ?int $accountId = null,
        ?string $color = null
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

            // Validate tagId if provided
            if ($tagId !== null && $tagId <= 0) {
                return new DataResponse(['error' => $this->l->t('Invalid tag ID')], Http::STATUS_BAD_REQUEST);
            }

            // Validate accountId if provided
            if ($accountId !== null && $accountId <= 0) {
                return new DataResponse(['error' => $this->l->t('Invalid account ID')], Http::STATUS_BAD_REQUEST);
            }

            // Validate color if provided
            if ($color !== null) {
                $colorValidation = $this->validationService->validateColor($color);
                if (!$colorValidation['valid']) {
                    return new DataResponse(['error' => $colorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $color = $colorValidation['sanitized'];
            }

            // Detect if tagId/accountId were explicitly sent in the request body
            $params = $this->request->getParams();
            $updateTagId = array_key_exists('tagId', $params);
            $updateAccountId = array_key_exists('accountId', $params);

            // Write access confirmed above; resolve the owner so the
            // owner-scoped service lookup succeeds for shared goals too.
            $owner = $this->granularShareService->resolveOwner($this->userId, 'savings_goal', $id)
                ?? $this->userId;

            $goal = $this->service->update(
                $id,
                $owner,
                $name,
                $targetAmount,
                $targetMonths,
                $currentAmount,
                $description,
                $targetDate,
                $tagId,
                $updateTagId,
                $accountId,
                $updateAccountId,
                $color
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
            $owner = $this->granularShareService->resolveOwner($this->userId, 'savings_goal', $id);
            if ($owner === null) {
                return new DataResponse(
                    ['error' => $this->l->t('%1$s not found', [$this->l->t('Goal')])],
                    Http::STATUS_NOT_FOUND
                );
            }
            // Only the owner may delete a goal — recipients (even with write
            // access) can edit and contribute but not remove it.
            if ($owner !== $this->userId) {
                return new DataResponse(
                    ['error' => $this->l->t('Only the goal owner can delete it')],
                    Http::STATUS_FORBIDDEN
                );
            }

            $this->service->delete($id, $this->userId);
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
            $owner = $this->granularShareService->resolveOwner($this->userId, 'savings_goal', $id);
            if ($owner === null) {
                return new DataResponse(
                    ['error' => $this->l->t('%1$s not found', [$this->l->t('Goal')])],
                    Http::STATUS_NOT_FOUND
                );
            }
            $progress = $this->service->getProgress($id, $owner);
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
            $owner = $this->granularShareService->resolveOwner($this->userId, 'savings_goal', $id);
            if ($owner === null) {
                return new DataResponse(
                    ['error' => $this->l->t('%1$s not found', [$this->l->t('Goal')])],
                    Http::STATUS_NOT_FOUND
                );
            }
            $forecast = $this->service->getForecast($id, $owner);
            return new DataResponse($forecast);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve goal forecast'), Http::STATUS_BAD_REQUEST, ['goalId' => $id]);
        }
    }
}