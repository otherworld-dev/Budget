<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * One user with at least one row in EVERY budget_* table, wired together the
 * way the app wires them (splits on a split parent, a linked transfer pair, a
 * bill-paid transaction ticked into a reconciliation session and funding a
 * pension contribution, JSON columns holding account ids, ...).
 *
 * DataModel::TABLE_SCOPES lists every budget table the suite knows. A new
 * table missing from it fails MigrationRoundTripTest, which is the point: it
 * has to be classified there and seeded here, and then the backup round trip
 * and factory reset tests cover it.
 */
trait FullDataset {
	/**
	 * Seed one row or more in every table for $userId.
	 *
	 * @return array<string, int> named ids of the rows other assertions need
	 */
	protected function seedEveryTable(string $userId): array {
		$now = $this->now();
		$ids = [];

		$ids['food'] = $this->makeCategory(['name' => 'Food'], $userId);
		$ids['takeaway'] = $this->makeCategory(['name' => 'Takeaway', 'parent_id' => $ids['food']], $userId);
		$ids['current'] = $this->makeAccount([
			'name' => 'Current',
			'accountNumber' => '12345678',
			'sortCode' => '12-34-56',
			'iban' => 'GB33BUKB20201555555555',
			'institution' => 'Test Bank',
			'balance' => 950.0,
		], $userId)->getId();
		$ids['card'] = $this->makeAccount(['name' => 'Card', 'type' => 'credit_card', 'balance' => -120.0, 'liabilityInCredit' => false], $userId)->getId();

		$ids['tag_set'] = $this->makeTagSet($ids['food']);
		$ids['tag'] = $this->makeTag($ids['tag_set'], $userId);

		$ids['bill'] = $this->insertRow('budget_bills', [
			'user_id' => $userId, 'name' => 'Rent', 'amount' => '800.00', 'frequency' => 'monthly',
			'account_id' => $ids['current'], 'category_id' => $ids['food'], 'is_active' => true,
			'created_at' => $now, 'due_day' => 1,
		]);
		$ids['recon'] = $this->insertRow('budget_recon_sessions', [
			'account_id' => $ids['current'], 'user_id' => $userId, 'statement_date' => '2026-03-31',
			'statement_balance' => '950.00', 'starting_balance' => '1000.00', 'status' => 'completed',
			'reconciled_count' => 1, 'created_at' => $now,
		]);
		$ids['pension'] = $this->insertRow('budget_pensions', [
			'user_id' => $userId, 'name' => 'Workplace', 'type' => 'workplace', 'currency' => 'GBP',
			'current_balance' => '5000.00', 'created_at' => $now, 'updated_at' => $now,
		]);

		$ids['tx_plain'] = $this->makeTransaction($ids['current'], ['category_id' => $ids['food'], 'amount' => '12.50', 'import_id' => 'csv:1']);
		$ids['tx_split'] = $this->makeSplitTransaction($ids['current'], [[$ids['food'], '6.00'], [$ids['takeaway'], '4.00']]);
		$ids['tx_out'] = $this->makeTransaction($ids['current'], ['amount' => '50.00', 'description' => 'Card payment']);
		$ids['tx_in'] = $this->makeTransaction($ids['card'], ['amount' => '50.00', 'type' => 'credit', 'description' => 'Card payment', 'linked_transaction_id' => $ids['tx_out']]);
		$this->db()->executeStatement(
			'UPDATE *PREFIX*budget_transactions SET linked_transaction_id = ? WHERE id = ?',
			[$ids['tx_in'], $ids['tx_out']]
		);
		$ids['pen_contrib'] = $this->insertRow('budget_pen_contribs', [
			'user_id' => $userId, 'pension_id' => $ids['pension'], 'amount' => '100.00', 'date' => '2026-03-20',
			'created_at' => $now, 'source_account_id' => $ids['current'], 'kind' => 'contribution',
		]);
		$ids['tx_bill'] = $this->makeTransaction($ids['current'], [
			'amount' => '800.00', 'description' => 'Rent', 'bill_id' => $ids['bill'],
			'recon_session_id' => $ids['recon'], 'reconciled' => true,
		]);
		$ids['tx_pension'] = $this->makeTransaction($ids['current'], [
			'amount' => '100.00', 'description' => 'Pension', 'pension_contrib_id' => $ids['pen_contrib'],
		]);
		$this->db()->executeStatement(
			'UPDATE *PREFIX*budget_pen_contribs SET transaction_id = ? WHERE id = ?',
			[$ids['tx_pension'], $ids['pen_contrib']]
		);

		$this->tagTransaction($ids['tx_plain'], $ids['tag']);
		$ids['contact'] = $this->makeContact($userId);
		$this->makeExpenseShare($ids['tx_plain'], $ids['contact'], $userId);
		$this->makeAttachment($ids['tx_plain'], $userId);

		$this->insertRow('budget_import_rules', [
			'user_id' => $userId, 'name' => 'Tesco', 'pattern' => 'TESCO', 'field' => 'description',
			'match_type' => 'contains', 'category_id' => $ids['food'], 'priority' => 0, 'active' => true,
			'created_at' => $now, 'schema_version' => 1,
		]);
		$this->insertRow('budget_settings', [
			'user_id' => $userId, 'key' => 'default_currency', 'value' => 'GBP', 'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_recurring_income', [
			'user_id' => $userId, 'name' => 'Salary', 'amount' => '2500.00', 'frequency' => 'monthly',
			'account_id' => $ids['current'], 'category_id' => $ids['food'], 'created_at' => $now, 'is_active' => true,
		]);
		$this->insertRow('budget_savings_goals', [
			'user_id' => $userId, 'name' => 'Holiday', 'target_amount' => '1000.00', 'current_amount' => '100.00',
			'created_at' => $now, 'tag_id' => $ids['tag'], 'account_id' => $ids['current'],
		]);
		$ids['asset'] = $this->insertRow('budget_assets', [
			'user_id' => $userId, 'name' => 'Car', 'type' => 'vehicle', 'currency' => 'GBP',
			'current_value' => '8000.00', 'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_asset_snaps', [
			'user_id' => $userId, 'asset_id' => $ids['asset'], 'value' => '8000.00', 'date' => '2026-03-01', 'created_at' => $now,
		]);
		$this->insertRow('budget_pen_recur', [
			'user_id' => $userId, 'pension_id' => $ids['pension'], 'amount' => '100.00', 'frequency' => 'monthly',
			'source_account_id' => $ids['current'], 'next_due_date' => '2026-04-20', 'is_active' => true,
			'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_pen_snaps', [
			'user_id' => $userId, 'pension_id' => $ids['pension'], 'balance' => '5000.00', 'date' => '2026-03-01', 'created_at' => $now,
		]);
		$this->insertRow('budget_interest_rates', [
			'account_id' => $ids['current'], 'user_id' => $userId, 'rate' => '1.5000', 'compounding_frequency' => 'monthly',
			'effective_date' => '2026-01-01', 'created_at' => $now,
		]);
		$this->insertRow('budget_manual_rates', [
			'user_id' => $userId, 'currency' => 'XAU', 'rate_per_eur' => '0.0004', 'updated_at' => $now,
		]);
		$this->insertRow('budget_import_templates', [
			'user_id' => $userId, 'name' => 'Bank CSV', 'mapping' => '{"date":0}', 'delimiter' => ',',
			'created_at' => $now, 'format' => 'csv', 'account_id' => $ids['current'],
			'account_mapping' => json_encode(['1234' => $ids['current']]),
		]);
		$this->insertRow('budget_imp_links', [
			'user_id' => $userId, 'format' => 'ofx', 'source_key' => 'acct-1234', 'budget_account_id' => $ids['current'], 'updated_at' => $now,
		]);
		$this->insertRow('budget_saved_reports', [
			'user_id' => $userId, 'name' => 'Monthly', 'config' => '{"type":"summary"}', 'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_nw_snaps', [
			'user_id' => $userId, 'total_assets' => '9000.00', 'total_liabilities' => '120.00', 'net_worth' => '8880.00',
			'date' => '2026-03-01', 'source' => 'auto', 'created_at' => $now,
		]);
		$this->insertRow('budget_bgt_snapshots', [
			'user_id' => $userId, 'category_id' => $ids['food'], 'effective_from' => '2026-01', 'amount' => '300.00',
			'period' => 'monthly', 'created_at' => $now,
		]);
		$this->insertRow('budget_dscn', [
			'user_id' => $userId, 'name' => 'Plan', 'strategy' => 'avalanche', 'extra_payment' => '50.00', 'lump_sum' => '0.00',
			'lump_sum_month' => 1, 'selected_debt_ids' => json_encode([$ids['card']]),
			'rate_overrides' => json_encode([(string)$ids['card'] => 19.9]), 'original_total_debt' => '120.00',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_cat_mutes', ['user_id' => $userId, 'category_id' => $ids['takeaway'], 'created_at' => $now]);
		$this->insertRow('budget_settlements', [
			'user_id' => $userId, 'contact_id' => $ids['contact'], 'amount' => '5.00', 'date' => '2026-03-25', 'created_at' => $now,
		]);
		$this->insertRow('budget_dismissed_sugg', [
			'user_id' => $userId, 'suggestion_type' => 'bill', 'pattern_hash' => md5('netflix'), 'pattern' => 'netflix', 'dismissed_at' => $now,
		]);
		$this->insertRow('budget_dismiss_imp', ['account_id' => $ids['current'], 'import_id' => 'simplefin:abc', 'dismissed_at' => $now]);
		$ids['project'] = $this->insertRow('budget_projects', [
			'user_id' => $userId, 'name' => 'Kitchen', 'category_id' => $ids['food'], 'total_amount' => '5000.00',
			'start_date' => '2026-01-01', 'created_at' => $now,
		]);
		$this->insertRow('budget_project_allocs', [
			'user_id' => $userId, 'project_id' => $ids['project'], 'category_id' => $ids['takeaway'], 'amount' => '500.00',
		]);

		// Deliberately never exported (instance state, other users, provider
		// credentials, files) - seeded so the tests can prove that too
		$this->insertRow('budget_audit_log', ['user_id' => $userId, 'action' => 'test', 'created_at' => $now]);
		$this->insertRow('budget_idem_keys', ['user_id' => $userId, 'idem_key' => bin2hex(random_bytes(8)), 'transaction_id' => $ids['tx_plain'], 'created_at' => $now]);
		$bc = $this->insertRow('budget_bc', [
			'user_id' => $userId, 'provider' => 'simplefin', 'name' => 'Bank', 'credentials' => 'secret', 'status' => 'active',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_bam', [
			'connection_id' => $bc, 'external_account_id' => 'ext-1', 'budget_account_id' => $ids['current'],
			'enabled' => true, 'created_at' => $now, 'updated_at' => $now,
		]);
		$share = $this->insertRow('budget_shares', [
			'owner_user_id' => $userId, 'shared_with_user_id' => $this->newUserId(), 'status' => 'accepted',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_share_items', [
			'share_id' => $share, 'entity_type' => 'account', 'entity_id' => $ids['current'], 'permission' => 'read',
			'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_share_auto', [
			'share_id' => $share, 'entity_type' => 'account', 'permission' => 'read', 'created_at' => $now, 'updated_at' => $now,
		]);
		$this->insertRow('budget_forecasts', [
			'user_id' => $userId, 'account_id' => $ids['current'], 'name' => 'Legacy', 'based_on_months' => 3,
			'forecast_months' => 6, 'created_at' => $now, 'updated_at' => $now,
		]);

		return $ids;
	}

	/**
	 * Rows belonging to $userId in $table, per TABLE_SCOPES.
	 */
	protected function countUserRows(string $table, string $userId): int {
		$scope = DataModel::TABLE_SCOPES[$table];
		if ($scope === null) {
			return 0;
		}
		$qb = $this->db()->getQueryBuilder();
		$qb->select($qb->func()->count('t.id'))->from($table, 't');
		if (is_string($scope)) {
			$qb->where($qb->expr()->eq('t.' . $scope, $qb->createNamedParameter($userId)));
		} else {
			$prev = 't';
			$alias = 't';
			$userColumn = 'user_id';
			foreach ($scope as $i => $join) {
				[$joinTable, $column] = $join;
				$userColumn = $join[2] ?? 'user_id';
				$alias = 'j' . $i;
				$qb->innerJoin($prev, $joinTable, $alias, $qb->expr()->eq($prev . '.' . $column, $alias . '.id'));
				$prev = $alias;
			}
			$qb->where($qb->expr()->eq($alias . '.' . $userColumn, $qb->createNamedParameter($userId)));
		}
		$result = $qb->executeQuery();
		$count = (int)$result->fetchOne();
		$result->closeCursor();
		return $count;
	}

	/**
	 * @return array<string, int> table => this user's row count
	 */
	protected function countEveryTable(string $userId): array {
		$counts = [];
		foreach (array_keys(DataModel::TABLE_SCOPES) as $table) {
			$counts[$table] = $this->countUserRows($table, $userId);
		}
		return $counts;
	}

	/**
	 * Every reference in REFERENCES that points at a missing row, as
	 * "child.column" => count. Empty when the database is consistent.
	 *
	 * @return array<string, int>
	 */
	protected function danglingReferences(): array {
		$dangling = [];
		foreach (DataModel::REFERENCES as $child => $columns) {
			foreach ($columns as $column => $parent) {
				$qb = $this->db()->getQueryBuilder();
				$qb->select($qb->func()->count('c.id'))
					->from($child, 'c')
					->leftJoin('c', $parent, 'p', $qb->expr()->eq('c.' . $column, 'p.id'))
					->where($qb->expr()->isNotNull('c.' . $column))
					->andWhere($qb->expr()->neq('c.' . $column, $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->isNull('p.id'));
				$result = $qb->executeQuery();
				$n = (int)$result->fetchOne();
				$result->closeCursor();
				if ($n > 0) {
					$dangling[$child . '.' . $column] = $n;
				}
			}
		}
		return $dangling;
	}
}
