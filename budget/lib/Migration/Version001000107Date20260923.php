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
 * Give category tags their owner's user_id again.
 *
 * Version001000044 added budget_tags.user_id and backfilled it, but
 * TagSetService::createTag() never set it, so every category tag created
 * since has user_id NULL. The backup registry, restore and factory reset all
 * find tags by user_id, so those tags were missing from every backup and were
 * left behind (unreachable) by every restore and reset. createTag() now sets
 * it; this repeats the 044 backfill for the rows created in between.
 *
 * Tags whose tag set or category is gone cannot be attributed and are left
 * alone.
 */
class Version001000107Date20260923 extends SimpleMigrationStep {

    public function __construct(
        private IDBConnection $db,
    ) {
    }

    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        return null;
    }

    public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options): void {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();
        if (!$schema->hasTable('budget_tags') || !$schema->hasTable('budget_tag_sets')
            || !$schema->hasTable('budget_categories')
            || !$schema->getTable('budget_tags')->hasColumn('user_id')) {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('t.id', 'c.user_id')
            ->from('budget_tags', 't')
            ->innerJoin('t', 'budget_tag_sets', 'ts', $qb->expr()->eq('t.tag_set_id', 'ts.id'))
            ->innerJoin('ts', 'budget_categories', 'c', $qb->expr()->eq('ts.category_id', 'c.id'))
            ->where($qb->expr()->isNull('t.user_id'));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        $updated = 0;
        foreach ($rows as $row) {
            $update = $this->db->getQueryBuilder();
            $update->update('budget_tags')
                ->set('user_id', $update->createNamedParameter($row['user_id']))
                ->where($update->expr()->eq('id', $update->createNamedParameter((int) $row['id'], IQueryBuilder::PARAM_INT)));
            $updated += $update->executeStatement();
        }

        if ($updated > 0) {
            $output->info("Set the owner on {$updated} category tag(s)");
        }
    }
}
