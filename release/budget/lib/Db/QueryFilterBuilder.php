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

        // Category filter
        if (!empty($filters['category'])) {
            if ($filters['category'] === 'uncategorized') {
                $qb->andWhere($qb->expr()->isNull("{$alias}.category_id"));
            } else {
                $qb->andWhere($qb->expr()->eq(
                    "{$alias}.category_id",
                    $qb->createNamedParameter($filters['category'], IQueryBuilder::PARAM_INT)
                ));
            }
        }

        // Type filter (debit/credit)
        if (!empty($filters['type'])) {
            $qb->andWhere($qb->expr()->eq(
                "{$alias}.type",
                $qb->createNamedParameter($filters['type'])
            ));
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
        if (!empty($filters['search'])) {
            $searchPattern = '%' . $qb->escapeLikeParameter($filters['search']) . '%';
            $qb->andWhere(
                $qb->expr()->orX(
                    $qb->expr()->like("{$alias}.description", $qb->createNamedParameter($searchPattern)),
                    $qb->expr()->like("{$alias}.vendor", $qb->createNamedParameter($searchPattern)),
                    $qb->expr()->like("{$alias}.reference", $qb->createNamedParameter($searchPattern)),
                    $qb->expr()->like("{$alias}.notes", $qb->createNamedParameter($searchPattern))
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

        // Vendor filter
        if (!empty($filters['vendor'])) {
            $qb->andWhere($qb->expr()->eq(
                "{$alias}.vendor",
                $qb->createNamedParameter($filters['vendor'])
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
            'vendor',
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
        ];
    }
}
