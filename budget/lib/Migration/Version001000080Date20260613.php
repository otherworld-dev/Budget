<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Statement reconciliation sessions: a session records the statement being
 * reconciled against (balance + date) and which transactions were ticked
 * off (via recon_session_id on transactions — batched updates, free resume,
 * permanent provenance). Explicit PK/index names (#272 lesson).
 */
class Version001000080Date20260613 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        if (!$schema->hasTable('budget_recon_sessions')) {
            $table = $schema->createTable('budget_recon_sessions');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('account_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('statement_date', Types::STRING, [
                'notnull' => true,
                'length' => 10,
            ]);
            $table->addColumn('statement_balance', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 20,
                'scale' => 2,
            ]);
            $table->addColumn('starting_balance', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 20,
                'scale' => 2,
            ]);
            $table->addColumn('status', Types::STRING, [
                'notnull' => true,
                'length' => 16,
                'default' => 'in_progress',
            ]);
            $table->addColumn('reconciled_count', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);
            $table->addColumn('completed_at', Types::DATETIME, [
                'notnull' => false,
            ]);

            $table->setPrimaryKey(['id'], 'bgt_recsess_pk');
            $table->addIndex(['account_id'], 'bgt_recsess_acct_idx');
            $table->addIndex(['user_id'], 'bgt_recsess_user_idx');
            $changed = true;
        }

        if ($schema->hasTable('budget_transactions')) {
            $table = $schema->getTable('budget_transactions');
            if (!$table->hasColumn('recon_session_id')) {
                $table->addColumn('recon_session_id', Types::BIGINT, [
                    'notnull' => false,
                    'unsigned' => true,
                ]);
                $table->addIndex(['recon_session_id'], 'bgt_tx_reconsess_idx');
                $changed = true;
            }
        }

        return $changed ? $schema : null;
    }
}
