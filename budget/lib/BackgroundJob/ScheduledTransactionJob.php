<?php

declare(strict_types=1);

namespace OCA\Budget\BackgroundJob;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountBalanceCalculator;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Background job to transition scheduled transactions to cleared
 * when their date has arrived.
 *
 * Runs every 6 hours as a cleanup mechanism. Reports are already
 * self-correcting (they include scheduled transactions whose date
 * has passed), so this job just ensures data consistency.
 */
class ScheduledTransactionJob extends TimedJob {
    public function __construct(ITimeFactory $time) {
        parent::__construct($time);

        // Run every 6 hours
        $this->setInterval(6 * 60 * 60);
        $this->setTimeSensitivity(\OCP\BackgroundJob\IJob::TIME_INSENSITIVE);
    }

    protected function run($argument): void {
        $mapper = Server::get(TransactionMapper::class);
        $accountMapper = Server::get(AccountMapper::class);
        $balanceCalculator = Server::get(AccountBalanceCalculator::class);
        $logger = Server::get(LoggerInterface::class);

        try {
            $transactions = $mapper->findScheduledDueForTransition();
            $count = 0;
            $now = date('Y-m-d H:i:s');

            $touchedAccounts = [];
            foreach ($transactions as $transaction) {
                try {
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
