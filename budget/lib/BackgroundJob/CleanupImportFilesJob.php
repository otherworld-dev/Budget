<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCP\BackgroundJob\TimedJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Background job to clean up old import files.
 *
 * Import files are stored temporarily during the import process.
 * Files older than 24 hours are considered abandoned and are deleted.
 */
class CleanupImportFilesJob extends TimedJob {
    private const MAX_AGE_HOURS = 24;

    public function __construct(ITimeFactory $time) {
        parent::__construct($time);

        // Run every 6 hours
        $this->setInterval(6 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $appData = Server::get(IAppData::class);
        $logger = Server::get(LoggerInterface::class);

        try {
            $importsFolder = $appData->getFolder('imports');
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
                    $logger->warning(
                        'Failed to delete import file: ' . $e->getMessage(),
                        [
                            'app' => 'budget',
                            'exception' => $e,
                        ]
                    );
                }
            }

            if ($deletedCount > 0 || $errorCount > 0) {
                $logger->info(
                    "Import file cleanup completed: {$deletedCount} deleted, {$errorCount} errors",
                    ['app' => 'budget']
                );
            }
        } catch (\Exception $e) {
            $logger->error(
                'Import file cleanup job failed: ' . $e->getMessage(),
                [
                    'app' => 'budget',
                    'exception' => $e,
                ]
            );
        }
    }
}
