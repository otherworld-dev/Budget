<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Add support for global tags (GitHub Issue #109).
 * Makes tag_set_id nullable on budget_tags and adds user_id column
 * so tags can exist without a tag set (global tags).
 */
class Version001000044Date20260409 extends SimpleMigrationStep {

    private IDBConnection $db;

    public function __construct(IDBConnection $db) {
        $this->db = $db;
    }

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        if ($schema->hasTable('budget_tags')) {
            $table = $schema->getTable('budget_tags');

            // Make tag_set_id nullable so tags can exist without a tag set (global tags)
            $table->changeColumn('tag_set_id', [
                'notnull' => false,
                'unsigned' => true,
            ]);

            // Add user_id for global tags (category tags derive user from tag_set -> category)
            if (!$table->hasColumn('user_id')) {
                $table->addColumn('user_id', Types::STRING, [
                    'notnull' => false,
                    'length' => 64,
                ]);
                $table->addIndex(['user_id'], 'idx_tags_user');
            }
        }

        return $schema;
    }

    public function postSchemaChange(IOutput $output, \Closure $schemaClosure, array $options): void {
        // Backfill user_id on existing tags by joining through tag_sets -> categories
        $qb = $this->db->getQueryBuilder();
        $qb->select('t.id', 'c.user_id')
            ->from('budget_tags', 't')
            ->innerJoin('t', 'budget_tag_sets', 'ts', 't.tag_set_id = ts.id')
            ->innerJoin('ts', 'budget_categories', 'c', 'ts.category_id = c.id')
            ->where($qb->expr()->isNull('t.user_id'));

        $result = $qb->executeQuery();
        $rows = $result->fetchAll();
        $result->closeCursor();

        foreach ($rows as $row) {
            $update = $this->db->getQueryBuilder();
            $update->update('budget_tags')
                ->set('user_id', $update->createNamedParameter($row['user_id']))
                ->where($update->expr()->eq('id', $update->createNamedParameter((int)$row['id'], \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT)));
            $update->executeStatement();
        }
    }
}
