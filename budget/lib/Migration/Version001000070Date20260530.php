<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Rename indexes that exceed the 27-character limit (with oc_ prefix).
 * These worked on most databases but fail on strict MariaDB setups.
 */
class Version001000070Date20260530 extends SimpleMigrationStep {
    private const RENAMES = [
        'budget_categories' => [
            'budget_categories_parent' => 'bdgt_cat_parent_idx',
        ],
        'budget_forecasts' => [
            'budget_forecasts_account' => 'bdgt_fcast_acct_idx',
        ],
        'budget_auth' => [
            'budget_auth_session_token' => 'bdgt_auth_sess_idx',
            'budget_auth_user_id_unique' => 'bdgt_auth_user_unq',
        ],
        'budget_transactions' => [
            'budget_transactions_bill_id' => 'bdgt_tx_bill_idx',
        ],
        'budget_exchange_rates' => [
            'budget_exrate_currency_idx' => 'bdgt_exrate_cur_idx',
        ],
        'budget_shares' => [
            'budget_share_recipient_idx' => 'bdgt_share_rcpt_idx',
        ],
    ];

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        $changed = false;

        foreach (self::RENAMES as $tableName => $indexes) {
            if (!$schema->hasTable($tableName)) {
                continue;
            }
            $table = $schema->getTable($tableName);

            foreach ($indexes as $oldName => $newName) {
                if ($table->hasIndex($oldName)) {
                    $index = $table->getIndex($oldName);
                    $columns = $index->getColumns();
                    $isUnique = $index->isUnique();

                    $table->dropIndex($oldName);
                    if ($isUnique) {
                        $table->addUniqueIndex($columns, $newName);
                    } else {
                        $table->addIndex($columns, $newName);
                    }
                    $changed = true;
                }
            }
        }

        return $changed ? $schema : null;
    }
}
