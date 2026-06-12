<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\Service\AnomalyDetectionService;
use OCA\Budget\Service\DigestService;
use OCA\Budget\Service\SettingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Daily job for digests and anomaly alerts.
 *
 * Digest scheduling uses PERIOD KEYS, not timestamps: each run computes the
 * current ISO week (weekly) or month (monthly); when it differs from the
 * stored `digest_last_period`, the digest for the period that just ended is
 * sent and the key updated. Cron downtime self-heals on the next run and
 * a period is never sent twice.
 *
 * Anomaly alerts run for every user with `anomaly_alerts_enabled` (their
 * own per-category-per-month suppression lives in the service).
 */
class DigestJob extends TimedJob {

    public function __construct(ITimeFactory $time) {
        parent::__construct($time);

        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $db = Server::get(IDBConnection::class);
        $settingService = Server::get(SettingService::class);
        $digestService = Server::get(DigestService::class);
        $anomalyService = Server::get(AnomalyDetectionService::class);
        $logger = Server::get(LoggerInterface::class);

        $digestsSent = 0;
        $anomalyUsers = 0;

        // Digests — only users who opted in are visited
        foreach ($this->getOptedInUserIds($db, 'digest_enabled') as $userId) {
            try {
                $frequency = $settingService->get($userId, 'digest_frequency') === 'monthly' ? 'monthly' : 'weekly';
                $currentPeriod = $frequency === 'monthly' ? date('Y-m') : date('o-\WW');

                if ($settingService->get($userId, 'digest_last_period') === $currentPeriod) {
                    continue;
                }

                $digestService->sendDigest($userId, $frequency);
                $settingService->set($userId, 'digest_last_period', $currentPeriod);
                $digestsSent++;
            } catch (\Exception $e) {
                $logger->warning("Digest failed for {$userId}: " . $e->getMessage(), ['app' => 'budget']);
            }
        }

        // Anomaly alerts (default on — enabled unless explicitly 'false')
        foreach ($this->getAnomalyUserIds($db) as $userId) {
            try {
                if ($settingService->get($userId, 'anomaly_alerts_enabled') === 'false') {
                    continue;
                }
                $anomalyService->detectAndNotify($userId);
                $anomalyUsers++;
            } catch (\Exception $e) {
                $logger->warning("Anomaly detection failed for {$userId}: " . $e->getMessage(), ['app' => 'budget']);
            }
        }

        $logger->info("Digest job completed: {$digestsSent} digests sent, {$anomalyUsers} users checked for anomalies", ['app' => 'budget']);
    }

    /**
     * Users whose setting $key is 'true'.
     *
     * @return string[]
     */
    private function getOptedInUserIds(IDBConnection $db, string $key): array {
        $qb = $db->getQueryBuilder();
        $qb->selectDistinct('user_id')
            ->from('budget_settings')
            ->where($qb->expr()->eq('key', $qb->createNamedParameter($key)))
            ->andWhere($qb->expr()->eq('value', $qb->createNamedParameter('true')));

        $result = $qb->executeQuery();
        $userIds = array_map(fn($row) => (string) $row['user_id'], $result->fetchAll());
        $result->closeCursor();
        return $userIds;
    }

    /**
     * Anomaly alerts default to ON, so enumerate everyone who uses the app
     * (distinct owners of accounts); the explicit 'false' opt-out is checked
     * per user in run().
     *
     * @return string[]
     */
    private function getAnomalyUserIds(IDBConnection $db): array {
        $qb = $db->getQueryBuilder();
        $qb->selectDistinct('user_id')->from('budget_accounts');

        $result = $qb->executeQuery();
        $userIds = array_map(fn($row) => (string) $row['user_id'], $result->fetchAll());
        $result->closeCursor();
        return $userIds;
    }
}
