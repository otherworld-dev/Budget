<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\Db\AuditLogMapper;
use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

/**
 * Background job to clean up old audit logs.
 *
 * Audit logs older than the configured retention period are deleted
 * to prevent unbounded database growth while maintaining compliance.
 */
class CleanupAuditLogsJob extends TimedJob {
    /**
     * Default retention period in days.
     * Logs older than this will be deleted.
     */
    private const DEFAULT_RETENTION_DAYS = 90;

    private AuditLogMapper $auditLogMapper;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        AuditLogMapper $auditLogMapper,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->auditLogMapper = $auditLogMapper;
        $this->logger = $logger;

        // Run once per day
        $this->setInterval(24 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        try {
            $retentionDays = self::DEFAULT_RETENTION_DAYS;

            $deletedCount = $this->auditLogMapper->deleteOldLogs($retentionDays);

            if ($deletedCount > 0) {
                $this->logger->info(
                    "Audit log cleanup completed: {$deletedCount} records deleted (older than {$retentionDays} days)",
                    ['app' => 'budget']
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Audit log cleanup job failed: ' . $e->getMessage(),
                [
                    'app' => 'budget',
                    'exception' => $e,
                ]
            );
        }
    }
}
