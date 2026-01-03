<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\CategoryService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class CategoryController extends Controller {
    private CategoryService $service;
    private string $userId;

    public function __construct(
        IRequest $request,
        CategoryService $service,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function update(
        int $id,
        ?string $name = null,
        ?string $type = null,
        ?int $parentId = null,
        ?string $icon = null,
        ?string $color = null,
        ?float $budgetAmount = null,
        ?int $sortOrder = null
    ): DataResponse {
        try {
            $updates = array_filter([
                'name' => $name,
                'type' => $type,
                'parentId' => $parentId,
                'icon' => $icon,
                'color' => $color,
                'budgetAmount' => $budgetAmount,
                'sortOrder' => $sortOrder,
            ], fn($value) => $value !== null);
            
            $category = $this->service->update($id, $this->userId, $updates);
            return new DataResponse($category);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function destroy(int $id): DataResponse {
        try {
            $this->service->delete($id, $this->userId);
            return new DataResponse(['status' => 'success']);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}