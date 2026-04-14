<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\ShareService;
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

class CategoryController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;
    use SharedAccessTrait;

    private CategoryService $service;
    private ValidationService $validationService;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        CategoryService $service,
        ValidationService $validationService,
        ShareService $shareService,
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
        $this->setShareService($shareService);
    }

    /**
     * @NoAdminRequired
     */
    public function index(?string $type = null): DataResponse {
        try {
            if ($type) {
                $categories = $this->service->findByType($this->getEffectiveUserId(), $type);
            } else {
                $categories = $this->service->findAll($this->getEffectiveUserId());
            }
            return new DataResponse($categories);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve categories'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function tree(): DataResponse {
        try {
            $tree = $this->service->getCategoryTree($this->getEffectiveUserId());
            return new DataResponse($tree);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve category tree'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function transactionCounts(): DataResponse {
        try {
            $counts = $this->service->getCategoryTransactionCounts($this->getEffectiveUserId());
            return new DataResponse($counts);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve transaction counts'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $category = $this->service->find($id, $this->getEffectiveUserId());
            return new DataResponse($category);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Category'), ['categoryId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function create(
        string $name,
        string $type,
        ?int $parentId = null,
        ?string $icon = null,
        ?string $color = null,
        ?float $budgetAmount = null,
        int $sortOrder = 0
    ): DataResponse {
        try {
            // Validate name (required)
            $nameValidation = $this->validationService->validateName($name, true);
            if (!$nameValidation['valid']) {
                return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
            }
            $name = $nameValidation['sanitized'];

            // Validate type
            $validTypes = ['income', 'expense'];
            if (!in_array($type, $validTypes, true)) {
                return new DataResponse(['error' => $this->l->t('Invalid category type. Must be income or expense')], Http::STATUS_BAD_REQUEST);
            }

            // Validate optional fields
            if ($icon !== null) {
                $iconValidation = $this->validationService->validateIcon($icon);
                if (!$iconValidation['valid']) {
                    return new DataResponse(['error' => $iconValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $icon = $iconValidation['sanitized'];
            }

            if ($color !== null) {
                $colorValidation = $this->validationService->validateColor($color);
                if (!$colorValidation['valid']) {
                    return new DataResponse(['error' => $colorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $color = $colorValidation['sanitized'];
            }

            $category = $this->service->create(
                $this->getEffectiveUserId(),
                $name,
                $type,
                $parentId,
                $icon,
                $color,
                $budgetAmount,
                $sortOrder
            );
            return new DataResponse($category, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return $this->handleValidationError($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function update(
        int $id,
        ?string $name = null,
        ?string $type = null,
        ?int $parentId = null,
        ?string $icon = null,
        ?string $color = null,
        ?float $budgetAmount = null,
        ?string $budgetPeriod = null,
        ?int $sortOrder = null
    ): DataResponse {
        try {
            $updates = [];

            // Validate name if provided
            if ($name !== null) {
                $nameValidation = $this->validationService->validateName($name, false);
                if (!$nameValidation['valid']) {
                    return new DataResponse(['error' => $nameValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['name'] = $nameValidation['sanitized'];
            }

            // Validate type if provided
            if ($type !== null) {
                $validTypes = ['income', 'expense'];
                if (!in_array($type, $validTypes, true)) {
                    return new DataResponse(['error' => $this->l->t('Invalid category type. Must be income or expense')], Http::STATUS_BAD_REQUEST);
                }
                $updates['type'] = $type;
            }

            // Validate icon if provided
            if ($icon !== null) {
                $iconValidation = $this->validationService->validateIcon($icon);
                if (!$iconValidation['valid']) {
                    return new DataResponse(['error' => $iconValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['icon'] = $iconValidation['sanitized'];
            }

            // Validate color if provided
            if ($color !== null) {
                $colorValidation = $this->validationService->validateColor($color);
                if (!$colorValidation['valid']) {
                    return new DataResponse(['error' => $colorValidation['error']], Http::STATUS_BAD_REQUEST);
                }
                $updates['color'] = $colorValidation['sanitized'];
            }

            // Validate budgetPeriod if provided
            if ($budgetPeriod !== null) {
                $validPeriods = ['monthly', 'weekly', 'yearly', 'quarterly'];
                if (!in_array($budgetPeriod, $validPeriods, true)) {
                    return new DataResponse(['error' => $this->l->t('Invalid budget period. Must be monthly, weekly, yearly, or quarterly')], Http::STATUS_BAD_REQUEST);
                }
                $updates['budgetPeriod'] = $budgetPeriod;
            }

            // Handle other fields
            if ($parentId !== null) {
                $updates['parentId'] = $parentId;
            }
            if ($budgetAmount !== null) {
                $updates['budgetAmount'] = $budgetAmount;
            }
            if ($sortOrder !== null) {
                $updates['sortOrder'] = $sortOrder;
            }

            if (empty($updates)) {
                return new DataResponse(['error' => $this->l->t('No valid fields to update')], Http::STATUS_BAD_REQUEST);
            }

            $category = $this->service->update($id, $this->getEffectiveUserId(), $updates);
            return new DataResponse($category);
        } catch (\Exception $e) {
            return $this->handleValidationError($e);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->getEffectiveUserId());
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to delete category'), Http::STATUS_BAD_REQUEST, ['categoryId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function allSpending(string $startDate, string $endDate): DataResponse {
        try {
            $spending = $this->service->getAllCategorySpending($this->getEffectiveUserId(), $startDate, $endDate);
            return new DataResponse($spending);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve category spending'));
        }
    }

    /**
     * @NoAdminRequired
     */
    public function spending(int $id, string $startDate, string $endDate): DataResponse {
        try {
            $spending = $this->service->getCategorySpending($id, $this->getEffectiveUserId(), $startDate, $endDate);
            return new DataResponse(['spending' => $spending]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to retrieve category spending'), Http::STATUS_BAD_REQUEST, ['categoryId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function details(int $id): DataResponse {
        try {
            $details = $this->service->getCategoryDetails($id, $this->getEffectiveUserId());
            return new DataResponse($details);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Category'), ['categoryId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function transactions(int $id, int $limit = 5): DataResponse {
        try {
            $transactions = $this->service->getCategoryTransactions($id, $this->getEffectiveUserId(), $limit);
            return new DataResponse($transactions);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, $this->l->t('Category'), ['categoryId' => $id]);
        }
    }
}