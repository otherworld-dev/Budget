<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add opening_balance column to budget_accounts.
 * Back-calculates opening_balance for existing accounts from
 * current stored balance minus the net sum of all transactions.
 */
class Version001000041Date20260307 extends SimpleMigrationStep {
	private IDBConnection $db;

	public function __construct(IDBConnection $db) {
		$this->db = $db;
	}

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('budget_accounts')) {
			return null;
		}

		$table = $schema->getTable('budget_accounts');

		if (!$table->hasColumn('opening_balance')) {
			$table->addColumn('opening_balance', Types::DECIMAL, [
				'notnull' => false,
				'precision' => 15,
				'scale' => 2,
				'default' => 0,
			]);
		}

		return $schema;
	}

	/**
	 * Back-calculate opening_balance for existing accounts:
	 * opening_balance = stored_balance - SUM(credits - debits)
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		// Get all accounts
		$qb = $this->db->getQueryBuilder();
		$qb->select('id', 'balance')
			->from('budget_accounts');
		$result = $qb->executeQuery();
		$accounts = $result->fetchAll();
		$result->closeCursor();

		foreach ($accounts as $account) {
			$accountId = (int) $account['id'];
			$storedBalance = (float) $account['balance'];

			// Sum all transactions for this account
			$qb2 = $this->db->getQueryBuilder();
			$qb2->selectAlias(
					$qb2->createFunction(
						'COALESCE(SUM(CASE WHEN t.type = \'credit\' THEN t.amount ELSE -t.amount END), 0)'
					),
					'net_change'
				)
				->from('budget_transactions', 't')
				->where($qb2->expr()->eq('t.account_id', $qb2->createNamedParameter($accountId, \OCP\IDBConnection::PARAM_INT)));

			$txResult = $qb2->executeQuery();
			$netChange = (float) $txResult->fetchOne();
			$txResult->closeCursor();

			$openingBalance = $storedBalance - $netChange;

			// Update the account's opening_balance
			$qb3 = $this->db->getQueryBuilder();
			$qb3->update('budget_accounts')
				->set('opening_balance', $qb3->createNamedParameter(sprintf('%.2f', $openingBalance)))
				->where($qb3->expr()->eq('id', $qb3->createNamedParameter($accountId, \OCP\IDBConnection::PARAM_INT)));
			$qb3->executeStatement();
		}
	}
}
