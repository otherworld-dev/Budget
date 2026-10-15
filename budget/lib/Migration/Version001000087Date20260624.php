<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Pensions revamp — scheduled (recurring) pension contributions (#251 asked for
 * "quarterly and by hand"). Each row is a schedule on a pension; when auto-post
 * is enabled the background job creates the due contribution (and the linked
 * bank transfer if source_account_id is set). Mirrors budget_recurring_income.
 */
class Version001000087Date20260624 extends SimpleMigrationStep {

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('budget_pen_recur')) {
			return null;
		}

		$table = $schema->createTable('budget_pen_recur');
		$table->addColumn('id', Types::BIGINT, [
			'autoincrement' => true,
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('user_id', Types::STRING, [
			'notnull' => true,
			'length' => 64,
		]);
		$table->addColumn('pension_id', Types::BIGINT, [
			'notnull' => true,
			'unsigned' => true,
		]);
		$table->addColumn('amount', Types::DECIMAL, [
			'notnull' => true,
			'precision' => 15,
			'scale' => 2,
		]);
		$table->addColumn('frequency', Types::STRING, [
			'notnull' => true,
			'length' => 20,
			'default' => 'monthly',
		]);
		$table->addColumn('source_account_id', Types::BIGINT, [
			'notnull' => false,
			'unsigned' => true,
		]);
		$table->addColumn('auto_post_enabled', Types::BOOLEAN, [
			'notnull' => false,
			'default' => false,
		]);
		$table->addColumn('next_due_date', Types::DATE, [
			'notnull' => true,
		]);
		$table->addColumn('last_posted_date', Types::DATE, [
			'notnull' => false,
		]);
		$table->addColumn('is_active', Types::BOOLEAN, [
			'notnull' => false,
			'default' => true,
		]);
		$table->addColumn('note', Types::STRING, [
			'notnull' => false,
			'length' => 500,
		]);
		$table->addColumn('created_at', Types::DATETIME, [
			'notnull' => true,
		]);
		$table->addColumn('updated_at', Types::DATETIME, [
			'notnull' => true,
		]);

		$table->setPrimaryKey(['id']);
		$table->addIndex(['user_id'], 'bgt_penrec_uid');
		$table->addIndex(['pension_id'], 'bgt_penrec_pid');
		$table->addIndex(['next_due_date'], 'bgt_penrec_due');
		$table->addIndex(['auto_post_enabled'], 'bgt_penrec_auto');

		return $schema;
	}
}
