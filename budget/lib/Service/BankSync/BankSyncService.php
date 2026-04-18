<?php

declare(strict_types=1);

namespace OCA\Budget\Service\BankSync;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\BankAccountMapping;
use OCA\Budget\Db\BankAccountMappingMapper;
use OCA\Budget\Db\BankConnection;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\AuditService;
use OCA\Budget\Service\TransactionService;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates bank sync operations: connecting, syncing transactions,
 * managing account mappings, and coordinating with providers.
 */
class BankSyncService {
    public function __construct(
        private BankConnectionMapper $connectionMapper,
        private BankAccountMappingMapper $mappingMapper,
        private ProviderFactory $providerFactory,
        private TransactionService $transactionService,
        private AuditService $auditService,
        private AdminSettingService $adminSettings,
        private AccountMapper $accountMapper,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Create a new bank connection.
     */
    public function connect(string $userId, string $providerName, array $params, string $name): array {
        $this->requireEnabled();

        $provider = $this->providerFactory->getProvider($providerName);
        $result = $provider->initializeConnection($params);
        $now = date('Y-m-d H:i:s');

        // Create connection record
        $connection = new BankConnection();
        $connection->setUserId($userId);
        $connection->setProvider($providerName);
        $connection->setName($name);
        $connection->setCredentials($result['credentials']);
        $connection->setStatus('active');
        $connection->setCreatedAt($now);
        $connection->setUpdatedAt($now);
        $connection = $this->connectionMapper->insert($connection);

        // Create account mappings for discovered accounts
        foreach ($result['accounts'] as $account) {
            $mapping = new BankAccountMapping();
            $mapping->setConnectionId($connection->getId());
            $mapping->setExternalAccountId($account['id']);
            $mapping->setExternalAccountName($account['name']);
            $mapping->setEnabled(false);
            $mapping->setLastBalance($account['balance'] ?? null);
            $mapping->setLastCurrency($account['currency'] ?? null);
            $mapping->setCreatedAt($now);
            $mapping->setUpdatedAt($now);
            $this->mappingMapper->insert($mapping);
        }

        $this->auditService->log($userId, 'bank_connected', 'bank_connection', $connection->getId(), [
            'provider' => $providerName,
            'name' => $name,
            'accountCount' => count($result['accounts']),
        ]);

        return [
            'connection' => $connection,
            'mappings' => $this->mappingMapper->findByConnection($connection->getId()),
            'authorizationUrl' => $result['authorizationUrl'] ?? null,
        ];
    }

    /**
     * Disconnect and delete a bank connection.
     */
    public function disconnect(string $userId, int $connectionId): void {
        $connection = $this->connectionMapper->find($connectionId, $userId);

        $this->mappingMapper->deleteByConnection($connectionId);
        $this->connectionMapper->delete($connection);

        $this->auditService->log($userId, 'bank_disconnected', 'bank_connection', $connectionId, [
            'provider' => $connection->getProvider(),
            'name' => $connection->getName(),
        ]);
    }

    /**
     * Sync transactions from a bank connection.
     *
     * @return array{imported: int, skipped: int, errors: int, accounts: array}
     */
    public function sync(string $userId, int $connectionId): array {
        $this->requireEnabled();

        $connection = $this->connectionMapper->find($connectionId, $userId);
        if ($connection->getStatus() !== 'active') {
            throw new \Exception('Connection is not active (status: ' . $connection->getStatus() . ')');
        }

        $provider = $this->providerFactory->getProvider($connection->getProvider());

        // Check if re-authorization is needed
        if ($provider->requiresReauthorization($connection->getCredentials())) {
            $connection->setStatus('expired');
            $connection->setLastError('Bank authorization has expired. Please re-authorize.');
            $connection->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->connectionMapper->update($connection);
            throw new \Exception('Bank authorization has expired. Please reconnect.');
        }

        // Fetch accounts and transactions from provider
        try {
            $data = $provider->fetchAccounts($connection->getCredentials());
        } catch (\Exception $e) {
            $connection->setStatus('error');
            $connection->setLastError($e->getMessage());
            $connection->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->connectionMapper->update($connection);

            $this->auditService->log($userId, 'bank_sync_failed', 'bank_connection', $connectionId, [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        // Persist refreshed credentials if the provider returned them (e.g. GoCardless token refresh)
        if (isset($data['updatedCredentials'])) {
            $connection->setCredentials($data['updatedCredentials']);
            $connection->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->connectionMapper->update($connection);
        }

        $enabledMappings = $this->mappingMapper->findEnabledByConnection($connectionId);
        $mappingsByExternalId = [];
        foreach ($enabledMappings as $m) {
            $mappingsByExternalId[$m->getExternalAccountId()] = $m;
        }

        $totalImported = 0;
        $totalSkipped = 0;
        $totalErrors = 0;
        $accountResults = [];

        foreach ($data['accounts'] as $externalAccount) {
            $mapping = $mappingsByExternalId[$externalAccount['id']] ?? null;
            if (!$mapping) {
                continue; // Account not mapped or not enabled
            }

            $budgetAccountId = $mapping->getBudgetAccountId();
            if (!$budgetAccountId) {
                continue;
            }

            // Verify the budget account exists and belongs to this user
            try {
                $this->accountMapper->find($budgetAccountId, $userId);
            } catch (\Exception $e) {
                $totalErrors++;
                continue;
            }

            $imported = 0;
            $skipped = 0;

            foreach ($externalAccount['transactions'] as $tx) {
                $importId = $connection->getProvider() . ':' . $tx['id'];

                // Check for duplicate via import ID
                if ($this->transactionService->existsByImportId($budgetAccountId, $importId)) {
                    $skipped++;
                    continue;
                }

                // Determine type: negative amount = debit (outflow), positive = credit (inflow)
                $amount = (float) $tx['amount'];
                $type = $amount < 0 ? 'debit' : 'credit';
                $absAmount = abs($amount);

                try {
                    $this->transactionService->create(
                        userId: $userId,
                        accountId: $budgetAccountId,
                        date: $tx['date'],
                        description: $tx['description'] ?? '',
                        amount: $absAmount,
                        type: $type,
                        vendor: $tx['vendor'] ?? null,
                        importId: $importId,
                        status: 'cleared'
                    );
                    $imported++;
                } catch (\Exception $e) {
                    $this->logger->warning("Bank sync: failed to create transaction: " . $e->getMessage(), [
                        'app' => 'budget',
                        'importId' => $importId,
                    ]);
                    $totalErrors++;
                }
            }

            // Update mapping balance
            $mapping->setLastBalance($externalAccount['balance'] ?? null);
            $mapping->setLastCurrency($externalAccount['currency'] ?? null);
            $mapping->setUpdatedAt(date('Y-m-d H:i:s'));
            $this->mappingMapper->update($mapping);

            $totalImported += $imported;
            $totalSkipped += $skipped;
            $accountResults[] = [
                'externalAccountId' => $externalAccount['id'],
                'name' => $externalAccount['name'],
                'imported' => $imported,
                'skipped' => $skipped,
            ];
        }

        // Update connection sync status
        $connection->setLastSyncAt(date('Y-m-d H:i:s'));
        $connection->setLastError($totalErrors > 0 ? "Sync completed with {$totalErrors} error(s)" : null);
        $connection->setStatus('active');
        $connection->setUpdatedAt(date('Y-m-d H:i:s'));
        $this->connectionMapper->update($connection);

        $this->auditService->log($userId, 'bank_sync_completed', 'bank_connection', $connectionId, [
            'imported' => $totalImported,
            'skipped' => $totalSkipped,
            'errors' => $totalErrors,
        ]);

        return [
            'imported' => $totalImported,
            'skipped' => $totalSkipped,
            'errors' => $totalErrors,
            'accounts' => $accountResults,
        ];
    }

    /**
     * Get all connections for a user with their mappings.
     */
    public function getConnections(string $userId): array {
        $connections = $this->connectionMapper->findAll($userId);
        $result = [];

        foreach ($connections as $conn) {
            $result[] = [
                'connection' => $conn,
                'mappings' => $this->mappingMapper->findByConnection($conn->getId()),
            ];
        }

        return $result;
    }

    /**
     * Update an account mapping (map external → Budget account, enable/disable).
     */
    public function updateMapping(string $userId, int $connectionId, int $mappingId, ?int $budgetAccountId, ?bool $enabled): BankAccountMapping {
        // Verify connection ownership
        $this->connectionMapper->find($connectionId, $userId);

        $mapping = $this->mappingMapper->find($mappingId);
        if ($mapping->getConnectionId() !== $connectionId) {
            throw new \Exception('Mapping does not belong to this connection');
        }

        if ($budgetAccountId !== null) {
            // Verify the budget account belongs to this user
            $this->accountMapper->find($budgetAccountId, $userId);
            $mapping->setBudgetAccountId($budgetAccountId);
        }

        if ($enabled !== null) {
            $mapping->setEnabled($enabled);
        }

        $mapping->setUpdatedAt(date('Y-m-d H:i:s'));
        return $this->mappingMapper->update($mapping);
    }

    /**
     * Refresh the account list from the provider (does NOT import transactions).
     */
    public function refreshAccounts(string $userId, int $connectionId): array {
        $this->requireEnabled();

        $connection = $this->connectionMapper->find($connectionId, $userId);
        $provider = $this->providerFactory->getProvider($connection->getProvider());
        $data = $provider->fetchAccounts($connection->getCredentials());
        $now = date('Y-m-d H:i:s');

        // Add any new accounts that don't exist yet
        $newMappings = [];
        foreach ($data['accounts'] as $account) {
            $existing = $this->mappingMapper->findByExternalId($connectionId, $account['id']);
            if (!$existing) {
                $mapping = new BankAccountMapping();
                $mapping->setConnectionId($connectionId);
                $mapping->setExternalAccountId($account['id']);
                $mapping->setExternalAccountName($account['name']);
                $mapping->setLastBalance($account['balance'] ?? null);
                $mapping->setLastCurrency($account['currency'] ?? null);
                $mapping->setCreatedAt($now);
                $mapping->setUpdatedAt($now);
                $newMappings[] = $this->mappingMapper->insert($mapping);
            } else {
                // Update balance
                $existing->setLastBalance($account['balance'] ?? null);
                $existing->setLastCurrency($account['currency'] ?? null);
                $existing->setUpdatedAt($now);
                $this->mappingMapper->update($existing);
            }
        }

        return $this->mappingMapper->findByConnection($connectionId);
    }

    private function requireEnabled(): void {
        if (!$this->adminSettings->isBankSyncEnabled()) {
            throw new \Exception('Bank sync is disabled by the administrator');
        }
    }
}
