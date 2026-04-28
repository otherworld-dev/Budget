<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AccountService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\CategoryService;
use OCA\Budget\Service\FactoryResetService;
use OCA\Budget\Service\ImportRuleService;
use OCA\Budget\Service\RepairService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;

class SetupController extends Controller {
    private CategoryService $categoryService;
    private ImportRuleService $importRuleService;
    private IL10N $l;
    private string $userId;

    public function __construct(
        IRequest $request,
        CategoryService $categoryService,
        ImportRuleService $importRuleService,
        private FactoryResetService $factoryResetService,
        private AuditService $auditService,
        private AccountService $accountService,
        private RepairService $repairService,
        IL10N $l,
        string $userId
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->categoryService = $categoryService;
        $this->importRuleService = $importRuleService;
        $this->l = $l;
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
            
            $results['message'] = $this->l->t('Budget app initialized successfully');
            
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
                'message' => $this->l->t('%1$s duplicate categories removed', [count($deleted)])
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
                'message' => $this->l->t('Reset complete: deleted %1$s, created %2$s', [$deletedCount, count($categories)])
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
    #[UserRateLimit(limit: 3, period: 300)]
    public function factoryReset(): DataResponse {
        try {
            // Require explicit confirmation parameter to prevent accidental resets
            $confirmed = $this->request->getParam('confirmed', false);
            if (!$confirmed) {
                return new DataResponse([
                    'error' => $this->l->t('Factory reset requires confirmed=true parameter. This will permanently delete ALL your data.')
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
                'message' => $this->l->t('Factory reset completed successfully. All data has been deleted.'),
                'deletedCounts' => $counts
            ]);
        } catch (\Exception $e) {
            return new DataResponse([
                'error' => $this->l->t('Factory reset failed: %1$s', [$e->getMessage()])
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Recalculate all account balances from opening_balance + transaction history.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 3, period: 300)]
    public function recalculateBalances(): DataResponse {
        try {
            $results = $this->accountService->recalculateAllBalances($this->userId);

            $this->auditService->log(
                $this->userId,
                'recalculate_balances',
                'account',
                0,
                ['updated' => $results['updated'], 'total' => $results['total']]
            );

            return new DataResponse($results);
        } catch (\Exception $e) {
            return new DataResponse([
                'error' => $this->l->t('Balance recalculation failed: %1$s', [$e->getMessage()])
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Diagnose data integrity issues (dry run).
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function diagnoseData(): DataResponse {
        try {
            $findings = $this->repairService->diagnose($this->userId);
            return new DataResponse($findings);
        } catch (\Exception $e) {
            return new DataResponse([
                'error' => $this->l->t('Diagnosis failed: %1$s', [$e->getMessage()])
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Repair data integrity issues.
     *
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 3, period: 300)]
    public function repairData(): DataResponse {
        try {
            $params = $this->request->getParams();
            $categories = $params['categories'] ?? [];

            if (empty($categories)) {
                return new DataResponse([
                    'error' => $this->l->t('No repair categories specified')
                ], Http::STATUS_BAD_REQUEST);
            }

            $validCategories = ['duplicateTransactions', 'stuckBills', 'balanceDrift'];
            $categories = array_intersect($categories, $validCategories);

            if (empty($categories)) {
                return new DataResponse([
                    'error' => $this->l->t('No valid repair categories specified')
                ], Http::STATUS_BAD_REQUEST);
            }

            $results = $this->repairService->repair($this->userId, $categories);

            $this->auditService->log(
                $this->userId,
                'repair_data',
                'setup',
                0,
                ['categories' => $categories, 'results' => $results]
            );

            return new DataResponse($results);
        } catch (\Exception $e) {
            return new DataResponse([
                'error' => $this->l->t('Repair failed: %1$s', [$e->getMessage()])
            ], Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }
}