<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Background job to clean up old import files.
 *
 * Import files are stored temporarily during the import process.
 * Files older than 24 hours are considered abandoned and are deleted.
 */
class CleanupImportFilesJob extends TimedJob {
    private const MAX_AGE_HOURS = 24;

    private IAppData $appData;
    private LoggerInterface $logger;

    public function __construct(
        ITimeFactory $time,
        IAppData $appData,
        LoggerInterface $logger
    ) {
        parent::__construct($time);
        $this->appData = $appData;
        $this->logger = $logger;

        // Run every 6 hours
        $this->setInterval(6 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        try {
            $importsFolder = $this->appData->getFolder('imports');
        } catch (NotFoundException $e) {
            // No imports folder - nothing to clean
            return;
        }

        $cutoffTime = time() - (self::MAX_AGE_HOURS * 60 * 60);
        $deletedCount = 0;
        $errorCount = 0;

        try {
            $files = $importsFolder->getDirectoryListing();

            foreach ($files as $file) {
                try {
                    $mtime = $file->getMTime();

                    if ($mtime < $cutoffTime) {
                        $file->delete();
                        $deletedCount++;
                    }
                } catch (\Exception $e) {
                    $errorCount++;
                    $this->logger->warning(
                        'Failed to delete import file: ' . $e->getMessage(),
                        [
                            'app' => 'budget',
                            'exception' => $e,
                        ]
                    );
                }
            }

            if ($deletedCount > 0 || $errorCount > 0) {
                $this->logger->info(
                    "Import file cleanup completed: {$deletedCount} deleted, {$errorCount} errors",
                    ['app' => 'budget']
                );
            }
        } catch (\Exception $e) {
            $this->logger->error(
                'Import file cleanup job failed: ' . $e->getMessage(),
                [
                    'app' => 'budget',
                    'exception' => $e,
                ]
            );
        }
    }
}
