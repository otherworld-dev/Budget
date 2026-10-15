<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\BackgroundJob\Support\JobUsers;
use OCA\Budget\Service\ImportFolderService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Imports the statements waiting in users' import folders (#425), every
 * fifteen minutes.
 *
 * Little is remembered between runs: a file that has been handled is moved
 * out of its folder, so what is still there is what is still to do. Each
 * user takes a limited number of files per run (ImportFolderService), so one
 * person's backlog cannot hold up everyone else's. A file whose reading
 * kills the run (PHP out of memory or time, which nothing can catch) costs
 * that one run: the service sets it aside on the next, and its owner is
 * taken last until then.
 */
class ImportFolderJob extends TimedJob {

	public function __construct(ITimeFactory $time) {
		parent::__construct($time);

		$this->setInterval(15 * 60);
	}

	protected function run($argument): void {
		$service = Server::get(ImportFolderService::class);
		$logger = Server::get(LoggerInterface::class);

		// Everyone with a folder row; which rows are switched on is the service's to say
		$userIds = (new JobUsers(Server::get(IDBConnection::class)))->from('budget_import_folders');
		// Those with a file still recorded as being read go last: most likely
		// a file that stopped the last run, which may stop this one too
		// before it is set aside. Everyone else's files go first
		try {
			$last = $service->interrupted($userIds);
			$userIds = array_merge(array_values(array_diff($userIds, $last)), $last);
		} catch (\Throwable $e) {
			$logger->warning('Import folder job: the order could not be worked out: ' . $e->getMessage(), ['app' => 'budget', 'exception' => $e]);
		}

		$imported = 0;
		$failed = 0;
		$review = 0;
		foreach ($userIds as $userId) {
			try {
				$summary = $service->check($userId);
				$imported += $summary['imported'];
				$failed += $summary['failed'];
				$review += $summary['review'];
			} catch (\Throwable $e) {
				$logger->warning("Import folder check failed for {$userId}: " . $e->getMessage(), ['app' => 'budget', 'exception' => $e]);
			}
		}

		if ($imported > 0 || $failed > 0 || $review > 0) {
			$logger->info("Import folder job: {$imported} files imported, {$failed} failed, {$review} held for review", ['app' => 'budget']);
		}
	}
}
