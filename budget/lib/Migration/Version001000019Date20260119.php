<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Clean up floating-point precision errors in account balances.
 * Fixes balances that show scientific notation (e.g., 9.9920072216264e-15)
 * due to accumulated rounding errors from float arithmetic.
 */
class Version001000019Date20260119 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    /**
     * Clean up balances with floating-point precision errors.
     * Round any balance between -0.01 and 0.01 (but not exactly 0.00) to 0.00.
     */
    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        $output->info('Cleaning up floating-point precision errors in account balances...');

        $qb = $this->db->getQueryBuilder();
        $qb->select('id', 'balance')
            ->from('budget_accounts');

        $result = $qb->executeQuery();
        $updatedCount = 0;

        while ($row = $result->fetch()) {
            $balance = (float) $row['balance'];

            // If balance is very small (likely precision error), round to 0.00
            // This catches values like 9.9920072216264e-15 which should be 0.00
            if ($balance > -0.01 && $balance < 0.01 && $balance != 0.00) {
                $updateQb = $this->db->getQueryBuilder();
                $updateQb->update('budget_accounts')
                    ->set('balance', $updateQb->createNamedParameter('0.00'))
                    ->set('updated_at', $updateQb->createNamedParameter(date('Y-m-d H:i:s')))
                    ->where($updateQb->expr()->eq('id', $updateQb->createNamedParameter($row['id'])));

                $updateQb->executeStatement();
                $updatedCount++;
            }
        }
        $result->closeCursor();

        if ($updatedCount > 0) {
            $output->info("Cleaned up {$updatedCount} account(s) with precision errors");
        } else {
            $output->info('No accounts with precision errors found');
        }
    }
}
