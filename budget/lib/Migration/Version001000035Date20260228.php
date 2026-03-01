<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add exchange rates table for currency conversion.
 */
class Version001000035Date20260228 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_exchange_rates')) {
            $table = $schema->createTable('budget_exchange_rates');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);

            // Currency code (e.g. USD, BTC, ETH)
            $table->addColumn('currency', Types::STRING, [
                'notnull' => true,
                'length' => 10,
            ]);

            // Units of this currency per 1 EUR (ECB native format)
            $table->addColumn('rate_per_eur', Types::DECIMAL, [
                'notnull' => true,
                'precision' => 20,
                'scale' => 10,
            ]);

            // The date this rate applies to
            $table->addColumn('date', Types::DATE, [
                'notnull' => true,
            ]);

            // Data source: 'ecb' or 'coingecko'
            $table->addColumn('source', Types::STRING, [
                'notnull' => true,
                'length' => 20,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
            ]);

            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['currency', 'date'], 'budget_exrate_curr_date_idx');
            $table->addIndex(['currency'], 'budget_exrate_currency_idx');
        }

        return $schema;
    }
}
