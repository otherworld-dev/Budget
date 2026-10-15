<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob\Queued;

use OCA\Budget\Service\AdminSettingService;
use OCA\Budget\Service\BankSync\BankSyncService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;

/**
 * Sync one bank connection, queued by BankSyncJob. One provider round trip
 * per job, so a slow or hanging bank API holds up this connection alone
 * rather than every connection after it in a single cron slot.
 *
 * Not registered in info.xml: a queued job runs once per argument it is
 * added with, and BankSyncJob adds it.
 */
class BankSyncConnectionJob extends QueuedJob {
	public function __construct(
		ITimeFactory $time,
		private AdminSettingService $adminSettings,
		private BankSyncService $syncService,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
	}

	protected function run($argument): void {
		$userId = is_array($argument) ? (string)($argument['userId'] ?? '') : '';
		$connectionId = is_array($argument) ? (int)($argument['connectionId'] ?? 0) : 0;
		if ($userId === '' || $connectionId <= 0) {
			return;
		}

		// The admin may have switched bank sync off since this was queued
		if (!$this->adminSettings->isBankSyncEnabled()) {
			return;
		}

		try {
			$this->syncService->sync($userId, $connectionId);
		} catch (\Exception $e) {
			$this->logger->warning(
				"Bank sync failed for connection {$connectionId}: " . $e->getMessage(),
				['app' => 'budget', 'userId' => $userId, 'connectionId' => $connectionId]
			);
		}
	}
}
