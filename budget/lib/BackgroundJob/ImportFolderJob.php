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
 * Nothing is remembered between runs: a file that has been handled is moved
 * out of its folder, so what is still there is what is still to do. Each
 * user takes a limited number of files per run (ImportFolderService), so one
 * person's backlog cannot hold up everyone else's.
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
