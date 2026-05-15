<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Background job to sync transactions from bank connections daily.
 * Checks admin toggle before running. Skips connections that are
 * in error/expired state.
 */
class BankSyncJob extends TimedJob {
    public function __construct(
        ITimeFactory $time,
        private AdminSettingService $adminSettings,
        private BankSyncService $syncService,
        private BankConnectionMapper $connectionMapper,
        private LoggerInterface $logger
    ) {
        parent::__construct($time);

        // Run once per day
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        if (!$this->adminSettings->isBankSyncEnabled()) {
            return;
        }

        try {
            // Fetch only IDs to avoid decrypting all credentials into memory at once
            $connectionRefs = $this->connectionMapper->findActiveIdsForSync();

            $syncCount = 0;
            $errorCount = 0;

            foreach ($connectionRefs as $ref) {
                try {
                    $this->syncService->sync($ref['userId'], $ref['id']);
                    $syncCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->logger->warning(
                        "Bank sync failed for connection {$ref['id']}: " . $e->getMessage(),
                        ['app' => 'budget', 'userId' => $ref['userId'], 'connectionId' => $ref['id']]
                    );
                }
            }

            $this->logger->info(
                "Bank sync job completed: {$syncCount} connections synced" .
                    ($errorCount > 0 ? ", {$errorCount} errors" : ""),
                ['app' => 'budget']
            );
        } catch (\Exception $e) {
            $this->logger->error(
                'Bank sync job failed: ' . $e->getMessage(),
                ['app' => 'budget', 'exception' => $e]
            );
        }
    }
}
