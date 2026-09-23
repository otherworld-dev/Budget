<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\BackgroundJob\Support\JobUsers;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\InterestService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\IDBConnection;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Background job to refresh cached accrued interest for all accounts
 * with interest tracking enabled. Runs once per day.
 */
class InterestAccrualJob extends TimedJob {
	public function __construct(ITimeFactory $time) {
		parent::__construct($time);

		// Run once per day
		$this->setInterval(24 * 60 * 60);
		$this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$interestService = Server::get(InterestService::class);
		$accountMapper = Server::get(AccountMapper::class);
		$db = Server::get(IDBConnection::class);
		$logger = Server::get(LoggerInterface::class);

		try {
			$userIds = $this->getAllUserIds($db);

			$updatedCount = 0;
			$errorCount = 0;

			foreach ($userIds as $userId) {
				try {
					$accounts = $accountMapper->findWithInterestEnabled($userId);
					foreach ($accounts as $account) {
						try {
							$interestService->refreshAccruedInterestCache($account->getId(), $userId);
							$updatedCount++;
						} catch (\Exception $e) {
							$errorCount++;
							$logger->warning(
								"Failed to refresh interest for account {$account->getId()}: " . $e->getMessage(),
								['app' => 'budget', 'userId' => $userId, 'accountId' => $account->getId()]
							);
						}
					}
				} catch (\Exception $e) {
					$errorCount++;
					$logger->warning(
						"Failed to process interest for user {$userId}: " . $e->getMessage(),
						['app' => 'budget', 'userId' => $userId]
					);
				}
			}

			$logger->info(
				"Interest accrual job completed: {$updatedCount} accounts updated"
					. ($errorCount > 0 ? ", {$errorCount} errors" : ''),
				['app' => 'budget']
			);
		} catch (\Exception $e) {
			$logger->error(
				'Interest accrual job failed: ' . $e->getMessage(),
				['app' => 'budget', 'exception' => $e]
			);
		}
	}

	/**
	 * Users with at least one account accruing interest.
	 *
	 * @return string[]
	 */
	private function getAllUserIds(IDBConnection $db): array {
		return (new JobUsers($db))->from('budget_accounts', ['interest_enabled' => true]);
	}
}
