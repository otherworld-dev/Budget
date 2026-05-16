<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version001000061Date20260516 extends SimpleMigrationStep {
    public function __construct(
        private IDBConnection $db
    ) {
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $liabilityTypes = ['credit_card', 'loan', 'mortgage', 'line_of_credit'];

        foreach ($liabilityTypes as $type) {
            $this->db->executeStatement(
                'UPDATE `*PREFIX*budget_accounts` SET `balance` = -`balance` WHERE `type` = ? AND `balance` > 0',
                [$type]
            );
            $this->db->executeStatement(
                'UPDATE `*PREFIX*budget_accounts` SET `opening_balance` = -`opening_balance` WHERE `type` = ? AND `opening_balance` > 0',
                [$type]
            );
        }

        $output->info('Negated liability account balances for new storage model');
    }
}
