<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Pensions revamp — link bank transactions to pension contributions (#304) and
 * add a per-pension projection target (#251 follow-up).
 *
 * - budget_pen_contribs gains transaction_id + source_account_id (the bank leg a
 *   contribution/withdrawal came from / went to) and a kind discriminator
 *   ('contribution' | 'withdrawal'; the 'withdrawal' value is reserved here so
 *   drawdowns need no further ALTER).
 * - budget_transactions gains a read-only pension_contrib_id marker, used solely
 *   by the spending-exclusion filters so a contribution's bank leg never counts
 *   as spending. It deliberately keeps linked_transaction_id NULL so it never
 *   enters the transfer-total joins.
 * - budget_pensions gains projection_target so the £500k projection goal is
 *   configurable per pension.
 *
 * Idempotent: every column/index is guarded, so it's a no-op on healthy schemas.
 */
class Version001000086Date20260624 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		$changed = false;

		if ($schema->hasTable('budget_pen_contribs')) {
			$table = $schema->getTable('budget_pen_contribs');
			if (!$table->hasColumn('transaction_id')) {
				$table->addColumn('transaction_id', Types::BIGINT, [
					'notnull' => false,
					'unsigned' => true,
				]);
				$changed = true;
			}
			if (!$table->hasColumn('source_account_id')) {
				$table->addColumn('source_account_id', Types::BIGINT, [
					'notnull' => false,
					'unsigned' => true,
				]);
				$changed = true;
			}
			if (!$table->hasColumn('kind')) {
				$table->addColumn('kind', Types::STRING, [
					'notnull' => false,
					'length' => 20,
					'default' => 'contribution',
				]);
				$changed = true;
			}
			if (!$table->hasIndex('bgt_pencon_txid')) {
				$table->addIndex(['transaction_id'], 'bgt_pencon_txid');
				$changed = true;
			}
			if (!$table->hasIndex('bgt_pencon_acct')) {
				$table->addIndex(['source_account_id'], 'bgt_pencon_acct');
				$changed = true;
			}
		}

		if ($schema->hasTable('budget_transactions')) {
			$table = $schema->getTable('budget_transactions');
			if (!$table->hasColumn('pension_contrib_id')) {
				$table->addColumn('pension_contrib_id', Types::BIGINT, [
					'notnull' => false,
					'unsigned' => true,
				]);
				$changed = true;
			}
			if (!$table->hasIndex('bgt_tx_pencontrib')) {
				$table->addIndex(['pension_contrib_id'], 'bgt_tx_pencontrib');
				$changed = true;
			}
		}

		if ($schema->hasTable('budget_pensions')) {
			$table = $schema->getTable('budget_pensions');
			if (!$table->hasColumn('projection_target')) {
				$table->addColumn('projection_target', Types::DECIMAL, [
					'notnull' => false,
					'precision' => 15,
					'scale' => 2,
				]);
				$changed = true;
			}
		}

		return $changed ? $schema : null;
	}
}
