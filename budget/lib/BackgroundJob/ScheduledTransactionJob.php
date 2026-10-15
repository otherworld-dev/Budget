<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountBalanceCalculator;
use OCA\Budget\Service\UserClock;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Background job to transition scheduled transactions to cleared
 * when their date has arrived on the account owner's calendar.
 *
 * Reports are already self-correcting (they include scheduled transactions
 * whose date has passed), but balances count cleared rows only, so it runs
 * hourly: a row clears within the hour of its owner's midnight. On the
 * server's UTC date, Los Angeles saw tomorrow's payment in its balance every
 * evening, and Auckland waited until noon for today's (#399 review, F90).
 */
class ScheduledTransactionJob extends TimedJob {
	public function __construct(ITimeFactory $time) {
		parent::__construct($time);

		$this->setInterval(60 * 60);
		$this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		$mapper = Server::get(TransactionMapper::class);
		$accountMapper = Server::get(AccountMapper::class);
		$balanceCalculator = Server::get(AccountBalanceCalculator::class);
		$logger = Server::get(LoggerInterface::class);
		$clock = Server::get(UserClock::class);

		try {
			// UTC+14 is the furthest ahead anyone is: a day past UTC at most
			$latest = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
			$transactions = $mapper->findScheduledDueForTransition($latest);
			$count = 0;
			$now = date('Y-m-d H:i:s');

			$touchedAccounts = [];
			$todayFor = [];
			foreach ($transactions as $transaction) {
				try {
					$accountId = $transaction->getAccountId();
					if (!array_key_exists($accountId, $todayFor)) {
						$owner = $accountMapper->findById($accountId)->getUserId();
						$todayFor[$accountId] = $clock instanceof UserClock ? $clock->today($owner) : date('Y-m-d');
					}
					if ($transaction->getDate() > $todayFor[$accountId]) {
						continue;
					}

					$transaction->setStatus('cleared');
					$transaction->setUpdatedAt($now);
					$mapper->update($transaction);
					$touchedAccounts[$transaction->getAccountId()] = true;

					$count++;
				} catch (\Exception $e) {
					$logger->warning('Failed to transition scheduled transaction {id}: {error}', [
						'id' => $transaction->getId(),
						'error' => $e->getMessage(),
						'app' => 'budget',
					]);
				}
			}

			// Recompute balances from the ledger (cleared rows now count).
			// No hand-computed deltas: a read-modify-write here could race a
			// concurrent import/sync recompute and reintroduce drift (#274).
			foreach (array_keys($touchedAccounts) as $accountId) {
				try {
					// At the account currency's scale: a crypto account must
					// keep its 8dp when a scheduled row clears (#331).
					$balanceCalculator->recalculate($accountMapper->findById($accountId));
				} catch (\Exception $e) {
					$logger->warning('Failed to recalculate balance for account {id}: {error}', [
						'id' => $accountId,
						'error' => $e->getMessage(),
						'app' => 'budget',
					]);
				}
			}

			if ($count > 0) {
				$logger->info('Transitioned {count} scheduled transaction(s) to cleared', [
					'count' => $count,
					'app' => 'budget',
				]);
			}
		} catch (\Exception $e) {
			$logger->error('ScheduledTransactionJob failed: {error}', [
				'error' => $e->getMessage(),
				'app' => 'budget',
			]);
		}
	}
}
