<?php

declare(strict_types=1);

namespace OCA\Budget\Controller;

use OCA\Budget\AppInfo\Application;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCA\Budget\Service\BankSync\GoCardlessProvider;
use OCA\Budget\Service\BankSync\ProviderFactory;
use OCA\Budget\Traits\ApiErrorHandlerTrait;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\DataResponse;
use OCP\IL10N;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

class BankSyncController extends Controller {
    use ApiErrorHandlerTrait;

    public function __construct(
        IRequest $request,
        private BankSyncService $syncService,
        private AdminSettingService $adminSettings,
        private ProviderFactory $providerFactory,
        private IL10N $l,
        private string $userId,
        LoggerInterface $logger
    ) {
        parent::__construct(Application::APP_ID, $request);
        $this->setLogger($logger);
    }

    private function requireBankSync(): ?DataResponse {
        if (!$this->adminSettings->isBankSyncEnabled()) {
            return new DataResponse(
                ['error' => $this->l->t('Bank sync is disabled by the administrator')],
                Http::STATUS_FORBIDDEN
            );
        }
        return null;
    }

    /**
     * Check if bank sync is enabled and if user has connections.
     * @NoAdminRequired
     */
    public function status(): DataResponse {
        $enabled = $this->adminSettings->isBankSyncEnabled();
        $connections = $enabled ? $this->syncService->getConnections($this->userId) : [];
        return new DataResponse([
            'enabled' => $enabled,
            'hasConnections' => count($connections) > 0,
            'connectionCount' => count($connections),
        ]);
    }

    /**
     * List available providers.
     * @NoAdminRequired
     */
    public function providers(): DataResponse {
        if ($r = $this->requireBankSync()) return $r;
        return new DataResponse($this->providerFactory->getAvailableProviders());
    }

    /**
     * List institutions for a provider (GoCardless bank list).
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function institutions(string $provider, string $country = 'GB'): DataResponse {
        if ($r = $this->requireBankSync()) return $r;

        try {
            $p = $this->providerFactory->getProvider($provider);
            if (!($p instanceof GoCardlessProvider)) {
                return new DataResponse(['error' => $this->l->t('This provider does not support institution listing')], Http::STATUS_BAD_REQUEST);
            }

            // Need to get an access token first using user's stored credentials
            // For initial setup, credentials come from the request
            $params = $this->request->getParams();
            $secretId = $params['secretId'] ?? null;
            $secretKey = $params['secretKey'] ?? null;

            if (!$secretId || !$secretKey) {
                return new DataResponse(['error' => $this->l->t('API credentials required')], Http::STATUS_BAD_REQUEST);
            }

            // Get access token and fetch institutions
            $accessToken = $this->getGoCardlessToken($p, $secretId, $secretKey);
            $institutions = $p->getInstitutions($accessToken, $country);

            return new DataResponse($institutions);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to fetch institutions'), Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * List user's bank connections.
     * @NoAdminRequired
     */
    public function connections(): DataResponse {
        if ($r = $this->requireBankSync()) return $r;
        return new DataResponse($this->syncService->getConnections($this->userId));
    }

    /**
     * Create a new bank connection.
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 5, period: 60)]
    public function connect(): DataResponse {
        if ($r = $this->requireBankSync()) return $r;

        try {
            $params = $this->request->getParams();
            $provider = $params['provider'] ?? null;
            $name = $params['name'] ?? null;

            if (!$provider || !$name) {
                return new DataResponse(['error' => $this->l->t('Provider and name are required')], Http::STATUS_BAD_REQUEST);
            }

            $validProviders = ['simplefin', 'gocardless'];
            if (!in_array($provider, $validProviders, true)) {
                return new DataResponse(['error' => $this->l->t('Invalid provider')], Http::STATUS_BAD_REQUEST);
            }

            // Extract provider-specific params
            $providerParams = [];
            if ($provider === 'simplefin') {
                $providerParams['setupToken'] = $params['setupToken'] ?? null;
            } elseif ($provider === 'gocardless') {
                $providerParams['secretId'] = $params['secretId'] ?? null;
                $providerParams['secretKey'] = $params['secretKey'] ?? null;
                $providerParams['institutionId'] = $params['institutionId'] ?? null;
                $providerParams['redirectUrl'] = $params['redirectUrl'] ?? null;
            }

            $result = $this->syncService->connect($this->userId, $provider, $providerParams, $name);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to connect bank'), Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Disconnect a bank connection.
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function disconnect(int $id): DataResponse {
        if ($r = $this->requireBankSync()) return $r;

        try {
            $this->syncService->disconnect($this->userId, $id);
            return new DataResponse(['status' => 'ok']);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to disconnect bank'), Http::STATUS_BAD_REQUEST, ['connectionId' => $id]);
        }
    }

    /**
     * Manually trigger a sync for a connection.
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function sync(int $id): DataResponse {
        if ($r = $this->requireBankSync()) return $r;

        try {
            $result = $this->syncService->sync($this->userId, $id);
            return new DataResponse($result);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to sync bank data'), Http::STATUS_BAD_REQUEST, ['connectionId' => $id]);
        }
    }

    /**
     * Get account mappings for a connection.
     * @NoAdminRequired
     */
    public function mappings(int $id): DataResponse {
        if ($r = $this->requireBankSync()) return $r;

        try {
            // Verify connection ownership
            $connections = $this->syncService->getConnections($this->userId);
            $found = false;
            $mappings = [];
            foreach ($connections as $conn) {
                if ($conn['connection']->getId() === $id) {
                    $found = true;
                    $mappings = $conn['mappings'];
                    break;
                }
            }

            if (!$found) {
                return new DataResponse(['error' => $this->l->t('Connection not found')], Http::STATUS_NOT_FOUND);
            }

            return new DataResponse($mappings);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to fetch mappings'), Http::STATUS_BAD_REQUEST, ['connectionId' => $id]);
        }
    }

    /**
     * Update an account mapping.
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 30, period: 60)]
    public function updateMapping(int $id, int $mappingId): DataResponse {
        if ($r = $this->requireBankSync()) return $r;

        try {
            $params = $this->request->getParams();
            $budgetAccountId = array_key_exists('budgetAccountId', $params) ? ($params['budgetAccountId'] !== null ? (int) $params['budgetAccountId'] : null) : null;
            $enabled = isset($params['enabled']) ? (bool) $params['enabled'] : null;

            $mapping = $this->syncService->updateMapping($this->userId, $id, $mappingId, $budgetAccountId, $enabled);
            return new DataResponse($mapping);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to update mapping'), Http::STATUS_BAD_REQUEST);
        }
    }

    /**
     * Refresh account list from provider.
     * @NoAdminRequired
     */
    #[UserRateLimit(limit: 10, period: 60)]
    public function refreshAccounts(int $id): DataResponse {
        if ($r = $this->requireBankSync()) return $r;

        try {
            $mappings = $this->syncService->refreshAccounts($this->userId, $id);
            return new DataResponse($mappings);
        } catch (\Exception $e) {
            return $this->handleError($e, $this->l->t('Failed to refresh accounts'), Http::STATUS_BAD_REQUEST, ['connectionId' => $id]);
        }
    }

    private function getGoCardlessToken(GoCardlessProvider $provider, string $secretId, string $secretKey): string {
        return $provider->getToken($secretId, $secretKey);
    }
}
