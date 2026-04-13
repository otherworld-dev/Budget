<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Recalculate all account balances to exclude scheduled transactions.
 *
 * Previously, scheduled (future) transactions were incorrectly included in
 * the stored account balance. This migration recalculates each account's
 * balance as: opening_balance + SUM(cleared transaction effects).
 *
 * Fixes: https://github.com/otherworld-dev/budget/issues/115
 */
class Version001000043Date20260408 extends SimpleMigrationStep {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Get all accounts
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'opening_balance')
			->from('budget_accounts');
		$result = $qb->executeQuery();
		$accounts = $result->fetchAll();
		$result->closeCursor();

		$updated = 0;
		foreach ($accounts as $account) {
			$accountId = (int) $account['id'];
			$openingBalance = (string) ($account['opening_balance'] ?? '0');

			// Sum only non-scheduled transactions
			$qb2 = $this->db->getQueryBuilder();
			$qb2->selectAlias(
					$qb2->createFunction(
						'COALESCE(SUM(CASE WHEN t.type = \'credit\' THEN t.amount ELSE -t.amount END), 0)'
					),
					'net_change'
				)
				->from('budget_transactions', 't')
				->where($qb2->expr()->eq('t.account_id', $qb2->createNamedParameter($accountId)))
				->andWhere(
					$qb2->expr()->orX(
						$qb2->expr()->neq('t.status', $qb2->createNamedParameter('scheduled')),
						$qb2->expr()->isNull('t.status')
					)
				);
			$netResult = $qb2->executeQuery();
			$netChange = (string) ($netResult->fetchOne() ?: '0');
			$netResult->closeCursor();

			$newBalance = number_format((float) $openingBalance + (float) $netChange, 2, '.', '');

			// Update the account balance
			$qb3 = $this->db->getQueryBuilder();
			$qb3->update('budget_accounts')
				->set('balance', $qb3->createNamedParameter($newBalance))
				->where($qb3->expr()->eq('id', $qb3->createNamedParameter($accountId)));
			$qb3->executeStatement();
			$updated++;
		}

		$output->info("Recalculated balances for {$updated} accounts (excluding scheduled transactions).");
	}
}
