<?php

declare(strict_types=1);

namespace OCA\Budget\Migration;

use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Create tables for tag sets feature (GitHub Issue #13).
 * Enables multiple tag sets per category for detailed transaction organization.
 */
class Version001000022Date20260127 extends SimpleMigrationStep {

    public function changeSchema(IOutput $output, \Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        // Create budget_tag_sets table
        if (!$schema->hasTable('budget_tag_sets')) {
            $table = $schema->createTable('budget_tag_sets');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->setPrimaryKey(['id'], 'tag_sets_pk');

            $table->addColumn('category_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);

            $table->addColumn('description', Types::TEXT, [
                'notnull' => false,
            ]);

            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ]);

            $table->addColumn('updated_at', Types::DATETIME, [
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ]);

            // Foreign key to categories with cascade delete
            $table->addForeignKeyConstraint(
                'budget_categories',
                ['category_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_tag_sets_category'
            );

            // Index for category lookup
            $table->addIndex(['category_id'], 'idx_tag_sets_category');
        }

        // Create budget_tags table
        if (!$schema->hasTable('budget_tags')) {
            $table = $schema->createTable('budget_tags');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->setPrimaryKey(['id'], 'tags_pk');

            $table->addColumn('tag_set_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('name', Types::STRING, [
                'notnull' => true,
                'length' => 255,
            ]);

            $table->addColumn('color', Types::STRING, [
                'notnull' => false,
                'length' => 7,
            ]);

            $table->addColumn('sort_order', Types::INTEGER, [
                'notnull' => true,
                'default' => 0,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ]);

            // Foreign key to tag_sets with cascade delete
            $table->addForeignKeyConstraint(
                'budget_tag_sets',
                ['tag_set_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_tags_tag_set'
            );

            // Index for tag set lookup
            $table->addIndex(['tag_set_id'], 'idx_tags_tag_set');
        }

        // Create budget_transaction_tags junction table
        if (!$schema->hasTable('budget_transaction_tags')) {
            $table = $schema->createTable('budget_transaction_tags');

            $table->addColumn('id', Types::BIGINT, [
                'autoincrement' => true,
                'notnull' => true,
                'unsigned' => true,
            ]);
            $table->setPrimaryKey(['id'], 'tx_tags_pk');

            $table->addColumn('transaction_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('tag_id', Types::BIGINT, [
                'notnull' => true,
                'unsigned' => true,
            ]);

            $table->addColumn('created_at', Types::DATETIME, [
                'notnull' => true,
                'default' => 'CURRENT_TIMESTAMP',
            ]);

            // Foreign key to transactions with cascade delete
            $table->addForeignKeyConstraint(
                'budget_transactions',
                ['transaction_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_tx_tags_transaction'
            );

            // Foreign key to tags with cascade delete
            $table->addForeignKeyConstraint(
                'budget_tags',
                ['tag_id'],
                ['id'],
                ['onDelete' => 'CASCADE'],
                'fk_tx_tags_tag'
            );

            // Unique constraint to prevent duplicate tag assignments
            $table->addUniqueIndex(['transaction_id', 'tag_id'], 'idx_tx_tags_unique');

            // Indexes for efficient lookups and filtering
            $table->addIndex(['transaction_id'], 'idx_tx_tags_transaction');
            $table->addIndex(['tag_id'], 'idx_tx_tags_tag');
        }

        return $schema;
    }
}
