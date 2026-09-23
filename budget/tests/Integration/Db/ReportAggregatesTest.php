<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionReportQueries;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The report/insight aggregates in TransactionMapper, run as real SQL.
 *
 * The unit tests mock the QueryBuilder, so they prove which calls are made,
 * not what the SQL returns. These check the actual rows that survive the
 * exclusion choke points (report-excluded accounts and categories, future
 * scheduled rows, pension funding legs) and that split parts are counted by
 * their own categories - once per transaction, however many parts it has.
 */
class ReportAggregatesTest extends IntegrationTestCase {
	private TransactionMapper $mapper;
	private TransactionReportQueries $reports;
	private int $accountId;
	private int $excludedAccountId;
	private int $food;
	private int $hidden;
	private string $future;

	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->service(TransactionMapper::class);
		$this->reports = $this->service(TransactionReportQueries::class);
		$this->accountId = $this->makeAccount(['name' => 'Current'])->getId();
		$this->excludedAccountId = $this->makeAccount(['name' => 'Business', 'excludedFromReports' => true])->getId();
		$this->food = $this->makeCategory(['name' => 'Food']);
		$this->hidden = $this->makeCategory(['name' => 'Reimbursable', 'excluded_from_reports' => true]);
		$this->future = date('Y-m-d', strtotime('+20 days'));
	}

	public function testCategorySummaryAddsSplitPartsAndCountsEachTransactionOnce(): void {
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00']);
		// One part in Food, one elsewhere: only the Food share counts
		$this->makeSplitTransaction($this->accountId, [[$this->food, '7.00'], [$this->hidden, '3.00']]);
		// Two parts in Food: one transaction, both amounts
		$this->makeSplitTransaction($this->accountId, [[$this->food, '2.00'], [$this->food, '3.00']]);

		$summary = $this->mapper->getCategorySummary($this->userId, $this->food);

		$this->assertSame(3, $summary['count']);
		$this->assertEqualsWithDelta(22.0, $summary['total'], 0.001);
	}

	public function testCategorySummaryNetsRefundsAgainstSpending(): void {
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '30.00']);
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '4.50', 'type' => 'credit']);

		$summary = $this->mapper->getCategorySummary($this->userId, $this->food);

		$this->assertSame(2, $summary['count']);
		$this->assertEqualsWithDelta(25.5, $summary['total'], 0.001);
	}

	public function testCategorySummaryTreatsNullSplitFlagWithPartsAsASplit(): void {
		// is_split post-dates its default: old parents hold NULL
		$this->makeSplitTransaction($this->accountId, [[$this->food, '4.00']], ['is_split' => null]);

		$summary = $this->mapper->getCategorySummary($this->userId, $this->food);

		$this->assertSame(1, $summary['count']);
		$this->assertEqualsWithDelta(4.0, $summary['total'], 0.001);
	}

	public function testCategorySummaryCountsAnExplicitlyUnsplitRowAtItsOwnAmountOnly(): void {
		// Stray parts left under a row explicitly marked unsplit must not be
		// counted on top of the row's own category (#356/#360)
		$id = $this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '8.00', 'is_split' => false]);
		$this->makeSplit($id, $this->food, '100.00');

		$summary = $this->mapper->getCategorySummary($this->userId, $this->food);

		$this->assertSame(1, $summary['count']);
		$this->assertEqualsWithDelta(8.0, $summary['total'], 0.001);
	}

	public function testCategorySummaryDropsExcludedAccountsFutureScheduledRowsAndPensionLegs(): void {
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00']);
		$this->makeTransaction($this->excludedAccountId, ['category_id' => $this->food, 'amount' => '20.00']);
		$this->makeSplitTransaction($this->excludedAccountId, [[$this->food, '21.00']]);
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '30.00', 'status' => 'scheduled', 'date' => $this->future]);
		$this->makeSplitTransaction($this->accountId, [[$this->food, '31.00']], ['status' => 'scheduled', 'date' => $this->future]);
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '50.00', 'pension_contrib_id' => 999999]);
		// A scheduled row whose date has arrived counts like a cleared one
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '40.00', 'status' => 'scheduled', 'date' => '2026-01-10']);
		// Rows predating the status column hold NULL and count
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '1.00', 'status' => null]);

		$summary = $this->mapper->getCategorySummary($this->userId, $this->food);

		$this->assertSame(3, $summary['count']);
		$this->assertEqualsWithDelta(51.0, $summary['total'], 0.001);
	}

	public function testCategoryMonthlySpendingPutsSplitPartsInTheirMonth(): void {
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00', 'date' => '2026-01-05']);
		$this->makeSplitTransaction($this->accountId, [[$this->food, '2.00'], [$this->food, '3.00']], ['date' => '2026-01-20']);
		$this->makeSplitTransaction($this->accountId, [[$this->food, '6.00'], [$this->hidden, '1.00']], ['date' => '2026-02-11']);

		$series = $this->mapper->getCategoryMonthlySpending(
			$this->userId, $this->food, 6, null, '2026-01-01', '2026-03-31'
		);

		$this->assertSame(['2026-01', '2026-02'], array_column($series, 'month'));
		$this->assertEqualsWithDelta(15.0, $series[0]['total'], 0.001);
		$this->assertSame(2, $series[0]['count']);
		$this->assertSame(1, $series[0]['splitCount']);
		$this->assertEqualsWithDelta(6.0, $series[1]['total'], 0.001);
		$this->assertSame(1, $series[1]['count']);
	}

	public function testCategoryMonthlySpendingHonoursTheExclusionChokePoints(): void {
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00', 'date' => '2026-01-05']);
		$this->makeTransaction($this->excludedAccountId, ['category_id' => $this->food, 'amount' => '20.00', 'date' => '2026-01-06']);
		$this->makeSplitTransaction($this->excludedAccountId, [[$this->food, '21.00']], ['date' => '2026-01-07']);
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '50.00', 'date' => '2026-01-08', 'pension_contrib_id' => 999999]);
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '30.00', 'status' => 'scheduled', 'date' => $this->future]);
		$this->makeSplitTransaction($this->accountId, [[$this->food, '31.00']], ['status' => 'scheduled', 'date' => $this->future]);

		$series = $this->mapper->getCategoryMonthlySpending(
			$this->userId, $this->food, 6, null, '2026-01-01', date('Y-m-d', strtotime('+60 days'))
		);

		$this->assertSame(['2026-01'], array_column($series, 'month'));
		$this->assertEqualsWithDelta(10.0, $series[0]['total'], 0.001);
	}

	public function testSpendingSummaryDropsReportExcludedCategoriesAndAccountsButKeepsSplitParts(): void {
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00']);
		$this->makeTransaction($this->accountId, ['category_id' => $this->hidden, 'amount' => '99.00']);
		$this->makeTransaction($this->excludedAccountId, ['category_id' => $this->food, 'amount' => '20.00']);
		$this->makeSplitTransaction($this->accountId, [[$this->food, '7.00'], [$this->hidden, '3.00']]);

		$rows = $this->mapper->getSpendingSummary($this->userId, '2026-01-01', '2026-12-31');

		$byCategory = [];
		foreach ($rows as $row) {
			$byCategory[(int)$row['id']] = $row;
		}
		$this->assertArrayNotHasKey($this->hidden, $byCategory, 'A report-excluded category must not appear, not even through a split part');
		$this->assertArrayHasKey($this->food, $byCategory);
		$this->assertEqualsWithDelta(17.0, (float)$byCategory[$this->food]['total'], 0.001);
		$this->assertSame(2, (int)$byCategory[$this->food]['count']);
	}

	public function testCashFlowByMonthDropsExcludedAccountsFutureScheduledRowsAndPensionLegs(): void {
		$this->makeTransaction($this->accountId, ['amount' => '100.00', 'type' => 'credit', 'date' => '2026-02-01']);
		$this->makeTransaction($this->accountId, ['amount' => '40.00', 'date' => '2026-02-03']);
		$this->makeTransaction($this->excludedAccountId, ['amount' => '500.00', 'type' => 'credit', 'date' => '2026-02-04']);
		$this->makeTransaction($this->accountId, ['amount' => '60.00', 'date' => '2026-02-05', 'pension_contrib_id' => 999999]);
		$this->makeTransaction($this->accountId, ['amount' => '70.00', 'status' => 'scheduled', 'date' => $this->future]);

		$flow = $this->reports->getCashFlowByMonth(
			$this->userId, null, '2026-01-01', date('Y-m-d', strtotime('+60 days'))
		);

		$this->assertSame(['2026-02'], array_column($flow, 'month'));
		$this->assertEqualsWithDelta(100.0, $flow[0]['income'], 0.001);
		$this->assertEqualsWithDelta(40.0, $flow[0]['expenses'], 0.001);
		$this->assertEqualsWithDelta(60.0, $flow[0]['net'], 0.001);
	}

	public function testCashFlowByMonthCanDropLinkedTransfers(): void {
		$savings = $this->makeAccount(['name' => 'Savings', 'type' => 'savings'])->getId();
		$out = $this->makeTransaction($this->accountId, ['amount' => '25.00', 'date' => '2026-02-10']);
		$in = $this->makeTransaction($savings, ['amount' => '25.00', 'type' => 'credit', 'date' => '2026-02-10', 'linked_transaction_id' => $out]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET linked_transaction_id = ? WHERE id = ?', [$in, $out]);
		$this->makeTransaction($this->accountId, ['amount' => '5.00', 'date' => '2026-02-11']);

		$flow = $this->reports->getCashFlowByMonth($this->userId, null, '2026-02-01', '2026-02-28', [], true, true);

		$this->assertEqualsWithDelta(0.0, $flow[0]['income'], 0.001);
		$this->assertEqualsWithDelta(5.0, $flow[0]['expenses'], 0.001);
	}

	public function testMonthlyTrendDropsReportExcludedCategoriesButKeepsUncategorised(): void {
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00', 'date' => '2026-02-01']);
		$this->makeTransaction($this->accountId, ['category_id' => $this->hidden, 'amount' => '99.00', 'date' => '2026-02-02']);
		$this->makeTransaction($this->accountId, ['amount' => '1.00', 'date' => '2026-02-03']);

		$trend = $this->reports->getMonthlyTrendData($this->userId, null, '2026-02-01', '2026-02-28');

		$this->assertEqualsWithDelta(11.0, $trend[0]['expenses'], 0.001);
	}

	/**
	 * The cash-flow report used to be the one income/expense aggregate that
	 * skipped the category choke point, so it disagreed with the dashboard
	 * trend for the same month (#219).
	 */
	public function testCashFlowByMonthDropsReportExcludedCategories(): void {
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00', 'date' => '2026-02-01']);
		$this->makeTransaction($this->accountId, ['category_id' => $this->hidden, 'amount' => '99.00', 'date' => '2026-02-02']);

		$flow = $this->reports->getCashFlowByMonth($this->userId, null, '2026-02-01', '2026-02-28');

		$this->assertEqualsWithDelta(10.0, $flow[0]['expenses'], 0.001);
	}
}
