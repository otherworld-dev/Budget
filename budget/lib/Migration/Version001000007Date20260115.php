<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add linked_transaction_id column to transactions table for transfer matching.
 */
class Version001000007Date20260115 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_transactions')) {
            $table = $schema->getTable('budget_transactions');

            if (!$table->hasColumn('linked_transaction_id')) {
                $table->addColumn('linked_transaction_id', Types::BIGINT, [
                    'notnull' => false,
                    'unsigned' => true,
                ]);

                $table->addIndex(['linked_transaction_id'], 'bgt_tx_linked');
            }
        }

        return $schema;
    }
}
