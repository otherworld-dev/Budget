<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;

/**
 * Builds query filters for transaction queries.
 * Eliminates duplication between main queries and count queries.
 */
class QueryFilterBuilder {
    /**
     * The ids a category filter names: a single id, or the comma-separated list
     * a chart drill-down from an aggregated top-level slice passes (#317).
     *
     * 'uncategorized', an empty value and anything non-numeric all yield an
     * empty list. Shared with TransactionService so the SQL and the split
     * portion it attaches to each row can never disagree about what was asked
     * for (#359).
     *
     * @return int[]
     */
    public static function parseCategoryIds(mixed $category): array {
        if ($category === null || $category === '' || $category === 'uncategorized') {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', (string) $category))));
    }

    /**
     * Apply transaction filters to a query builder.
     *
     * @param IQueryBuilder $qb The query builder to modify
     * @param array $filters The filters to apply
     * @param string $alias The table alias (default: 't')
     */
    public function applyTransactionFilters(IQueryBuilder $qb, array $filters, string $alias = 't'): void {
        // Account filter
        if (!empty($filters['accountId'])) {
            $qb->andWhere($qb->expr()->eq(
                "{$alias}.account_id",
                $qb->createNamedParameter($filters['accountId'], IQueryBuilder::PARAM_INT)
            ));
        }

        // Category filter — accepts a single id or a comma-separated id list
        // (a pie-chart drill-down from an aggregated top-level slice passes
        // the parent plus all its subcategories, #317)
        if (!empty($filters['category'])) {
            if ($filters['category'] === 'uncategorized') {
                $qb->andWhere($qb->expr()->isNull("{$alias}.category_id"));
                // A split parent's own category is deliberately cleared when
                // the transaction is split (the categories move to the split
                // rows), so "category_id IS NULL" alone matches every split
                // transaction and listed them all as uncategorised (#356).
                // is_split predates a default, so legacy rows hold NULL and
                // the eq() leg alone would hide them.
                $qb->andWhere($qb->expr()->orX(
                    $qb->expr()->eq("{$alias}.is_split", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
                    $qb->expr()->isNull("{$alias}.is_split")
                ));
            } else {
                $ids = self::parseCategoryIds($filters['category']);
                if (count($ids) > 1) {
                    $ownCategory = $qb->expr()->in(
                        "{$alias}.category_id",
                        $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY)
                    );
                } else {
                    $ownCategory = $qb->expr()->eq(
                        "{$alias}.category_id",
                        $qb->createNamedParameter($ids[0] ?? 0, IQueryBuilder::PARAM_INT)
                    );
                }

                if ($ids === []) {
                    $qb->andWhere($ownCategory);
                } else {
                    // A split transaction's own category_id is deliberately NULL —
                    // its categories moved to its budget_tx_splits rows — so the
                    // test above can never match one. Filtering by a category
                    // therefore hid every split that spent in it, while the
                    // spending charts, which do read the split rows, counted the
                    // same money: the slice and the list it opens disagreed (#359).
                    //
                    // Correlated EXISTS, deliberately not a join: findWithFilters()
                    // counts with COUNT(t.id) and pages this same predicate, so a
                    // receipt with two parts in the filtered category has to stay
                    // exactly one row. Identifiers inside stay unquoted — none are
                    // reserved, and unquoted folds to the lowercase the table was
                    // created with on PostgreSQL.
                    $splitIds = $qb->createNamedParameter($ids, IQueryBuilder::PARAM_INT_ARRAY);
                    $splitMatch = 'EXISTS (SELECT 1 FROM ' . $qb->getTableName('budget_tx_splits') . ' bsx'
                        . ' WHERE bsx.transaction_id = ' . $alias . '.id'
                        . ' AND bsx.category_id IN (' . $splitIds . '))';

                    // Guarded by is_split so the splits table alone can never
                    // put a transaction in a category: a row explicitly marked
                    // unsplit is not a split, whatever rows happen to reference
                    // it. NULL is kept because is_split post-dates its own
                    // default and old rows hold it (#356).
                    $qb->andWhere($qb->expr()->orX(
                        $ownCategory,
                        $qb->expr()->andX(
                            $splitMatch,
                            $qb->expr()->orX(
                                $qb->expr()->eq("{$alias}.is_split", $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
                                $qb->expr()->isNull("{$alias}.is_split")
                            )
                        )
                    ));
                }
            }
        }

        // Type filter (debit/credit/split)
        if (!empty($filters['type'])) {
            if ($filters['type'] === 'split') {
                $qb->andWhere($qb->expr()->eq(
                    "{$alias}.is_split",
                    $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)
                ));
            } else {
                $qb->andWhere($qb->expr()->eq(
                    "{$alias}.type",
                    $qb->createNamedParameter($filters['type'])
                ));
            }
        }

        // Date range filters
        if (!empty($filters['dateFrom'])) {
            $qb->andWhere($qb->expr()->gte(
                "{$alias}.date",
                $qb->createNamedParameter($filters['dateFrom'])
            ));
        }

        if (!empty($filters['dateTo'])) {
            $qb->andWhere($qb->expr()->lte(
                "{$alias}.date",
                $qb->createNamedParameter($filters['dateTo'])
            ));
        }

        // Creation date range filters (created_at is DATETIME, so date-only
        // values are normalized: "from" uses >= start of day, "to" uses < next day)
        if (!empty($filters['createdAtFrom'])) {
            $timestamp = strtotime($filters['createdAtFrom']);
            if ($timestamp !== false) {
                $qb->andWhere($qb->expr()->gte(
                    "{$alias}.created_at",
                    $qb->createNamedParameter(date('Y-m-d', $timestamp))
                ));
            }
        }

        if (!empty($filters['createdAtTo'])) {
            $timestamp = strtotime($filters['createdAtTo'] . ' +1 day');
            if ($timestamp !== false) {
                $qb->andWhere($qb->expr()->lt(
                    "{$alias}.created_at",
                    $qb->createNamedParameter(date('Y-m-d', $timestamp))
                ));
            }
        }

        // Amount range filters
        if (!empty($filters['amountMin'])) {
            $qb->andWhere($qb->expr()->gte(
                "{$alias}.amount",
                $qb->createNamedParameter($filters['amountMin'])
            ));
        }

        if (!empty($filters['amountMax'])) {
            $qb->andWhere($qb->expr()->lte(
                "{$alias}.amount",
                $qb->createNamedParameter($filters['amountMax'])
            ));
        }

        // Text search filter
        // iLike + lowered pattern: plain LIKE is case-sensitive on PostgreSQL and SQLite
        if (!empty($filters['search'])) {
            $searchPattern = '%' . $qb->escapeLikeParameter(mb_strtolower($filters['search'])) . '%';
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->iLike("{$alias}.description", $qb->createNamedParameter($searchPattern)),
                    $qb->expr()->iLike("{$alias}.vendor", $qb->createNamedParameter($searchPattern)),
                    $qb->expr()->iLike("{$alias}.reference", $qb->createNamedParameter($searchPattern)),
                    $qb->expr()->iLike("{$alias}.notes", $qb->createNamedParameter($searchPattern))
                )
            );
        }

