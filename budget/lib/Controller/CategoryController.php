<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\ValidationService;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCA\Budget\Traits\InputValidationTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class CategoryController extends Controller {
    use ApiErrorHandlerTrait;
    use InputValidationTrait;

    private CategoryService $service;
    private ValidationService $validationService;
    private string $userId;

    public function __construct(
        IRequest $request,
        CategoryService $service,
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
    public function index(?string $type = null): DataResponse {
        try {
            if ($type) {
                $categories = $this->service->findByType($this->userId, $type);
            } else {
                $categories = $this->service->findAll($this->userId);
            }
            return new DataResponse($categories);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve categories');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function tree(): DataResponse {
        try {
            $tree = $this->service->getCategoryTree($this->userId);
            return new DataResponse($tree);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve category tree');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $category = $this->service->find($id, $this->userId);
            return new DataResponse($category);
        } catch (\Exception $e) {
            return $this->handleNotFoundError($e, 'Category', ['categoryId' => $id]);
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
                return new DataResponse(['error' => 'Invalid category type. Must be income or expense'], Http::STATUS_BAD_REQUEST);
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
                $this->userId,
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
            return $this->handleError($e, 'Failed to create category');
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
                    return new DataResponse(['error' => 'Invalid category type. Must be income or expense'], Http::STATUS_BAD_REQUEST);
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
                    return new DataResponse(['error' => 'Invalid budget period. Must be monthly, weekly, yearly, or quarterly'], Http::STATUS_BAD_REQUEST);
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
                return new DataResponse(['error' => 'No valid fields to update'], Http::STATUS_BAD_REQUEST);
            }

            $category = $this->service->update($id, $this->userId, $updates);
            return new DataResponse($category);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to update category', Http::STATUS_BAD_REQUEST, ['categoryId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 20, period: 60)]
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to delete category', Http::STATUS_BAD_REQUEST, ['categoryId' => $id]);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function allSpending(string $startDate, string $endDate): DataResponse {
        try {
            $spending = $this->service->getAllCategorySpending($this->userId, $startDate, $endDate);
            return new DataResponse($spending);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve category spending');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function spending(int $id, string $startDate, string $endDate): DataResponse {
        try {
            $spending = $this->service->getCategorySpending($id, $this->userId, $startDate, $endDate);
            return new DataResponse(['spending' => $spending]);
        } catch (\Exception $e) {
            return $this->handleError($e, 'Failed to retrieve category spending', Http::STATUS_BAD_REQUEST, ['categoryId' => $id]);
        }
    }
}