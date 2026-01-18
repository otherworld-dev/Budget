<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create transaction splits table for splitting transactions across categories.
 */
class Version001000012Date20260117 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create transaction splits table
        if (!$schema->hasTable('budget_tx_splits')) {
            $table = $schema->createTable('budget_tx_splits');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('transaction_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->addColumn('category_id', Types::BIGINT, [
                'notnull' => false,
                'unsigned' => true,
            ]);
            $table->addColumn('amount', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('description', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['transaction_id'], 'bgt_txsplit_txid');
            $table->addIndex(['category_id'], 'bgt_txsplit_cat');
        }

        // Add is_split flag to transactions table
        if ($schema->hasTable('budget_transactions')) {
            $transactionsTable = $schema->getTable('budget_transactions');
            if (!$transactionsTable->hasColumn('is_split')) {
                $transactionsTable->addColumn('is_split', Types::BOOLEAN, [
                    'notnull' => false,
                    'default' => false,
                ]);
            }
        }

        return $schema;
    }
}
