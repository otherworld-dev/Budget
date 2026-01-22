<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Service\ImportRuleService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
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
        private FactoryResetService $factoryResetService,
        private AuditService $auditService,
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

    /**
     * @NoAdminRequired
     */
    public function removeDuplicateCategories(): DataResponse {
        try {
            $deleted = $this->categoryService->removeDuplicates($this->userId);

            return new DataResponse([
                'deleted' => $deleted,
                'count' => count($deleted),
                'message' => count($deleted) . ' duplicate categories removed'
            ]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function resetCategories(): DataResponse {
        try {
            $deletedCount = $this->categoryService->deleteAll($this->userId);
            $categories = $this->categoryService->createDefaultCategories($this->userId);

            return new DataResponse([
                'deleted' => $deletedCount,
                'created' => count($categories),
                'message' => "Reset complete: deleted $deletedCount, created " . count($categories)
            ]);
        } catch (\Exception $e) {
            return new DataResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Factory reset - delete ALL user data except audit logs.
     * This is a destructive operation that cannot be undone.
     *
     * @NoAdminRequired
     */
    #[PasswordConfirmationRequired]
    #[UserRateLimit(limit: 3, period: 300)]
    public function factoryReset(): DataResponse {
        try {
            // Require explicit confirmation parameter to prevent accidental resets
            $confirmed = $this->request->getParam('confirmed', false);
            if (!$confirmed) {
                return new DataResponse([
                    'error' => 'Factory reset requires confirmed=true parameter. This will permanently delete ALL your data.'
                ], Http::STATUS_BAD_REQUEST);
            }

            // Execute the factory reset
            $counts = $this->factoryResetService->executeFactoryReset($this->userId);

            // Log the factory reset action for audit trail
            $this->auditService->log(
                $this->userId,
                'factory_reset',
                'setup',
                0,
                ['deletedCounts' => $counts]
            );

            return new DataResponse([
                'success' => true,
                'message' => 'Factory reset completed successfully. All data has been deleted.',
                'deletedCounts' => $counts
            ]);
        } catch (\Exception $e) {
            return new DataResponse([
                'error' => 'Factory reset failed: ' . $e->getMessage()
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}