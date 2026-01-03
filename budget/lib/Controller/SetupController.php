<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\ImportRuleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\IRequest;

class SetupController extends Controller {
    private CategoryService $categoryService;
    private ImportRuleService $importRuleService;
    private string $userId;

    public function __construct(
        IRequest $request,
        CategoryService $categoryService,
        ImportRuleService $importRuleService,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->categoryService = $categoryService;
        $this->importRuleService = $importRuleService;
        $this->userId = $userId;
    }

    /**
     * @NoAdminRequired
     */
    public function initialize(): DataResponse {
        try {
            $results = [];
            
            // Create default categories
            $categories = $this->categoryService->createDefaultCategories($this->userId);
            $results['categoriesCreated'] = count($categories);
            
            // Create default import rules
            $rules = $this->importRuleService->createDefaultRules($this->userId);
            $results['rulesCreated'] = count($rules);
            
            $results['message'] = 'Budget app initialized successfully';
            
            return new DataResponse($results, Http::STATUS_CREATED);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function status(): DataResponse {
        try {
            $categories = $this->categoryService->findAll($this->userId);
            $rules = $this->importRuleService->findAll($this->userId);
            
            return new DataResponse([
                'initialized' => count($categories) > 0,
                'categoriesCount' => count($categories),
                'rulesCount' => count($rules)
            ]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }
}