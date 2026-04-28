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
 * Add currency column to budget_expense_shares and budget_settlements
 * so shared expenses respect the transaction's account currency.
 */
class Version001000057Date20260428 extends SimpleMigrationStep {
    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Add currency to expense_shares
        $table = $schema->getTable('budget_expense_shares');
        if (!$table->hasColumn('currency')) {
            $table->addColumn('currency', Types::STRING, [
                'notnull' => false,
                'length' => 3,
                'default' => null,
            ]);
        }

        // Add currency to settlements
        $table = $schema->getTable('budget_settlements');
        if (!$table->hasColumn('currency')) {
            $table->addColumn('currency', Types::STRING, [
                'notnull' => false,
                'length' => 3,
                'default' => null,
            ]);
        }

        return $schema;
    }

    /**
     * Backfill currency from transaction -> account for existing expense shares.
     * For settlements, default to user's base currency (no transaction link).
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Backfill expense_shares: join through transaction -> account to get currency
        $qb = $this->db->getQueryBuilder();
        $qb->update('budget_expense_shares', 'es')
            ->set('es.currency', 'sub.currency')
            ->where('es.currency IS NULL');

        // Can't do subquery update easily with query builder, use raw SQL
        $sql = "UPDATE `*PREFIX*budget_expense_shares` AS es "
             . "SET es.`currency` = ("
             . "  SELECT a.`currency` FROM `*PREFIX*budget_transactions` t "
             . "  JOIN `*PREFIX*budget_accounts` a ON t.`account_id` = a.`id` "
             . "  WHERE t.`id` = es.`transaction_id` LIMIT 1"
             . ") "
             . "WHERE es.`currency` IS NULL";

        try {
            $this->db->executeStatement($sql);
        } catch (\Exception $e) {
            // SQLite doesn't support UPDATE with alias — try without
            $sql = "UPDATE `*PREFIX*budget_expense_shares` "
                 . "SET `currency` = ("
                 . "  SELECT a.`currency` FROM `*PREFIX*budget_transactions` t "
                 . "  JOIN `*PREFIX*budget_accounts` a ON t.`account_id` = a.`id` "
                 . "  WHERE t.`id` = `*PREFIX*budget_expense_shares`.`transaction_id` LIMIT 1"
                 . ") "
                 . "WHERE `currency` IS NULL";

            try {
                $this->db->executeStatement($sql);
            } catch (\Exception $e2) {
                $output->warning('Could not backfill expense share currencies: ' . $e2->getMessage());
            }
        }

        // Backfill settlements: use the user's default currency from settings
        // Settlements don't link to a transaction, so we use default_currency setting
        $sql = "UPDATE `*PREFIX*budget_settlements` "
             . "SET `currency` = ("
             . "  SELECT s.`value` FROM `*PREFIX*budget_settings` s "
             . "  WHERE s.`user_id` = `*PREFIX*budget_settlements`.`user_id` "
             . "  AND s.`key` = 'default_currency' LIMIT 1"
             . ") "
             . "WHERE `currency` IS NULL";

        try {
            $this->db->executeStatement($sql);
        } catch (\Exception $e) {
            $output->warning('Could not backfill settlement currencies: ' . $e->getMessage());
        }
    }
}
