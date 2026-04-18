<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Background job to sync transactions from bank connections daily.
 * Checks admin toggle before running. Skips connections that are
 * in error/expired state.
 */
class BankSyncJob extends TimedJob {
    public function __construct(ITimeFactory $time) {
        parent::__construct($time);

        // Run once per day
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $adminSettings = Server::get(AdminSettingService::class);
        $logger = Server::get(LoggerInterface::class);

        if (!$adminSettings->isBankSyncEnabled()) {
            return;
        }

        $syncService = Server::get(BankSyncService::class);
        $connectionMapper = Server::get(BankConnectionMapper::class);

        try {
            $connections = $connectionMapper->findActiveForSync();

            $syncCount = 0;
            $errorCount = 0;

            foreach ($connections as $connection) {
                try {
                    $syncService->sync($connection->getUserId(), $connection->getId());
                    $syncCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    $logger->warning(
                        "Bank sync failed for connection {$connection->getId()}: " . $e->getMessage(),
                        ['app' => 'budget', 'userId' => $connection->getUserId(), 'connectionId' => $connection->getId()]
                    );
                }
            }

            $logger->info(
                "Bank sync job completed: {$syncCount} connections synced" .
                    ($errorCount > 0 ? ", {$errorCount} errors" : ""),
                ['app' => 'budget']
            );
        } catch (\Exception $e) {
            $logger->error(
                'Bank sync job failed: ' . $e->getMessage(),
                ['app' => 'budget', 'exception' => $e]
            );
        }
    }
}
