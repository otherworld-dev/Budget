<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCA\Budget\Service\MoneyCalculator;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The query predicates that decide which transactions a report, insight or
 * budget aggregate may count, in one stateless place so they stay single
 * while the queries using them live in more than one mapper.
 *
 * These are the SQL choke points CLAUDE.md names: report-excluded accounts
 * (#286), report-excluded and viewer-muted categories (#219), scheduled
 * future rows and pension-funding legs (#304), and the direct/split
 * partition every per-category aggregate relies on (#360). Every report
 * aggregate routes through here — never through a filter in PHP after the
 * fetch, which is how exclusions drifted before.
 *
 * Every predicate expects the transaction table aliased 't' and, where it
 * scopes by account, budget_accounts joined as 'a'.
 */
final class ReportScope {
    /**
     * Scale report merges add at: wide enough for every supported currency
     * (crypto keeps 8dp), so merging two SQL sums never truncates either.
     */
    public const MERGE_SCALE = 8;

    // ==================== Month bucketing and the viewer's account scope ====================

    /**
     * Return a SQL expression that extracts YYYY-MM from a date column.
     * Uses CAST to VARCHAR for PostgreSQL compatibility (SUBSTR on a native
     * date type fails without an explicit cast). VARCHAR works on all three
     * supported databases (MySQL, PostgreSQL, SQLite).
     */
    public static function monthExpr(string $alias = 't'): string {
        return "SUBSTR(CAST({$alias}.date AS CHAR(10)), 1, 7)";
    }

    /**
     * Apply user scope to a query — either by userId or by visible account IDs.
     * Used for granular sharing where the user can see specific shared accounts.
     *
     * By default this ALSO excludes accounts flagged excluded_from_reports (#286)
     * so every "all accounts" aggregation drops them automatically. The few
     * non-aggregate callers that must still see those accounts — the transaction
     * list/count, search, and the generic date-range fetch — pass
     * $includeReportExcluded = true to opt out.
     *
     * @param int[]|null $visibleAccountIds If provided, scope by account IDs instead of userId
     */
    public static function applyUserScope(IQueryBuilder $qb, string $userId, ?array $visibleAccountIds = null, bool $includeReportExcluded = false): void {
        if ($visibleAccountIds !== null && !empty($visibleAccountIds)) {
            $qb->andWhere($qb->expr()->in('a.id', $qb->createNamedParameter($visibleAccountIds, IQueryBuilder::PARAM_INT_ARRAY)));
        } else {
            $qb->andWhere($qb->expr()->eq('a.user_id', $qb->createNamedParameter($userId)));
        }
        if (!$includeReportExcluded) {
            self::excludeReportExcludedAccounts($qb);
        }
    }

    // ==================== The direct/split partition ====================

    /**
     * Match the parent transactions that own split rows.
     *
     * is_split post-dates its own default, so rows written before it hold NULL
     * rather than 0/1 and an eq(true) test alone hides them — the same reason
     * QueryFilterBuilder's uncategorised branch spells this out (#356). false
     * stays excluded: a transaction explicitly marked unsplit must not have its
     * leftover split rows counted on top of its own category.
     */
    public static function splitParentPredicate(IQueryBuilder $qb, string $alias = 't'): ICompositeExpression {
        return $qb->expr()->orX(
            $qb->expr()->eq("{$alias}.is_split", $qb->createNamedParameter(true, IQueryBuilder::PARAM_BOOL)),
            $qb->expr()->isNull("{$alias}.is_split")
        );
    }

    /**
     * Match the transactions whose own row speaks for itself: explicitly
     * marked unsplit, or carrying no rows in budget_tx_splits. This is the
     * exact complement of the split side of every per-category aggregate, so
     * the direct and split queries partition the transactions — no row lands
     * in both, none in neither (#360).
     *
     * A properly split parent has category_id NULL and usually falls out of a
     * category-scoped query on its own, but one the pre-#356 bulk edit (or
     * any rule run until #360) stamped a category onto kept its split rows,
     * and nothing ever repaired those — such a row was counted at its full
     * amount AND part by part. A NULL-flag row (is_split predates its own
     * default) that HAS parts is a split parent regardless of the flag; a row
     * explicitly marked unsplit keeps its own amount whatever split rows
     * still reference it, the policy QueryFilterBuilder states for the same
     * situation (#356).
     *
     * NOT splitParentPredicate()'s negation via the flag alone — the whole
     * point is that the parts table, not the flag, settles the grey states.
     */
    public static function directRowPredicate(IQueryBuilder $qb, string $alias = 't'): ICompositeExpression {
        return $qb->expr()->orX(
            $qb->expr()->eq("{$alias}.is_split", $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)),
            'NOT ' . self::hasSplitPartsExpr($qb, $alias)
        );
    }

    /**
     * Correlated EXISTS testing whether a transaction has any split rows.
     * Thin delegation to the one shared expression on QueryFilterBuilder,
     * which carries the not-a-join rationale (#360).
     */
    public static function hasSplitPartsExpr(IQueryBuilder $qb, string $alias): string {
        return QueryFilterBuilder::hasSplitPartsExpr($qb, $alias);
    }

    // ==================== Report exclusion choke points ====================

    /**
     * Add a condition that drops transactions belonging to accounts flagged
     * excluded_from_reports (#286). NULL counts as not-excluded so existing
     * accounts are unaffected. The given alias must reference budget_accounts.
     */
    public static function excludeReportExcludedAccounts(IQueryBuilder $qb, string $alias = 'a'): void {
        $qb->andWhere($qb->expr()->orX(
            $qb->expr()->isNull($alias . '.excluded_from_reports'),
            $qb->expr()->eq($alias . '.excluded_from_reports', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
        ));
    }

    /**
     * Add a condition that drops transactions whose CATEGORY is flagged
     * excluded_from_reports (#219). NULL/unflagged categories are kept. Use this
     * overload when budget_categories is already joined under $alias.
     *
     * This is the single SQL-level choke point for category exclusion: every
     * report/insight aggregate must route through here (or its left-join
     * companion below) so a new report can't silently re-introduce the leak the
     * way the old per-consumer PHP filtering did.
     */
    public static function excludeReportExcludedCategories(IQueryBuilder $qb, string $alias = 'c', ?string $viewerUserId = null): void {
        $qb->andWhere($qb->expr()->orX(
            $qb->expr()->isNull($alias . '.excluded_from_reports'),
            $qb->expr()->eq($alias . '.excluded_from_reports', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL))
        ));
        if ($viewerUserId !== null) {
            self::excludeViewerMutedCategories($qb, $alias, $viewerUserId);
        }
    }

    /**
     * Per-viewer companion to the owner flag above: drop transactions whose
     * category the VIEWING user muted ("hide from my reports" on a category
     * shared with them). The owner's excluded_from_reports flag stays
     * owner-only because it is one switch affecting every viewer's reports;
     * this join gives each viewer their own switch (budget_cat_mutes).
     */
    public static function excludeViewerMutedCategories(IQueryBuilder $qb, string $catAlias, string $viewerUserId): void {
        $muteAlias = $catAlias . '_mut';
        $qb->leftJoin($catAlias, 'budget_cat_mutes', $muteAlias, $qb->expr()->andX(
            $qb->expr()->eq($muteAlias . '.category_id', $catAlias . '.id'),
            $qb->expr()->eq($muteAlias . '.user_id', $qb->createNamedParameter($viewerUserId))
        ));
        $qb->andWhere($qb->expr()->isNull($muteAlias . '.id'));
    }

    /**
     * Left-join budget_categories and drop transactions whose category is flagged
     * excluded_from_reports (#219). For aggregates that don't otherwise need the
     * category table (e.g. monthly income/expense trends). A LEFT join keeps
     * uncategorised (NULL category) transactions, which are never "excluded".
     */
    public static function leftJoinExcludeReportCategories(IQueryBuilder $qb, string $txAlias = 't', string $catAlias = 'exc', ?string $viewerUserId = null): void {
        $qb->leftJoin($txAlias, 'budget_categories', $catAlias, $qb->expr()->eq($txAlias . '.category_id', $catAlias . '.id'));
        self::excludeReportExcludedCategories($qb, $catAlias, $viewerUserId);
    }

    /**
     * Exclude rows that must not count toward spending/income/report aggregates:
     *  - scheduled future transactions (allows cleared, NULL status (pre-migration),
     *    and scheduled transactions whose date has arrived); and
     *  - bank legs that fund a pension contribution / withdrawal (#304) — the money
     *    moved to/from a pension, so it is a transfer, never spending or income.
     *
     * Called by every spending/income/category/tag/trend/cash-flow aggregate (and
     * never by balance or list queries, where the pension leg must still appear),
     * so it is the single place both exclusions belong.
     */
    public static function excludeScheduledFuture(IQueryBuilder $qb, string $alias = 't'): void {
        $today = date('Y-m-d');
        $qb->andWhere(
            $qb->expr()->orX(
                $qb->expr()->neq("{$alias}.status", $qb->createNamedParameter('scheduled')),
                $qb->expr()->isNull("{$alias}.status"),
                $qb->expr()->lte("{$alias}.date", $qb->createNamedParameter($today))
            )
        );
        $qb->andWhere($qb->expr()->isNull("{$alias}.pension_contrib_id"));
    }

    // ==================== Filters and sums shared by the aggregates ====================

    /**
     * Apply tag filtering to a query builder: transactions carrying any of
     * $tagIds (OR logic), plus — with $includeUntagged — those carrying no
     * tag at all.
     *
     * Correlated EXISTS, never a join: joined, a transaction carrying two of
     * the chosen tags became two rows and every SUM over it counted its money
     * twice.
     *
     * @param IQueryBuilder $qb Query builder to modify
     * @param int[] $tagIds Array of tag IDs to filter by
     * @param bool $includeUntagged Include transactions without tags
     */
    public static function applyTagFilter(IQueryBuilder $qb, array $tagIds, bool $includeUntagged = true): void {
        if (empty($tagIds)) {
            return;
        }

        $tagTable = $qb->getTableName('budget_transaction_tags');
        $ids = implode(', ', array_map(
            static fn($id) => (string)(int)$id,
            array_values($tagIds)
        ));
        $tagged = "EXISTS (SELECT 1 FROM {$tagTable} btt WHERE btt.transaction_id = t.id AND btt.tag_id IN ({$ids}))";

        if ($includeUntagged) {
            $untagged = "NOT EXISTS (SELECT 1 FROM {$tagTable} btu WHERE btu.transaction_id = t.id)";
            $qb->andWhere($qb->expr()->orX($tagged, $untagged));
        } else {
            $qb->andWhere($tagged);
        }
    }

    /**
     * Signed sum netting the opposite direction: rows of $transactionType
     * count positive, everything else negative. The single authority for the
     * netting CASE — the Budget page, Category Details and the budget report
     * must all agree on what "net spent" means (#360, #361). A COUNT beside
     * it counts BOTH directions, since no type filter accompanies this.
     */
    public static function signedAmountSum(IQueryBuilder $qb, string $transactionType, string $amountColumn): string {
        $primaryType = $qb->createNamedParameter($transactionType);
        return "SUM(CASE WHEN t.type = {$primaryType} THEN {$amountColumn} ELSE -{$amountColumn} END)";
    }

    /**
     * Select gross income (credits) and expenses (debits) of $alloc's amount
     * as 'income' and 'expenses'.
     */
    public static function selectIncomeExpenses(IQueryBuilder $qb, string $alloc): void {
        $qb->selectAlias(
                $qb->createFunction("SUM(CASE WHEN t.type = 'credit' THEN {$alloc}.amount ELSE 0 END)"),
                'income'
            )
            ->selectAlias(
                $qb->createFunction("SUM(CASE WHEN t.type = 'debit' THEN {$alloc}.amount ELSE 0 END)"),
                'expenses'
            );
    }

    // ==================== Two-half report aggregates ====================

    /**
     * Scope ONE half of a report aggregate to the rows every report grouping
     * must agree on, so month, category, vendor, account and tag views of the
     * same period add up to the same money.
     *
     * A report aggregate runs twice, once per half of the direct/split
     * partition directRowPredicate() explains (#360):
     *  - the direct half ($splitHalf false) reads the transaction's own row:
     *    its amount and its category;
     *  - the split half ($splitHalf true) reads a split parent's parts
     *    (budget_tx_splits as 's'): each part's amount and category.
     * The caller merges the two in PHP — never a join into one query, for the
     * reason #359 gives.
     *
     * Either way the category the money is filed under is left-joined as
     * 'exc' and run through the choke point, so a category flagged
     * excluded_from_reports (#219), or muted by the viewing user, drops out
     * whole — including the part of a split filed under it, which a filter on
     * the parent row alone cannot see. Uncategorised money is kept.
     *
     * Also applied: the viewer's account scope with report-excluded accounts
     * dropped in the all-accounts view (#286), the scheduled-future and
     * pension-funding exclusion (#304), the single account when one is
     * selected, and — when $excludeTransfers — linked transfers (#349). A
     * linked transfer is internal money movement, never income or spending,
     * so the all-accounts view drops both legs (#262); a single-account view
     * keeps its own legs, because the money really did enter or leave it.
     *
     * Adds FROM budget_transactions 't' joined to budget_accounts 'a'.
     *
     * @param int[]|null $visibleAccountIds
     * @return string alias carrying this half's money: 't' or 's'. Read the
     *                amount from "{alias}.amount" and the category from
     *                "{alias}.category_id".
     */
    public static function scopeReportHalf(
        IQueryBuilder $qb,
        bool $splitHalf,
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate,
        ?array $visibleAccountIds,
        bool $excludeTransfers
    ): string {
        $qb->from('budget_transactions', 't')
            ->innerJoin('t', 'budget_accounts', 'a', $qb->expr()->eq('t.account_id', 'a.id'));

        // A specific (possibly report-excluded) account selected upstream still
        // reports; only the all-accounts view drops excluded ones (#309)
        self::applyUserScope($qb, $userId, $visibleAccountIds, $accountId !== null);
        if ($accountId !== null) {
            $qb->andWhere($qb->expr()->eq('t.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));
        }

        $qb->andWhere($qb->expr()->gte('t.date', $qb->createNamedParameter($startDate)))
            ->andWhere($qb->expr()->lte('t.date', $qb->createNamedParameter($endDate)));

        self::excludeScheduledFuture($qb);
        if ($excludeTransfers) {
            $qb->andWhere($qb->expr()->isNull('t.linked_transaction_id'));
        }

        if ($splitHalf) {
            $qb->innerJoin('t', 'budget_tx_splits', 's', $qb->expr()->eq('s.transaction_id', 't.id'))
                ->andWhere(self::splitParentPredicate($qb));
            $alloc = 's';
        } else {
            $qb->andWhere(self::directRowPredicate($qb));
            $alloc = 't';
        }

        self::leftJoinExcludeReportCategories($qb, $alloc, 'exc', $userId);

        return $alloc;
    }

    /**
     * Run a report aggregate over both halves of the direct/split partition
     * and return each half's rows: [direct rows, split rows].
     *
     * $build receives a fresh query builder already scoped by
     * scopeReportHalf(), plus the alias carrying that half's money.
     *
     * @param callable(IQueryBuilder, string): void $build
     * @param int[]|null $visibleAccountIds
     * @return array{0: array[], 1: array[]}
     */
    public static function fetchReportHalves(
        IDBConnection $db,
        string $userId,
        ?int $accountId,
        string $startDate,
        string $endDate,
        ?array $visibleAccountIds,
        bool $excludeTransfers,
        callable $build
    ): array {
        $run = static function (bool $splitHalf) use ($db, $userId, $accountId, $startDate, $endDate, $visibleAccountIds, $excludeTransfers, $build): array {
            $qb = $db->getQueryBuilder();
            $alloc = self::scopeReportHalf(
                $qb, $splitHalf, $userId, $accountId, $startDate, $endDate, $visibleAccountIds, $excludeTransfers
            );
            $build($qb, $alloc);

            $result = $qb->executeQuery();
            $rows = $result->fetchAll();
            $result->closeCursor();
            return $rows;
        };

        return [$run(false), $run(true)];
    }

    /**
     * Merge the two halves of a report aggregate row by row: rows sharing the
     * values of $keyColumns are one group, their $moneyColumns added through
     * MoneyCalculator (#274) and their $countColumns added as integers.
     *
     * A transaction lives in exactly one half, and every grouping key used
     * here (month, vendor, account, tag, ...) belongs to the transaction, so
     * its parts all land in one group — adding counts never counts a
     * transaction twice.
     *
     * Money columns come back as floats, count columns as ints; first-seen
     * order is kept, so callers sort the result themselves.
     *
     * @param string[] $keyColumns
     * @param string[] $moneyColumns
     * @param string[] $countColumns
     * @return array[]
     */
    public static function mergeReportHalves(array $direct, array $split, array $keyColumns, array $moneyColumns, array $countColumns = []): array {
        $merged = [];
        foreach ([$direct, $split] as $rows) {
            foreach ($rows as $row) {
                $key = implode("\x1f", array_map(static fn(string $col) => (string)($row[$col] ?? ''), $keyColumns));
                if (!isset($merged[$key])) {
                    $merged[$key] = $row;
                    foreach ($moneyColumns as $col) {
                        $merged[$key][$col] = '0';
                    }
                    foreach ($countColumns as $col) {
                        $merged[$key][$col] = 0;
                    }
                }
                foreach ($moneyColumns as $col) {
                    $merged[$key][$col] = MoneyCalculator::add(
                        $merged[$key][$col], self::sqlMoney($row[$col] ?? null), self::MERGE_SCALE
                    );
                }
                foreach ($countColumns as $col) {
                    $merged[$key][$col] += (int)($row[$col] ?? 0);
                }
            }
        }

        foreach ($merged as &$row) {
            foreach ($moneyColumns as $col) {
                $row[$col] = MoneyCalculator::toFloat($row[$col]);
            }
        }
        unset($row);

        return array_values($merged);
    }

    /**
     * A money value as the database returned it, in a form bcmath accepts.
     * SQLite hands back a netted REAL sum as text such as "1.4e-14", which
     * bcadd() rejects outright, so anything in exponent form goes through
     * float (MoneyCalculator prints floats without an exponent).
     */
    public static function sqlMoney(mixed $value): float|string {
        if ($value === null || $value === '') {
            return '0';
        }
        if (is_int($value)) {
            return (string)$value;
        }
        if (is_string($value) && stripos($value, 'e') !== false) {
            return (float)$value;
        }
        return is_float($value) ? $value : (string)$value;
    }
}
