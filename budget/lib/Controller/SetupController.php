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
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class SetupController extends Controller {
    use ApiErrorHandlerTrait;

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
        string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->categoryService = $categoryService;
        $this->importRuleService = $importRuleService;
        $this->l = $l;
        $this->userId = $userId;
        $this->setLogger($logger);
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
            return $this->handleError($e, $this->l->t('Failed to initialize budget app'));
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
            return $this->handleError($e, $this->l->t('Failed to get setup status'));
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
            return $this->handleError($e, $this->l->t('Failed to remove duplicate categories'));
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
            return $this->handleError($e, $this->l->t('Failed to reset categories'));
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
            return $this->handleError($e, $this->l->t('Factory reset failed'), Http::STATUS_INTERNAL_SERVER_ERROR);
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
            return $this->handleError($e, $this->l->t('Balance recalculation failed'), Http::STATUS_INTERNAL_SERVER_ERROR);
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
            return $this->handleError($e, $this->l->t('Diagnosis failed'), Http::STATUS_INTERNAL_SERVER_ERROR);
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

            $validCategories = ['duplicateTransactions', 'stuckBills', 'paidOneTimeBills', 'futureClearedTransactions', 'balanceDrift'];
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
            return $this->handleError($e, $this->l->t('Repair failed'), Http::STATUS_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @NoAdminRequired
     */
    public function systemInfo(): DataResponse {
        try {
            $appVersion = \OCP\Server::get(\OCP\App\IAppManager::class)->getAppVersion(Application::APP_ID);
            $ncVersion = \OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('version', 'unknown');

            // Count user data
            $accounts = $this->accountService->findAll($this->userId);
            $accountCount = count($accounts);

            $db = \OCP\Server::get(\OCP\IDBConnection::class);
            $qb = $db->getQueryBuilder();
            $qb->select($qb->func()->count('t.id'))
                ->from('budget_transactions', 't')
                ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'))
                ->where($qb->expr()->eq('a.user_id', $qb->createNamedParameter($this->userId)));
            $txCount = (int) $qb->executeQuery()->fetchOne();

            $categoryCount = count($this->categoryService->findAll($this->userId));

            // Count rules
            $qbR = $db->getQueryBuilder();
            $qbR->select($qbR->func()->count('id'))
                ->from('budget_import_rules')
                ->where($qbR->expr()->eq('user_id', $qbR->createNamedParameter($this->userId)));
            $ruleCount = (int) $qbR->executeQuery()->fetchOne();

            $qbRA = $db->getQueryBuilder();
            $qbRA->select($qbRA->func()->count('id'))
                ->from('budget_import_rules')
                ->where($qbRA->expr()->eq('user_id', $qbRA->createNamedParameter($this->userId)))
                ->andWhere($qbRA->expr()->eq('active', $qbRA->createNamedParameter(true, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_BOOL)));
            $activeRules = (int) $qbRA->executeQuery()->fetchOne();

            // Count bills
            $qb2 = $db->getQueryBuilder();
            $qb2->select($qb2->func()->count('id'))
                ->from('budget_bills')
                ->where($qb2->expr()->eq('user_id', $qb2->createNamedParameter($this->userId)));
            $billCount = (int) $qb2->executeQuery()->fetchOne();

            // Bank sync connections
            $qb3 = $db->getQueryBuilder();
            $qb3->select($qb3->func()->count('id'))
                ->from('budget_bc')
                ->where($qb3->expr()->eq('user_id', $qb3->createNamedParameter($this->userId)));
            $bankSyncCount = (int) $qb3->executeQuery()->fetchOne();

            // Read recent budget-related log entries (admin only)
            $logEntries = [];
            $isAdmin = \OCP\Server::get(\OCP\IGroupManager::class)->isAdmin($this->userId);
            try {
                if ($isAdmin) {
                $logFile = \OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('logfile', '');
                if (empty($logFile)) {
                    $dataDir = \OCP\Server::get(\OCP\IConfig::class)->getSystemValueString('datadirectory', '');
                    $logFile = $dataDir . '/nextcloud.log';
                }
                if (file_exists($logFile) && is_readable($logFile)) {
                    // Read last 200 lines and filter for budget app entries
                    $lines = array_slice(file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), -200);
                    foreach ($lines as $line) {
                        $entry = json_decode($line, true);
                        if ($entry && isset($entry['app']) && $entry['app'] === 'budget' && ($entry['level'] ?? 0) >= 2) {
                            $logEntries[] = [
                                'time' => $entry['time'] ?? '',
                                'level' => $entry['level'] ?? 0,
                                'message' => mb_substr($entry['message'] ?? '', 0, 200),
                            ];
                        }
                    }
                    // Keep last 10
                    $logEntries = array_slice($logEntries, -10);
                }
            } // end isAdmin
            } catch (\Exception $e) {
                // Silently ignore — log may not be accessible
            }

            // Sharing status
            $qbShOut = $db->getQueryBuilder();
            $qbShOut->select($qbShOut->func()->count('id'))
                ->from('budget_shares')
                ->where($qbShOut->expr()->eq('owner_user_id', $qbShOut->createNamedParameter($this->userId)));
            $sharingOut = (int) $qbShOut->executeQuery()->fetchOne();

            $qbShIn = $db->getQueryBuilder();
            $qbShIn->select($qbShIn->func()->count('id'))
                ->from('budget_shares')
                ->where($qbShIn->expr()->eq('shared_with_user_id', $qbShIn->createNamedParameter($this->userId)));
            $sharingIn = (int) $qbShIn->executeQuery()->fetchOne();

            return new DataResponse([
                'appVersion' => $appVersion,
                'nextcloudVersion' => $ncVersion,
                'phpVersion' => PHP_VERSION,
                'database' => $db->getDatabaseProvider(),
                'accounts' => $accountCount,
                'transactions' => $txCount,
                'categories' => $categoryCount,
                'rules' => $ruleCount,
                'activeRules' => $activeRules,
                'bills' => $billCount,
                'bankSyncConnections' => $bankSyncCount,
                'sharingOut' => $sharingOut,
                'sharingIn' => $sharingIn,
                'serverLogs' => $logEntries,
            ]);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to get system info'));
        }
    }
}