<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCA\Budget\Service\MoneyCalculator;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * The report queries over budget_transactions: the spending and income
 * groupings of the Reports page (by month, vendor/source, account and tag),
 * the cash-flow and income-vs-expense trend series, and the tag reports.
 *
 * Split out of TransactionMapper, which is left with the entity queries and
 * the budget/category aggregates. Every query here scopes its rows through
 * ReportScope — the one home of the report choke points — so the groupings
 * of one period agree with each other (#219).
 */
class TransactionReportQueries {
	private const TABLE = 'budget_transactions';

	/** getCategoryNetByMonth()'s key for money with no category (ids start at 1). */
	public const UNCATEGORIZED = 0;

	public function __construct(
		private IDBConnection $db,
	) {
	}

	// ==================== Spending and income groupings ====================

	/**
	 * Get spending grouped by month.
	 *
	 * Report-scoped like every other grouping of the spending report
	 * (scopeReportHalf()): excluded and muted categories — split parts
	 * included — never count, and the all-accounts view leaves transfers out
	 * (#349), so month, category, vendor, account and tag views of one period
	 * agree.
	 *
	 * @return array<int, array{month: string, total: float, count: int}>
	 */
	public function getSpendingByMonth(string $userId, ?int $accountId, string $startDate, string $endDate, ?array $visibleAccountIds = null): array {
		return $this->getTotalsByMonth($userId, $accountId, $startDate, $endDate, $visibleAccountIds, 'debit');
	}

	/**
	 * Get spending grouped by vendor: the $limit largest named vendors
	 * (transactions without a vendor are left out), report-scoped like the
	 * other groupings (see getSpendingByMonth()).
	 */
	public function getSpendingByVendor(string $userId, ?int $accountId, string $startDate, string $endDate, int $limit = 15, ?array $visibleAccountIds = null): array {
		return $this->getTotalsByVendor($userId, $accountId, $startDate, $endDate, $limit, $visibleAccountIds, 'debit', false, 'Unknown');
	}

	/**
	 * Get income grouped by month, report-scoped like the spending groupings
	 * (see getSpendingByMonth()).
	 *
	 * @return array<int, array{month: string, total: float, count: int}>
	 */
	public function getIncomeByMonth(string $userId, ?int $accountId, string $startDate, string $endDate, ?array $visibleAccountIds = null): array {
		return $this->getTotalsByMonth($userId, $accountId, $startDate, $endDate, $visibleAccountIds, 'credit');
	}

	/**
	 * Get income grouped by source (vendor): the $limit largest, with income
	 * that names no source grouped as one "Unknown Source" row.
	 */
	public function getIncomeBySource(string $userId, ?int $accountId, string $startDate, string $endDate, int $limit = 15, ?array $visibleAccountIds = null): array {
		return $this->getTotalsByVendor($userId, $accountId, $startDate, $endDate, $limit, $visibleAccountIds, 'credit', true, 'Unknown Source');
	}

	/**
	 * One direction's report-scoped totals per month.
	 *
	 * @param int[]|null $visibleAccountIds
	 * @return array<int, array{month: string, total: float, count: int}>
	 */
	private function getTotalsByMonth(string $userId, ?int $accountId, string $startDate, string $endDate, ?array $visibleAccountIds, string $type): array {
		[$direct, $split] = ReportScope::fetchReportHalves(
			$this->db, $userId, $accountId, $startDate, $endDate, $visibleAccountIds, $accountId === null,
			function (IQueryBuilder $qb, string $alloc) use ($type): void {
				$qb->select($qb->createFunction(ReportScope::monthExpr() . ' as month'))
					->selectAlias($qb->func()->sum("{$alloc}.amount"), 'total')
					->selectAlias($qb->createFunction('COUNT(DISTINCT t.id)'), 'count')
					->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter($type)))
					->groupBy($qb->createFunction(ReportScope::monthExpr()));
			}
		);

		$rows = ReportScope::mergeReportHalves($direct, $split, ['month'], ['total'], ['count']);
		usort($rows, static fn (array $a, array $b) => strcmp((string)$a['month'], (string)$b['month']));

		return array_map(static fn (array $row) => [
			'month' => (string)$row['month'],
			'total' => $row['total'],
			'count' => $row['count'],
		], $rows);
	}

	/**
	 * One direction's report-scoped totals per vendor, largest first, cut to
	 * $limit after the two halves are merged (cutting each half first could
	 * drop a vendor whose money is spread across both).
	 *
	 * @param int[]|null $visibleAccountIds
	 */
	private function getTotalsByVendor(
		string $userId,
		?int $accountId,
		string $startDate,
		string $endDate,
		int $limit,
		?array $visibleAccountIds,
		string $type,
		bool $includeUnnamed,
		string $unnamedLabel,
	): array {
		[$direct, $split] = ReportScope::fetchReportHalves(
			$this->db, $userId, $accountId, $startDate, $endDate, $visibleAccountIds, $accountId === null,
			function (IQueryBuilder $qb, string $alloc) use ($type, $includeUnnamed): void {
				$qb->select('t.vendor')
					->selectAlias($qb->func()->sum("{$alloc}.amount"), 'total')
					->selectAlias($qb->createFunction('COUNT(DISTINCT t.id)'), 'count')
					->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter($type)))
					->groupBy('t.vendor');
				if (!$includeUnnamed) {
					$qb->andWhere($qb->expr()->isNotNull('t.vendor'))
						->andWhere($qb->expr()->neq('t.vendor', $qb->createNamedParameter('')));
				}
			}
		);

		$rows = ReportScope::mergeReportHalves($direct, $split, ['vendor'], ['total'], ['count']);
		usort($rows, static fn (array $a, array $b) => $b['total'] <=> $a['total']);

		return array_map(static fn (array $row) => [
			'name' => $row['vendor'] ?: $unnamedLabel,
			'unknown' => !$row['vendor'],
			'total' => $row['total'],
			'count' => $row['count'],
		], array_slice($rows, 0, $limit));
	}

	/**
	 * Get spending by account with aggregation in SQL (avoids N+1),
	 * report-scoped like the other spending groupings (see
	 * getSpendingByMonth()).
	 *
	 * With $accountId the breakdown is that one account's row, its own
	 * transfer legs kept, as every single-account report view does (#349);
	 * without it, every account in view with transfers left out.
	 *
	 * @param int[]|null $visibleAccountIds
	 */
	public function getSpendingByAccountAggregated(string $userId, string $startDate, string $endDate, ?array $visibleAccountIds = null, ?int $accountId = null): array {
		[$direct, $split] = ReportScope::fetchReportHalves(
			$this->db, $userId, $accountId, $startDate, $endDate, $visibleAccountIds, $accountId === null,
			function (IQueryBuilder $qb, string $alloc): void {
				$qb->select('a.id', 'a.name')
					->selectAlias($qb->func()->sum("{$alloc}.amount"), 'total')
					->selectAlias($qb->createFunction('COUNT(DISTINCT t.id)'), 'count')
					->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
					->groupBy('a.id', 'a.name');
			}
		);

		$rows = ReportScope::mergeReportHalves($direct, $split, ['id'], ['total'], ['count']);
		usort($rows, static fn (array $a, array $b) => $b['total'] <=> $a['total']);

		return array_map(static fn (array $row) => [
			'name' => $row['name'],
			'total' => $row['total'],
			'count' => $row['count'],
			'average' => $row['count'] > 0 ? $row['total'] / $row['count'] : 0
		], $rows);
	}

	// ==================== Cash flow and income/expense trends ====================

	/**
	 * Get cash flow data by month (income and expenses combined).
	 *
	 * Report-scoped (scopeReportHalf()): excluded and muted categories —
	 * split parts included — never count, so the cash-flow report agrees with
	 * the income and spending reports for the same period. Transfers are
	 * dropped only when the caller asks, as before: the all-accounts view
	 * does, a single account keeps its own legs.
	 *
	 * @param int[] $tagIds Optional tag filter (OR logic)
	 * @param bool $includeUntagged Include untagged transactions when filtering by tags
	 * @return array<int, array{month: string, income: float, expenses: float, net: float, count: int}>
	 */
	public function getCashFlowByMonth(
		string $userId,
		?int $accountId,
		string $startDate,
		string $endDate,
		array $tagIds = [],
		bool $includeUntagged = true,
		bool $excludeTransfers = false,
		?array $visibleAccountIds = null,
	): array {
		[$direct, $split] = ReportScope::fetchReportHalves(
			$this->db, $userId, $accountId, $startDate, $endDate, $visibleAccountIds, $excludeTransfers,
			function (IQueryBuilder $qb, string $alloc) use ($tagIds, $includeUntagged): void {
				$qb->select($qb->createFunction(ReportScope::monthExpr() . ' as month'));
				ReportScope::selectIncomeExpenses($qb, $alloc);
				$qb->selectAlias($qb->createFunction('COUNT(DISTINCT t.id)'), 'count');
				ReportScope::applyTagFilter($qb, $tagIds, $includeUntagged);
				$qb->groupBy($qb->createFunction(ReportScope::monthExpr()));
			}
		);

		$rows = ReportScope::mergeReportHalves($direct, $split, ['month'], ['income', 'expenses'], ['count']);
		usort($rows, static fn (array $a, array $b) => strcmp((string)$a['month'], (string)$b['month']));

		return array_map(fn (array $row) => [
			'month' => (string)$row['month'],
			'income' => $row['income'],
			'expenses' => $row['expenses'],
			'net' => MoneyCalculator::toFloat(MoneyCalculator::subtract($row['income'], $row['expenses'], ReportScope::MERGE_SCALE)),
			'count' => $row['count'],
		], $rows);
	}

	/**
	 * Get cash flow by month grouped by account for currency conversion.
	 * Returns per-account-per-month rows so the aggregator can convert before summing.
	 * Report-scoped exactly like getCashFlowByMonth(), all-accounts view.
	 *
	 * @param int[] $tagIds Optional tag filter (OR logic)
	 * @param bool $includeUntagged Include untagged transactions when filtering by tags
	 * @return array<int, array{month: string, account_id: int, income: float, expenses: float, net: float}>
	 */
	public function getCashFlowByMonthByAccount(
		string $userId,
		string $startDate,
		string $endDate,
		array $tagIds = [],
		bool $includeUntagged = true,
		bool $excludeTransfers = false,
		?array $visibleAccountIds = null,
	): array {
		[$direct, $split] = ReportScope::fetchReportHalves(
			$this->db, $userId, null, $startDate, $endDate, $visibleAccountIds, $excludeTransfers,
			function (IQueryBuilder $qb, string $alloc) use ($tagIds, $includeUntagged): void {
				$qb->select('t.account_id')
					->addSelect($qb->createFunction(ReportScope::monthExpr() . ' as month'));
				ReportScope::selectIncomeExpenses($qb, $alloc);
				ReportScope::applyTagFilter($qb, $tagIds, $includeUntagged);
				$qb->groupBy('t.account_id', $qb->createFunction(ReportScope::monthExpr()));
			}
		);

		$rows = ReportScope::mergeReportHalves($direct, $split, ['account_id', 'month'], ['income', 'expenses']);
		usort($rows, static fn (array $a, array $b) => strcmp((string)$a['month'], (string)$b['month']));

		return array_map(static fn (array $row) => [
			'month' => (string)$row['month'],
			'account_id' => (int)$row['account_id'],
			'income' => $row['income'],
			'expenses' => $row['expenses'],
			'net' => MoneyCalculator::toFloat(MoneyCalculator::subtract($row['income'], $row['expenses'], ReportScope::MERGE_SCALE)),
		], $rows);
	}

	/**
	 * Get monthly aggregates for trend data (single pass for all months).
	 *
	 * The same report-scoped figures as getCashFlowByMonth() — the summary's
	 * income/expense chart and the cash-flow report must never disagree on a
	 * month — minus the net and count columns.
	 *
	 * @param int[] $tagIds Optional tag filter (OR logic)
	 * @param bool $includeUntagged Include untagged transactions when filtering by tags
	 * @return array<int, array{month: string, income: float, expenses: float}>
	 */
	public function getMonthlyTrendData(
		string $userId,
		?int $accountId,
		string $startDate,
		string $endDate,
		array $tagIds = [],
		bool $includeUntagged = true,
		bool $excludeTransfers = false,
		?array $visibleAccountIds = null,
	): array {
		return array_map(static fn (array $row) => [
			'month' => $row['month'],
			'income' => $row['income'],
			'expenses' => $row['expenses'],
		], $this->getCashFlowByMonth(
			$userId, $accountId, $startDate, $endDate, $tagIds, $includeUntagged, $excludeTransfers, $visibleAccountIds
		));
	}

	/**
	 * Get monthly trend data grouped by account for currency conversion.
	 * Returns per-account-per-month rows so the aggregator can convert before summing.
	 * Same figures as getCashFlowByMonthByAccount(), minus the net column.
	 *
	 * @param int[] $tagIds Optional tag filter (OR logic)
	 * @param bool $includeUntagged Include untagged transactions when filtering by tags
	 * @return array<int, array{month: string, account_id: int, income: float, expenses: float}>
	 */
	public function getMonthlyTrendDataByAccount(
		string $userId,
		string $startDate,
		string $endDate,
		array $tagIds = [],
		bool $includeUntagged = true,
		bool $excludeTransfers = false,
		?array $visibleAccountIds = null,
	): array {
		return array_map(static fn (array $row) => [
			'month' => $row['month'],
			'account_id' => $row['account_id'],
			'income' => $row['income'],
			'expenses' => $row['expenses'],
		], $this->getCashFlowByMonthByAccount(
			$userId, $startDate, $endDate, $tagIds, $includeUntagged, $excludeTransfers, $visibleAccountIds
		));
	}

	// ==================== Tag reports ====================

	/**
	 * Get spending grouped by tags within a specific tag set, report-scoped
	 * like the other spending groupings (see getSpendingByMonth()).
	 *
	 * @param string $userId
	 * @param int $tagSetId Tag set to group by
	 * @param string $startDate
	 * @param string $endDate
	 * @param int|null $accountId Optional account filter
	 * @param int|null $categoryId Optional category filter (a split counts
	 *                             the parts filed under it)
	 * @return array Array of [tagId, tagName, color, total, count]
	 */
	public function getSpendingByTag(
		string $userId,
		int $tagSetId,
		string $startDate,
		string $endDate,
		?int $accountId = null,
		?int $categoryId = null,
		?array $visibleAccountIds = null,
	): array {
		return $this->getTotalsByTag($userId, $tagSetId, $startDate, $endDate, $accountId, $categoryId, $visibleAccountIds, 'debit');
	}

	/**
	 * Get income grouped by tags within a specific tag set, report-scoped
	 * like getSpendingByTag().
	 *
	 * @param string $userId
	 * @param int $tagSetId Tag set to group by
	 * @param string $startDate
	 * @param string $endDate
	 * @param int|null $accountId Optional account filter
	 * @param int|null $categoryId Optional category filter
	 * @return array Array of [tagId, tagName, color, total, count]
	 */
	public function getIncomeByTag(
		string $userId,
		int $tagSetId,
		string $startDate,
		string $endDate,
		?int $accountId = null,
		?int $categoryId = null,
		?array $visibleAccountIds = null,
	): array {
		return $this->getTotalsByTag($userId, $tagSetId, $startDate, $endDate, $accountId, $categoryId, $visibleAccountIds, 'credit');
	}

	/**
	 * One direction's report-scoped totals per tag of one tag set.
	 *
	 * @param int[]|null $visibleAccountIds
	 */
	private function getTotalsByTag(
		string $userId,
		int $tagSetId,
		string $startDate,
		string $endDate,
		?int $accountId,
		?int $categoryId,
		?array $visibleAccountIds,
		string $type,
	): array {
		[$direct, $split] = ReportScope::fetchReportHalves(
			$this->db, $userId, $accountId, $startDate, $endDate, $visibleAccountIds, $accountId === null,
			function (IQueryBuilder $qb, string $alloc) use ($tagSetId, $categoryId, $type): void {
				$qb->select('tag.id', 'tag.name', 'tag.color')
					->selectAlias($qb->func()->sum("{$alloc}.amount"), 'total')
					->selectAlias($qb->createFunction('COUNT(DISTINCT t.id)'), 'count')
					->innerJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
					->innerJoin('tt', 'budget_tags', 'tag', $qb->expr()->eq('tt.tag_id', 'tag.id'))
					->andWhere($qb->expr()->eq('tag.tag_set_id', $qb->createNamedParameter($tagSetId, IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter($type)))
					->groupBy('tag.id', 'tag.name', 'tag.color');
				if ($categoryId !== null) {
					$qb->andWhere($qb->expr()->eq("{$alloc}.category_id", $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
				}
			}
		);

		$rows = ReportScope::mergeReportHalves($direct, $split, ['id'], ['total'], ['count']);
		usort($rows, static fn (array $a, array $b) => $b['total'] <=> $a['total']);

		return array_map(static fn (array $row) => [
			'tagId' => (int)$row['id'],
			'name' => $row['name'],
			'color' => $row['color'],
			'total' => $row['total'],
			'count' => $row['count'],
		], $rows);
	}

	/**
	 * Get tag dimensions breakdown for spending in a category
	 * Returns spending grouped by each tag set associated with the category
	 *
	 * Report-scoped like getSpendingByTag() (scopeReportHalf()): a split
	 * counts the parts filed under the category, and the all-accounts view
	 * leaves transfers out.
	 *
	 * @param string $userId
	 * @param int $categoryId
	 * @param string $startDate
	 * @param string $endDate
	 * @param int|null $accountId Optional account filter
	 * @return array Array indexed by tag set ID, containing tag breakdowns
	 */
	public function getTagDimensionsForCategory(
		string $userId,
		int $categoryId,
		string $startDate,
		string $endDate,
		?int $accountId = null,
		?array $visibleAccountIds = null,
	): array {
		[$direct, $split] = ReportScope::fetchReportHalves(
			$this->db, $userId, $accountId, $startDate, $endDate, $visibleAccountIds, $accountId === null,
			function (IQueryBuilder $qb, string $alloc) use ($categoryId): void {
				$qb->select('ts.id as tag_set_id', 'ts.name as tag_set_name', 'tag.id as tag_id', 'tag.name as tag_name', 'tag.color')
					->selectAlias($qb->func()->sum("{$alloc}.amount"), 'total')
					->selectAlias($qb->createFunction('COUNT(DISTINCT t.id)'), 'count')
					->innerJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
					->innerJoin('tt', 'budget_tags', 'tag', $qb->expr()->eq('tt.tag_id', 'tag.id'))
					->innerJoin('tag', 'budget_tag_sets', 'ts', $qb->expr()->eq('tag.tag_set_id', 'ts.id'))
					->andWhere($qb->expr()->eq("{$alloc}.category_id", $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->eq('ts.category_id', $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)))
					->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
					->groupBy('ts.id', 'ts.name', 'tag.id', 'tag.name', 'tag.color');
			}
		);

		$rows = ReportScope::mergeReportHalves($direct, $split, ['tag_set_id', 'tag_id'], ['total'], ['count']);
		usort($rows, static fn (array $a, array $b) => ((int)$a['tag_set_id'] <=> (int)$b['tag_set_id']) ?: ($b['total'] <=> $a['total']));

		// Group by tag set
		$dimensions = [];
		foreach ($rows as $row) {
			$tagSetId = (int)$row['tag_set_id'];
			if (!isset($dimensions[$tagSetId])) {
				$dimensions[$tagSetId] = [
					'tagSetId' => $tagSetId,
					'tagSetName' => $row['tag_set_name'],
					'tags' => []
				];
			}
			$dimensions[$tagSetId]['tags'][] = [
				'tagId' => (int)$row['tag_id'],
				'name' => $row['tag_name'],
				'color' => $row['color'],
				'total' => $row['total'],
				'count' => $row['count']
			];
		}

		return array_values($dimensions);
	}

	/**
	 * Each tagged transaction's report-scoped amount and its tags, for the
	 * tag reports that group by transaction (combinations, cross-tab).
	 *
	 * Report-scoped like the other groupings (scopeReportHalf()): excluded
	 * and muted categories never count - a split contributes only its parts
	 * in categories that do - and neither do future scheduled rows, pension
	 * legs or, in the all-accounts view, transfers. Each (transaction, tag)
	 * row carries the transaction's in-scope amount: a transaction lives in
	 * one half only, and every one of its tags sees the same parts.
	 *
	 * @param int[]|null $tagSetIds Only tags of these sets (null: any tag)
	 * @param int[]|null $visibleAccountIds
	 * @return array<int, array{amount: float, tags: list<array{id: int, name: string, color: ?string, tagSetId: int}>}>
	 *                                                                                                                   transaction id => its amount and tags, ordered by transaction id
	 */
	private function getTaggedTransactionAmounts(
		string $userId,
		string $startDate,
		string $endDate,
		?int $accountId,
		?int $categoryId,
		?array $tagSetIds,
		?array $visibleAccountIds,
	): array {
		[$direct, $split] = ReportScope::fetchReportHalves(
			$this->db, $userId, $accountId, $startDate, $endDate, $visibleAccountIds, $accountId === null,
			function (IQueryBuilder $qb, string $alloc) use ($categoryId, $tagSetIds): void {
				$qb->select('t.id', 'tag.id as tag_id', 'tag.name as tag_name', 'tag.color', 'tag.tag_set_id')
					->selectAlias($qb->func()->sum("{$alloc}.amount"), 'amount')
					->innerJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
					->innerJoin('tt', 'budget_tags', 'tag', $qb->expr()->eq('tt.tag_id', 'tag.id'))
					->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
					->groupBy('t.id', 'tag.id', 'tag.name', 'tag.color', 'tag.tag_set_id');
				if ($categoryId !== null) {
					$qb->andWhere($qb->expr()->eq("{$alloc}.category_id", $qb->createNamedParameter($categoryId, IQueryBuilder::PARAM_INT)));
				}
				if ($tagSetIds !== null) {
					$qb->andWhere($qb->expr()->in('tag.tag_set_id', $qb->createNamedParameter($tagSetIds, IQueryBuilder::PARAM_INT_ARRAY)));
				}
			}
		);

		$transactions = [];
		foreach ([$direct, $split] as $rows) {
			foreach ($rows as $row) {
				$txId = (int)$row['id'];
				$transactions[$txId] ??= [
					'amount' => MoneyCalculator::toFloat(ReportScope::sqlMoney($row['amount'] ?? null)),
					'tags' => [],
				];
				$transactions[$txId]['tags'][] = [
					'id' => (int)$row['tag_id'],
					'name' => (string)$row['tag_name'],
					'color' => $row['color'] ?? null,
					'tagSetId' => (int)$row['tag_set_id'],
				];
			}
		}
		ksort($transactions);

		return $transactions;
	}

	/**
	 * Get spending by tag combinations (transactions with specific sets of tags),
	 * report-scoped like the other tag reports (getTaggedTransactionAmounts()).
	 *
	 * @param string $userId
	 * @param string $startDate
	 * @param string $endDate
	 * @param int|null $accountId Optional account filter
	 * @param int|null $categoryId Optional category filter (a split counts
	 *                             the parts filed under it)
	 * @param int $minCombinationSize Minimum number of tags in combination (default 2)
	 * @param int $limit Maximum number of combinations to return
	 * @return array Array of [tagIds => int[], tagNames => string[], total, count]
	 */
	public function getSpendingByTagCombination(
		string $userId,
		string $startDate,
		string $endDate,
		?int $accountId = null,
		?int $categoryId = null,
		int $minCombinationSize = 2,
		int $limit = 50,
		?array $visibleAccountIds = null,
	): array {
		$transactions = $this->getTaggedTransactionAmounts(
			$userId, $startDate, $endDate, $accountId, $categoryId, null, $visibleAccountIds
		);

		// Group by tag combination
		$combinations = [];
		foreach ($transactions as $tx) {
			$tags = $tx['tags'];
			if (count($tags) < $minCombinationSize) {
				continue;
			}

			// Sort tags by ID for consistent combination key
			usort($tags, fn ($a, $b) => $a['id'] <=> $b['id']);
			$tagIds = array_column($tags, 'id');
			$tagNames = array_column($tags, 'name');
			$key = implode(',', $tagIds);

			if (!isset($combinations[$key])) {
				$combinations[$key] = [
					'tagIds' => $tagIds,
					'tagNames' => $tagNames,
					'total' => '0',
					'count' => 0
				];
			}

			$combinations[$key]['total'] = MoneyCalculator::add($combinations[$key]['total'], $tx['amount'], ReportScope::MERGE_SCALE);
			$combinations[$key]['count']++;
		}

		foreach ($combinations as &$combination) {
			$combination['total'] = MoneyCalculator::toFloat($combination['total']);
		}
		unset($combination);

		// Sort by total descending
		usort($combinations, fn ($a, $b) => $b['total'] <=> $a['total']);

		return array_slice($combinations, 0, $limit);
	}

	/**
	 * Get cross-tabulation (pivot table) of spending by two tag sets
	 * Returns a matrix where rows are tags from tagSet1 and columns are tags from tagSet2
	 *
	 * Report-scoped like the other tag reports (getTaggedTransactionAmounts()),
	 * over every account the viewer can see - shared ones included - that
	 * $visibleAccountIds names.
	 *
	 * @param string $userId
	 * @param int $tagSetId1 First tag set (rows)
	 * @param int $tagSetId2 Second tag set (columns)
	 * @param string $startDate
	 * @param string $endDate
	 * @param int|null $accountId Optional account filter
	 * @param int|null $categoryId Optional category filter
	 * @param int[]|null $visibleAccountIds
	 * @return array ['rows' => tags from set 1, 'columns' => tags from set 2, 'data' => matrix]
	 */
	public function getTagCrossTabulation(
		string $userId,
		int $tagSetId1,
		int $tagSetId2,
		string $startDate,
		string $endDate,
		?int $accountId = null,
		?int $categoryId = null,
		?array $visibleAccountIds = null,
	): array {
		$transactions = $this->getTaggedTransactionAmounts(
			$userId, $startDate, $endDate, $accountId, $categoryId, [$tagSetId1, $tagSetId2], $visibleAccountIds
		);

		$rowTags = []; // Tags from tagSet1
		$colTags = []; // Tags from tagSet2
		$matrix = [];

		foreach ($transactions as $tx) {
			$tag1 = null;
			$tag2 = null;
			foreach ($tx['tags'] as $tag) {
				$entry = ['id' => $tag['id'], 'name' => $tag['name'], 'color' => $tag['color']];
				if ($tag['tagSetId'] === $tagSetId1) {
					$tag1 = $tag['id'];
					$rowTags[$tag['id']] ??= $entry;
				} elseif ($tag['tagSetId'] === $tagSetId2) {
					$tag2 = $tag['id'];
					$colTags[$tag['id']] ??= $entry;
				}
			}

			if ($tag1 !== null && $tag2 !== null) {
				$key = $tag1 . '_' . $tag2;
				$matrix[$key] ??= [
					'rowTagId' => $tag1,
					'colTagId' => $tag2,
					'total' => '0',
					'count' => 0
				];
				$matrix[$key]['total'] = MoneyCalculator::add($matrix[$key]['total'], $tx['amount'], ReportScope::MERGE_SCALE);
				$matrix[$key]['count']++;
			}
		}

		foreach ($matrix as &$cell) {
			$cell['total'] = MoneyCalculator::toFloat($cell['total']);
		}
		unset($cell);

		return [
			'rows' => array_values($rowTags),
			'columns' => array_values($colTags),
			'data' => array_values($matrix)
		];
	}

	/**
	 * Get monthly trend data for specific tags, report-scoped like the other
	 * tag reports and over every account $visibleAccountIds names.
	 *
	 * @param string $userId
	 * @param int[] $tagIds Tags to track
	 * @param string $startDate
	 * @param string $endDate
	 * @param int|null $accountId Optional account filter
	 * @param int[]|null $visibleAccountIds
	 * @return array Array of [month, tagId, tagName, amount]
	 */
	public function getTagTrendByMonth(
		string $userId,
		array $tagIds,
		string $startDate,
		string $endDate,
		?int $accountId = null,
		?array $visibleAccountIds = null,
	): array {
		if (empty($tagIds)) {
			return [];
		}

		[$direct, $split] = ReportScope::fetchReportHalves(
			$this->db, $userId, $accountId, $startDate, $endDate, $visibleAccountIds, $accountId === null,
			function (IQueryBuilder $qb, string $alloc) use ($tagIds): void {
				$qb->select($qb->createFunction(ReportScope::monthExpr() . ' as month'))
					->addSelect('tag.id as tag_id', 'tag.name as tag_name', 'tag.color')
					->selectAlias($qb->func()->sum("{$alloc}.amount"), 'total')
					->innerJoin('t', 'budget_transaction_tags', 'tt', $qb->expr()->eq('t.id', 'tt.transaction_id'))
					->innerJoin('tt', 'budget_tags', 'tag', $qb->expr()->eq('tt.tag_id', 'tag.id'))
					->andWhere($qb->expr()->in('tag.id', $qb->createNamedParameter(array_map('intval', $tagIds), IQueryBuilder::PARAM_INT_ARRAY)))
					->andWhere($qb->expr()->eq('t.type', $qb->createNamedParameter('debit')))
					->groupBy($qb->createFunction(ReportScope::monthExpr()), 'tag.id', 'tag.name', 'tag.color');
			}
		);

		$rows = ReportScope::mergeReportHalves($direct, $split, ['month', 'tag_id'], ['total']);
		usort($rows, static fn (array $a, array $b) => strcmp((string)$a['month'], (string)$b['month']) ?: ((int)$a['tag_id'] <=> (int)$b['tag_id']));

		return array_map(fn ($row) => [
			'month' => (string)$row['month'],
			'tagId' => (int)$row['tag_id'],
			'tagName' => $row['tag_name'],
			'color' => $row['color'],
			'total' => $row['total']
		], $rows);
	}

	// ==================== Category by month ====================

	/**
	 * Signed net per category per month for the Category-by-Month report
	 * (#288): credits positive, debits negative, each split part under its
	 * own category.
	 *
	 * Report-scoped like every other grouping (scopeReportHalf()): a
	 * category excluded from reports or muted by the viewer drops out whole,
	 * split parts included; future scheduled rows and pension legs never
	 * count; and the all-accounts view leaves transfers out (#349), so this
	 * report agrees with the spending and income reports for the same
	 * period. The report used to drop excluded categories in PHP after the
	 * fetch - the owner flag only, never mutes, and transfers still counted.
	 * Uncategorised money (including a split part with no category) is kept
	 * under key UNCATEGORIZED, so the report's totals match the month view.
	 *
	 * @param int[]|null $visibleAccountIds
	 * @return array<int, array<string, float>> categoryId => 'YYYY-MM' => net
	 */
	public function getCategoryNetByMonth(
		string $userId,
		string $startDate,
		string $endDate,
		?int $accountId = null,
		?array $visibleAccountIds = null,
	): array {
		[$direct, $split] = ReportScope::fetchReportHalves(
			$this->db, $userId, $accountId, $startDate, $endDate, $visibleAccountIds, $accountId === null,
			function (IQueryBuilder $qb, string $alloc): void {
				$qb->select("{$alloc}.category_id")
					->addSelect($qb->createFunction(ReportScope::monthExpr() . ' as month'))
					->selectAlias($qb->createFunction(ReportScope::signedAmountSum($qb, 'credit', "{$alloc}.amount")), 'net')
					->groupBy("{$alloc}.category_id")
					->addGroupBy($qb->createFunction(ReportScope::monthExpr()));
			}
		);

		$totals = [];
		foreach (ReportScope::mergeReportHalves($direct, $split, ['category_id', 'month'], ['net']) as $row) {
			$catId = $row['category_id'] === null ? self::UNCATEGORIZED : (int)$row['category_id'];
			$totals[$catId][substr((string)$row['month'], 0, 7)] = $row['net'];
		}

		return $totals;
	}
}
