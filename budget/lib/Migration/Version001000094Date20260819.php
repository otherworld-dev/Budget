<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * How a bill's amount is determined (#347): 'fixed' (default, the stored
 * amount) or 'statement' (a transfer bill that resolves, at each payment,
 * to what is owed on the destination credit card as of the due date).
 */
class Version001000094Date20260819 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_bills')) {
            return null;
        }
        $table = $schema->getTable('budget_bills');
        if ($table->hasColumn('amount_type')) {
            return null;
        }
        $table->addColumn('amount_type', Types::STRING, [
            'notnull' => false,
            'length' => 20,
            'default' => 'fixed',
        ]);
        return $schema;
    }
}
