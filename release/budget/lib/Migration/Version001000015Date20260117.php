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
 * Add shared expense tables for split expenses with contacts.
 */
class Version001000015Date20260117 extends SimpleMigrationStep {

    /**
     * Drop broken tables entirely to avoid schema reconciliation issues
     */
    public function preSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var IDBConnection $connection */
        $connection = \OC::$server->getDatabaseConnection();
        $prefix = $connection->getPrefix();

        // Drop entire tables if they exist - will be recreated with correct defaults
        $tablesToDrop = [
            'budget_expense_shares',
            'budget_contacts',
            'budget_settlements',
        ];

        foreach ($tablesToDrop as $table) {
            try {
                $connection->executeStatement("DROP TABLE IF EXISTS {$prefix}{$table}");
            } catch (\Exception $e) {
                // Table might not exist, continue
            }
        }
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Contacts table - people you share expenses with
        if (!$schema->hasTable('budget_contacts')) {
            $table = $schema->createTable('budget_contacts');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);
            $table->addColumn('email', Types::STRING, [
                'notnull' => false,
                'length' => 255,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'bgt_contact_uid');
        }

        // Expense shares - records of who owes what on a transaction
        if (!$schema->hasTable('budget_expense_shares')) {
            $table = $schema->createTable('budget_expense_shares');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('transaction_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('contact_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            // Amount the contact owes (positive = they owe you, negative = you owe them)
            $table->addColumn('amount', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('is_settled', Types::BOOLEAN, [
                'notnull' => true,
                'default' => 0,
            ]);
            $table->addColumn('notes', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'bgt_expshare_uid');
            $table->addIndex(['transaction_id'], 'bgt_expshare_txn');
            $table->addIndex(['contact_id'], 'bgt_expshare_contact');
            $table->addIndex(['is_settled'], 'bgt_expshare_settled');
        }
        // Note: else removed - table was dropped in preSchemaChange if it existed

        // Settlements - records of settling debts
        if (!$schema->hasTable('budget_settlements')) {
            $table = $schema->createTable('budget_settlements');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('contact_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            // Positive = you received payment, negative = you paid them
            $table->addColumn('amount', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 15,
                'scale' => 2,
            ]);
            $table->addColumn('date', Types::DATE, [
                'notnull' => true,
            ]);
            $table->addColumn('notes', Types::TEXT, [
                'notnull' => false,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addIndex(['user_id'], 'bgt_settle_uid');
            $table->addIndex(['contact_id'], 'bgt_settle_contact');
            $table->addIndex(['date'], 'bgt_settle_date');
        }

        return $schema;
    }
}
