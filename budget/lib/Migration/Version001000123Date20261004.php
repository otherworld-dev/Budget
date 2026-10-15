<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Give splits with contacts that were stored without a currency the
 * currency of their transaction's account.
 *
 * Splitting a transaction in an account shared with the user 50/50, or with
 * one contact from the transaction list, looked the account up as the user
 * doing it, which finds nothing for someone else's account: the split was
 * stored with no currency, and every balance showed it in US dollars. The
 * lookup is fixed; this fills in the ones already stored, as Version
 * 001000057 did once when the column was added.
 *
 * Only rows still without a currency change, so it is safe to run twice. A
 * split whose transaction is gone keeps none.
 */
class Version001000123Date20261004 extends SimpleMigrationStep {
	public function __construct(
		private IDBConnection $db,
	) {
	}

	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
		$schema = $schemaClosure();
		if (!$schema->hasTable('budget_expense_shares')) {
			return;
		}

		// No table alias on the UPDATE: SQLite and PostgreSQL refuse one in
		// SET. The subqueries read other tables only, so MySQL accepts the
		// correlated reference to the table being updated.
		$updated = $this->db->executeStatement(
			'UPDATE `*PREFIX*budget_expense_shares` SET `currency` = ('
			. 'SELECT a.`currency` FROM `*PREFIX*budget_transactions` t'
			. ' INNER JOIN `*PREFIX*budget_accounts` a ON a.`id` = t.`account_id`'
			. ' WHERE t.`id` = `*PREFIX*budget_expense_shares`.`transaction_id`'
			. ') WHERE `currency` IS NULL AND EXISTS ('
			. 'SELECT 1 FROM `*PREFIX*budget_transactions` t2'
			. ' INNER JOIN `*PREFIX*budget_accounts` a2 ON a2.`id` = t2.`account_id`'
			. ' WHERE t2.`id` = `*PREFIX*budget_expense_shares`.`transaction_id`'
			. ' AND a2.`currency` IS NOT NULL AND a2.`currency` <> \'\')'
		);

		if ($updated > 0) {
			$output->info('Set the currency of ' . $updated . ' split(s) with contacts from their account');
		}
	}
}
