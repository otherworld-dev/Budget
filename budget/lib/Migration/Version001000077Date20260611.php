<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Backfill opening_balance so that the ledger invariant
 *   balance = opening_balance + net(non-scheduled transactions)
 * holds for every account (#274).
 *
 * Historically the stored balance was a running total adjusted by deltas, so
 * accounts created before opening_balance existed (or whose balance drifted
 * through past delta bugs — #3, #89, #124, #163, #187, #194) violate the
 * invariant. From this release on, balances are recomputed from the ledger on
 * every write; without this backfill that first recompute would visibly change
 * users' balances. Setting opening_balance := stored − net preserves exactly
 * what each user sees today while making all future math drift-proof.
 */
class Version001000077Date20260611 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        // Net per account over all non-scheduled transactions
        $netQb = $this->db->getQueryBuilder();
        $netQb->select('account_id')
            ->selectAlias(
                $netQb->createFunction("COALESCE(SUM(CASE WHEN type = 'credit' THEN amount ELSE -amount END), 0)"),
                'net'
            )
            ->from('budget_transactions')
            ->where(
                $netQb->expr()->orX(
                    $netQb->expr()->neq('status', $netQb->createNamedParameter('scheduled')),
                    $netQb->expr()->isNull('status')
                )
            )
            ->groupBy('account_id');
        $result = $netQb->executeQuery();
        $nets = [];
        while ($row = $result->fetch()) {
            $nets[(int)$row['account_id']] = (float)$row['net'];
        }
        $result->closeCursor();

        $accQb = $this->db->getQueryBuilder();
        $accQb->select('id', 'balance', 'opening_balance')->from('budget_accounts');
        $result = $accQb->executeQuery();
        $accounts = $result->fetchAll();
        $result->closeCursor();

        $fixed = 0;
        foreach ($accounts as $acc) {
            $stored = (float)($acc['balance'] ?? 0);
            $opening = (float)($acc['opening_balance'] ?? 0);
            $net = $nets[(int)$acc['id']] ?? 0.0;

            if (abs($stored - ($opening + $net)) <= 0.005) {
                continue; // invariant already holds
            }

            $update = $this->db->getQueryBuilder();
            $update->update('budget_accounts')
                ->set('opening_balance', $update->createNamedParameter(round($stored - $net, 2)))
                ->where($update->expr()->eq('id', $update->createNamedParameter((int)$acc['id'], IQueryBuilder::PARAM_INT)));
            $update->executeStatement();
            $fixed++;
        }

        if ($fixed > 0) {
            $output->info("Backfilled opening_balance for {$fixed} account(s) to preserve displayed balances under ledger-derived recalculation.");
        }
    }
}
