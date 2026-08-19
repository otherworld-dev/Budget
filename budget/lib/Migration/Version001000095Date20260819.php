<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Day of the month a credit card's statement payment is due (#347).
 * Account-level information for card-like accounts; the payment bill's
 * due day is prefilled from it.
 */
class Version001000095Date20260819 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_accounts')) {
            return null;
        }
        $table = $schema->getTable('budget_accounts');
        if ($table->hasColumn('statement_day')) {
            return null;
        }
        $table->addColumn('statement_day', Types::INTEGER, [
            'notnull' => false,
        ]);
        return $schema;
    }
}
