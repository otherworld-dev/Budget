<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Records what a POSITIVE opening balance on a liability account means (#353).
 *
 * A liability stores balance/opening_balance negative (amount owed). Since #195
 * a positive value is also legal, meaning the account is in credit (overpaid).
 * The sign alone could not tell a genuine overpayment from a user who typed the
 * statement balance without the minus, so the intent is now recorded explicitly.
 *
 * NULL = never declared (legacy rows). The Repair Data tool resolves those.
 */
class Version001000096Date20260824 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if (!$schema->hasTable('budget_accounts')) {
            return null;
        }

        $table = $schema->getTable('budget_accounts');
        if ($table->hasColumn('liability_in_credit')) {
            return null;
        }

        $table->addColumn('liability_in_credit', Types::BOOLEAN, [
            'notnull' => false,
            'default' => null,
        ]);

        return $schema;
    }
}