        // Reconciled filter
        if (isset($filters['reconciled']) && $filters['reconciled'] !== null) {
            $qb->andWhere($qb->expr()->eq(
                "{$alias}.reconciled",
                $qb->createNamedParameter($filters['reconciled'] ? 1 : 0, IQueryBuilder::PARAM_INT)
            ));
        }

        // Status filter (cleared/scheduled/pending)
        if (!empty($filters['status'])) {
            if ($filters['status'] === 'scheduled') {
                $qb->andWhere($qb->expr()->eq(
                    "{$alias}.status",
                    $qb->createNamedParameter('scheduled')
                ));
            } elseif ($filters['status'] === 'pending') {
                $qb->andWhere($qb->expr()->eq(
                    "{$alias}.status",
                    $qb->createNamedParameter('pending')
                ));
            } elseif ($filters['status'] === 'cleared') {
                $qb->andWhere(
                    $qb->expr()->orX(
                        $qb->expr()->eq("{$alias}.status", $qb->createNamedParameter('cleared')),
                        $qb->expr()->isNull("{$alias}.status")
                    )
                );
            }
        }

        // Vendor filter
        if (!empty($filters['vendor'])) {
            $qb->andWhere($qb->expr()->eq(
                "{$alias}.vendor",
                $qb->createNamedParameter($filters['vendor'])
            ));
        }

        // Tag filter - filter transactions by tags
        // UI enforces single-selection-per-tag-set, so duplicates cannot occur
        if (!empty($filters['tagIds']) && is_array($filters['tagIds'])) {
            $qb->innerJoin(
                $alias,
                'budget_transaction_tags',
                'tt',
                $qb->expr()->eq("{$alias}.id", 'tt.transaction_id')
            );
            $qb->andWhere($qb->expr()->in(
                'tt.tag_id',
                $qb->createNamedParameter($filters['tagIds'], IQueryBuilder::PARAM_INT_ARRAY)
            ));
        }
    }

    /**
     * Apply sorting to a query builder.
     *
     * @param IQueryBuilder $qb The query builder to modify
     * @param string|null $sortField The field to sort by
     * @param string|null $sortDirection The sort direction (ASC/DESC)
     * @param string $alias The table alias
     */
    public function applySorting(
        IQueryBuilder $qb,
        ?string $sortField = null,
        ?string $sortDirection = null,
        string $alias = 't'
    ): void {
        $sortField = $sortField ?? 'date';
        $sortDirection = strtoupper($sortDirection ?? 'DESC');

        // Map frontend sort fields to database fields
        $sortFieldMap = [
            'date' => "{$alias}.date",
            'description' => "{$alias}.description",
            'amount' => "{$alias}.amount",
            'type' => "{$alias}.type",
            'category' => "{$alias}.category_id",
            'account' => "{$alias}.account_id",
            'vendor' => "{$alias}.vendor",
            'reconciled' => "{$alias}.reconciled",
            'status' => "{$alias}.status",
            'createdAt' => "{$alias}.created_at",
        ];

        $dbSortField = $sortFieldMap[$sortField] ?? "{$alias}.date";
        $qb->orderBy($dbSortField, $sortDirection);

        // Add secondary sort by ID for consistency
        $qb->addOrderBy("{$alias}.id", 'DESC');
    }

    /**
     * Apply pagination to a query builder.
     *
     * @param IQueryBuilder $qb The query builder to modify
     * @param int $limit Maximum results
     * @param int $offset Starting offset
     */
    public function applyPagination(IQueryBuilder $qb, int $limit, int $offset): void {
        $qb->setMaxResults($limit);
        $qb->setFirstResult($offset);
    }

    /**
     * Get list of supported filter keys.
     *
     * @return array<string>
     */
    public function getSupportedFilters(): array {
        return [
            'accountId',
            'category',
            'type',
            'dateFrom',
            'dateTo',
            'amountMin',
            'amountMax',
            'search',
            'reconciled',
            'status',
            'vendor',
            'tagIds',
            'createdAtFrom',
            'createdAtTo',
        ];
    }

    /**
     * Get list of supported sort fields.
     *
     * @return array<string>
     */
    public function getSupportedSortFields(): array {
        return [
            'date',
            'description',
            'amount',
            'type',
            'category',
            'account',
            'vendor',
            'reconciled',
            'status',
            'createdAt',
        ];
    }
}
