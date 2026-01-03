<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\ImportRuleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class ImportRuleController extends Controller {
    private ImportRuleService $service;
    private string $userId;

    public function __construct(
        IRequest $request,
        ImportRuleService $service,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->service = $service;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function index(): DataResponse {
        try {
            $rules = $this->service->findAll($this->userId);
            return new DataResponse($rules);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function show(int $id): DataResponse {
        try {
            $rule = $this->service->find($id, $this->userId);
            return new DataResponse($rule);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function create(
        string $name,
        string $pattern,
        string $field,
        string $matchType,
        ?int $categoryId = null,
        ?string $vendorName = null,
        int $priority = 0
    ): DataResponse {
        try {
            $rule = $this->service->create(
                $this->userId,
                $name,
                $pattern,
                $field,
                $matchType,
                $categoryId,
                $vendorName,
                $priority
            );
            return new DataResponse($rule, Http::STATUS_CREATED);
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
        ?string $pattern = null,
        ?string $field = null,
        ?string $matchType = null,
        ?int $categoryId = null,
        ?string $vendorName = null,
        ?int $priority = null,
        ?bool $active = null
    ): DataResponse {
        try {
            $updates = array_filter([
                'name' => $name,
                'pattern' => $pattern,
                'field' => $field,
                'matchType' => $matchType,
                'categoryId' => $categoryId,
                'vendorName' => $vendorName,
                'priority' => $priority,
                'active' => $active,
            ], fn($value) => $value !== null);
            
            $rule = $this->service->update($id, $this->userId, $updates);
            return new DataResponse($rule);
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
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_NOT_FOUND);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function test(array $transactionData): DataResponse {
        try {
            $results = $this->service->testRules($this->userId, $transactionData);
            return new DataResponse($results);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}