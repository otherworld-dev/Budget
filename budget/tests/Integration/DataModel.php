<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration;

/**
 * What the integration suite knows about the budget schema. (Constants live
 * here rather than in the FullDataset trait: trait constants need PHP 8.2
 * and the app still supports 8.1.)
 */
final class DataModel {
	/**
	 * How to find a user's rows in each table:
	 *   'user_id' / 'owner_user_id'  the column holding the user id
	 *   list of [table, column] joins, ending at a table with user_id (or
	 *   with the user column named as a third element on the last join)
	 *   null   global table, owned by nobody
	 *
	 * @var array<string, string|list<array{0: string, 1: string, 2?: string}>|null>
	 */
	public const TABLE_SCOPES = [
		'budget_accounts' => 'user_id',
		'budget_asset_snaps' => 'user_id',
		'budget_assets' => 'user_id',
		'budget_attachments' => 'user_id',
		'budget_audit_log' => 'user_id',
		'budget_bam' => [['budget_bc', 'connection_id']],
		'budget_bc' => 'user_id',
		'budget_bgt_snapshots' => 'user_id',
		'budget_bills' => 'user_id',
		'budget_cat_mutes' => 'user_id',
		'budget_categories' => 'user_id',
		'budget_contacts' => 'user_id',
		'budget_dismiss_imp' => [['budget_accounts', 'account_id']],
		'budget_dismissed_sugg' => 'user_id',
		'budget_dscn' => 'user_id',
		'budget_exchange_rates' => null,
		'budget_expense_shares' => 'user_id',
		'budget_forecasts' => 'user_id',
		'budget_idem_keys' => 'user_id',
		'budget_imp_links' => 'user_id',
		'budget_import_rules' => 'user_id',
		'budget_import_templates' => 'user_id',
		'budget_interest_rates' => 'user_id',
		'budget_manual_rates' => 'user_id',
		'budget_nw_snaps' => 'user_id',
		'budget_pen_contribs' => 'user_id',
		'budget_pen_recur' => 'user_id',
		'budget_pen_snaps' => 'user_id',
		'budget_pensions' => 'user_id',
		'budget_project_allocs' => 'user_id',
		'budget_projects' => 'user_id',
		'budget_recon_sessions' => 'user_id',
		'budget_recurring_income' => 'user_id',
		'budget_saved_reports' => 'user_id',
		'budget_savings_goals' => 'user_id',
		'budget_settings' => 'user_id',
		'budget_settlements' => 'user_id',
		'budget_share_auto' => [['budget_shares', 'share_id', 'owner_user_id']],
		'budget_share_items' => [['budget_shares', 'share_id', 'owner_user_id']],
		'budget_shares' => 'owner_user_id',
		'budget_tag_sets' => [['budget_categories', 'category_id']],
		'budget_tags' => 'user_id',
		'budget_transaction_tags' => [['budget_transactions', 'transaction_id'], ['budget_accounts', 'account_id']],
		'budget_transactions' => [['budget_accounts', 'account_id']],
		'budget_tx_splits' => [['budget_transactions', 'transaction_id'], ['budget_accounts', 'account_id']],
	];

	/**
	 * Columns that reference another table's id: child table => [column =>
	 * parent table]. Used to prove a restore or a delete left nothing
	 * pointing at a row that is gone.
	 *
	 * @var array<string, array<string, string>>
	 */
	public const REFERENCES = [
		'budget_tx_splits' => ['transaction_id' => 'budget_transactions', 'category_id' => 'budget_categories'],
		'budget_transaction_tags' => ['transaction_id' => 'budget_transactions', 'tag_id' => 'budget_tags'],
		'budget_attachments' => ['transaction_id' => 'budget_transactions'],
		'budget_expense_shares' => ['transaction_id' => 'budget_transactions', 'contact_id' => 'budget_contacts'],
		'budget_transactions' => [
			'account_id' => 'budget_accounts',
			'category_id' => 'budget_categories',
			'linked_transaction_id' => 'budget_transactions',
			'bill_id' => 'budget_bills',
			'recon_session_id' => 'budget_recon_sessions',
			'pension_contrib_id' => 'budget_pen_contribs',
		],
		'budget_tag_sets' => ['category_id' => 'budget_categories'],
		'budget_tags' => ['tag_set_id' => 'budget_tag_sets'],
		'budget_categories' => ['parent_id' => 'budget_categories'],
		'budget_bills' => ['account_id' => 'budget_accounts', 'category_id' => 'budget_categories'],
		'budget_pen_contribs' => ['pension_id' => 'budget_pensions', 'transaction_id' => 'budget_transactions', 'source_account_id' => 'budget_accounts'],
		'budget_pen_recur' => ['pension_id' => 'budget_pensions', 'source_account_id' => 'budget_accounts'],
		'budget_pen_snaps' => ['pension_id' => 'budget_pensions'],
		'budget_asset_snaps' => ['asset_id' => 'budget_assets'],
		'budget_settlements' => ['contact_id' => 'budget_contacts'],
		'budget_project_allocs' => ['project_id' => 'budget_projects', 'category_id' => 'budget_categories'],
		'budget_projects' => ['category_id' => 'budget_categories'],
		'budget_recon_sessions' => ['account_id' => 'budget_accounts'],
		'budget_interest_rates' => ['account_id' => 'budget_accounts'],
		'budget_savings_goals' => ['account_id' => 'budget_accounts', 'tag_id' => 'budget_tags'],
		'budget_recurring_income' => ['account_id' => 'budget_accounts', 'category_id' => 'budget_categories'],
		'budget_bgt_snapshots' => ['category_id' => 'budget_categories'],
		'budget_cat_mutes' => ['category_id' => 'budget_categories'],
		'budget_dismiss_imp' => ['account_id' => 'budget_accounts'],
		'budget_imp_links' => ['budget_account_id' => 'budget_accounts'],
		'budget_import_templates' => ['account_id' => 'budget_accounts'],
	];
}
