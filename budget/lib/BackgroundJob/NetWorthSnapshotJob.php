<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\Db\NetWorthSnapshot;
use OCA\Budget\Service\NetWorthService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Background job to create daily net worth snapshots for all users.
 *
 * Runs once per day and creates a snapshot for each user who has
 * at least one account, recording their total assets, liabilities,
 * and net worth.
 */
class NetWorthSnapshotJob extends TimedJob {
    public function __construct(ITimeFactory $time) {
        parent::__construct($time);

        // Run once per day
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $netWorthService = Server::get(NetWorthService::class);
        $db = Server::get(IDBConnection::class);
        $logger = Server::get(LoggerInterface::class);

        try {
            // Get all unique user IDs who have accounts
            $userIds = $this->getAllUserIds($db);

            $snapshotCount = 0;
            $errorCount = 0;

            foreach ($userIds as $userId) {
                try {
                    $netWorthService->createSnapshot(
                        $userId,
                        NetWorthSnapshot::SOURCE_AUTO
                    );
                    $snapshotCount++;
                } catch (\Exception $e) {
                    $errorCount++;
                    $logger->warning(
                        "Failed to create net worth snapshot for user {$userId}: " . $e->getMessage(),
                        ['app' => 'budget', 'userId' => $userId]
                    );
                }
            }

            $logger->info(
                "Net worth snapshot job completed: {$snapshotCount} snapshots created" .
                    ($errorCount > 0 ? ", {$errorCount} errors" : ""),
                ['app' => 'budget']
            );
        } catch (\Exception $e) {
            $logger->error(
                'Net worth snapshot job failed: ' . $e->getMessage(),
                [
                    'app' => 'budget',
                    'exception' => $e,
                ]
            );
        }
    }

    /**
     * Get all unique user IDs from the accounts table.
     *
     * @return string[]
     */
    private function getAllUserIds(IDBConnection $db): array {
        $qb = $db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from('budget_accounts');

        $result = $qb->executeQuery();
        $userIds = [];
        while ($row = $result->fetch()) {
            $userIds[] = $row['user_id'];
        }
        $result->closeCursor();

        return $userIds;
    }
}
