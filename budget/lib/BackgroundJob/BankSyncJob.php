<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\BackgroundJob\Queued\BankSyncConnectionJob;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\AdminSettingService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Daily fan-out of bank syncs. Checks the admin toggle, then queues one
 * BankSyncConnectionJob per connection due a sync (active, or in a
 * retryable error state).
 *
 * Calling every provider in turn from this one job had no time budget: a
 * few slow bank APIs could hold a cron slot for as long as they liked, and
 * the connections after them waited. Queued, each connection is its own
 * job and cron spreads them over its runs.
 *
 * A connection whose job from an earlier day is still waiting keeps its
 * place in the queue rather than being re-added (IJobList::add() on an
 * existing job moves it to the back), so a backlog can never starve the
 * same connections day after day.
 */
class BankSyncJob extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private AdminSettingService $adminSettings,
		private BankConnectionMapper $connectionMapper,
		private IJobList $jobList,
		private LoggerInterface $logger,
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
			// Only ids: credentials are decrypted by each connection's own job
			$queued = 0;
			$waiting = 0;
			foreach ($this->connectionMapper->findActiveIdsForSync() as $ref) {
				$jobArgument = ['userId' => $ref['userId'], 'connectionId' => $ref['id']];
				if ($this->jobList->has(BankSyncConnectionJob::class, $jobArgument)) {
					$waiting++;
					continue;
				}
				$this->jobList->add(BankSyncConnectionJob::class, $jobArgument);
				$queued++;
			}

			$this->logger->info(
				"Bank sync job queued {$queued} connections"
					. ($waiting > 0 ? ", {$waiting} still waiting from an earlier run" : ''),
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
