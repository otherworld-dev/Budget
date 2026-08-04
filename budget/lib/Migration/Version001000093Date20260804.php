<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Idempotency keys for the public API's POST transactions (#533 handoff).
 *
 * A capture client on mobile data cannot know whether a timed-out POST
 * committed — its only safe options are to bother the user or to duplicate
 * the transaction. The Android app's review record lists ten distinct
 * client-side duplicate paths; this table is the server-side fix. The client
 * sends a UUID per draft; a repeat of the same key answers with the already
 * recorded transaction instead of a second insert.
 *
 * Rows are short-lived bookkeeping (purged after a week by the service) and
 * reference the transaction loosely by id — no FK, so deleting a transaction
 * never has to care about this table.
 */
class Version001000093Date20260804 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_idem_keys')) {
            $table = $schema->createTable('budget_idem_keys');
            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
            ]);
            $table->addColumn('user_id', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('idem_key', Types::STRING, [
                'notnull' => true,
                'length' => 64,
            ]);
            $table->addColumn('transaction_id', Types::BIGINT, [
                'notnull' => true,
            ]);
            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id'], 'budget_idemk_pk');
            // The whole point: one key, one transaction, per user.
            $table->addUniqueIndex(['user_id', 'idem_key'], 'budget_idemk_user_key');
            // The purge deletes by age.
            $table->addIndex(['created_at'], 'budget_idemk_created');
        }

        return $schema;
    }
}
