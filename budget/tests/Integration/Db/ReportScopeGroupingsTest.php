<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Db;

use OCA\Budget\Db\TransactionReportQueries;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The report groupings that used to scope their rows their own way, run as
 * real SQL: the Category-by-Month report (which filtered excluded categories
 * in PHP after the fetch), the tag reports (which ignored category exclusion
 * and split parts, and two of which saw the viewer's own accounts only) and
 * the budget carryover's split totals (which counted pension-funding legs).
 * Every one of them must now keep exactly the rows ReportScope keeps.
 */
class ReportScopeGroupingsTest extends IntegrationTestCase {
	private TransactionReportQueries $reports;
	private int $accountId;
	private int $savingsId;
	private int $food;
	private int $hidden;
	private int $muted;

	protected function setUp(): void {
		parent::setUp();
		$this->reports = $this->service(TransactionReportQueries::class);
		$this->accountId = $this->makeAccount(['name' => 'Current'])->getId();
		$this->savingsId = $this->makeAccount(['name' => 'Savings', 'type' => 'savings'])->getId();
		$this->food = $this->makeCategory(['name' => 'Food']);
		$this->hidden = $this->makeCategory(['name' => 'Reimbursable', 'excluded_from_reports' => true]);
		$this->muted = $this->makeCategory(['name' => 'Hobbies']);
		$this->insertRow('budget_cat_mutes', ['user_id' => $this->userId, 'category_id' => $this->muted, 'created_at' => $this->now()]);
	}

	// ==================== Category by month ====================

	public function testCategoryByMonthKeepsOnlyReportScopedMoney(): void {
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00', 'date' => '2026-02-01']);
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '4.00', 'type' => 'credit', 'date' => '2026-02-02']);
		$this->makeTransaction($this->accountId, ['category_id' => $this->hidden, 'amount' => '99.00', 'date' => '2026-02-03']);
		$this->makeTransaction($this->accountId, ['category_id' => $this->muted, 'amount' => '5.00', 'date' => '2026-02-04']);
		// Only the Food part of a split counts
		$this->makeSplitTransaction($this->accountId, [[$this->food, '7.00'], [$this->hidden, '3.00']], ['date' => '2026-02-05']);
		// A pension-funding leg is a transfer, never spending
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '50.00', 'date' => '2026-02-06', 'pension_contrib_id' => 999999]);
		$this->makeTransfer('25.00', '2026-02-07', $this->food);

		$net = $this->reports->getCategoryNetByMonth($this->userId, '2026-01-01', '2026-03-31');

		$this->assertSame([$this->food], array_keys($net), 'Excluded and muted categories must carry no money');
		$this->assertEqualsWithDelta(-13.0, $net[$this->food]['2026-02'], 0.001);
	}

	public function testCategoryByMonthKeepsASingleAccountsOwnTransferLegs(): void {
		$this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00', 'date' => '2026-02-01']);
		$this->makeTransfer('25.00', '2026-02-07', $this->food);

		$net = $this->reports->getCategoryNetByMonth($this->userId, '2026-01-01', '2026-03-31', $this->accountId);

		$this->assertEqualsWithDelta(-35.0, $net[$this->food]['2026-02'], 0.001);
	}

	// ==================== Budget carryover split totals ====================

	public function testSplitTotalsByBucketSkipPensionLegsAndExplicitlyUnsplitParents(): void {
		$this->makeSplitTransaction($this->accountId, [[$this->food, '6.00']], ['date' => '2026-02-01']);
		$this->makeSplitTransaction($this->accountId, [[$this->food, '40.00']], ['date' => '2026-02-02', 'pension_contrib_id' => 999999]);
		$this->makeSplitTransaction($this->accountId, [[$this->food, '2.00']], ['date' => '2026-02-03', 'is_split' => null]);
		$unsplit = $this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '9.00', 'date' => '2026-02-04', 'is_split' => false]);
		$this->makeSplit($unsplit, $this->food, '9.00');

		$totals = $this->service(TransactionSplitMapper::class)
			->getCategoryTotalsByBucket($this->userId, '2026-02-01', '2026-02-28');

		$this->assertEqualsWithDelta(8.0, $totals[$this->food]['2026-02'], 0.001);
	}

	// ==================== Tag reports ====================

	public function testTagCombinationsCountSplitPartsAndDropExcludedCategories(): void {
		[$red, $big] = $this->twoTagSets();
		$plain = $this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00']);
		$excluded = $this->makeTransaction($this->accountId, ['category_id' => $this->hidden, 'amount' => '99.00']);
		$split = $this->makeSplitTransaction($this->accountId, [[$this->food, '6.00'], [$this->hidden, '4.00']]);
		$transfer = $this->makeTransfer('25.00', '2026-03-15', $this->food);
		foreach ([$plain, $excluded, $split, $transfer] as $tx) {
			$this->tagTransaction($tx, $red);
			$this->tagTransaction($tx, $big);
		}

		$combos = $this->reports->getSpendingByTagCombination($this->userId, '2026-01-01', '2026-12-31');

		$this->assertCount(1, $combos);
		$this->assertEqualsWithDelta(16.0, $combos[0]['total'], 0.001);
		$this->assertSame(2, $combos[0]['count']);
	}

	public function testTagCrossTabAndTrendSeeSharedAccountsInView(): void {
		[$red, $big] = $this->twoTagSets();
		$owner = $this->newUserId();
		$shared = $this->makeAccount(['name' => 'Joint'], $owner)->getId();
		$mine = $this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00', 'date' => '2026-03-01']);
		$theirs = $this->makeTransaction($shared, ['amount' => '20.00', 'date' => '2026-03-02']);
		$excluded = $this->makeTransaction($this->accountId, ['category_id' => $this->muted, 'amount' => '99.00', 'date' => '2026-03-03']);
		foreach ([$mine, $theirs, $excluded] as $tx) {
			$this->tagTransaction($tx, $red);
			$this->tagTransaction($tx, $big);
		}
		$visible = [$this->accountId, $this->savingsId, $shared];
		$redSet = (int)$this->fetchRow('budget_tags', $red)['tag_set_id'];
		$bigSet = (int)$this->fetchRow('budget_tags', $big)['tag_set_id'];

		$crossTab = $this->reports->getTagCrossTabulation($this->userId, $redSet, $bigSet, '2026-01-01', '2026-12-31', null, null, $visible);
		$trend = $this->reports->getTagTrendByMonth($this->userId, [$red], '2026-01-01', '2026-12-31', null, $visible);

		$this->assertCount(1, $crossTab['data']);
		$this->assertEqualsWithDelta(30.0, $crossTab['data'][0]['total'], 0.001);
		$this->assertSame(2, $crossTab['data'][0]['count']);
		$this->assertSame([['month' => '2026-03', 'total' => 30.0]], array_map(
			static fn (array $row) => ['month' => $row['month'], 'total' => $row['total']],
			$trend
		));
	}

	public function testTagDimensionsCountTheCategorysSplitParts(): void {
		$set = $this->makeTagSet($this->food);
		$tag = $this->makeTag($set);
		$plain = $this->makeTransaction($this->accountId, ['category_id' => $this->food, 'amount' => '10.00']);
		$split = $this->makeSplitTransaction($this->accountId, [[$this->food, '6.00'], [$this->hidden, '4.00']]);
		$this->tagTransaction($plain, $tag);
		$this->tagTransaction($split, $tag);

		$dimensions = $this->reports->getTagDimensionsForCategory($this->userId, $this->food, '2026-01-01', '2026-12-31');

		$this->assertCount(1, $dimensions);
		$this->assertSame($set, $dimensions[0]['tagSetId']);
		$this->assertEqualsWithDelta(16.0, $dimensions[0]['tags'][0]['total'], 0.001);
		$this->assertSame(2, $dimensions[0]['tags'][0]['count']);
	}

	/**
	 * Two tags, each in its own tag set.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function twoTagSets(): array {
		return [
			$this->makeTag($this->makeTagSet($this->food)),
			$this->makeTag($this->makeTagSet($this->food)),
		];
	}

	/**
	 * A linked transfer from the current account to savings, the outgoing
	 * leg filed under $categoryId. Returns the outgoing leg's id.
	 */
	private function makeTransfer(string $amount, string $date, ?int $categoryId = null): int {
		$out = $this->makeTransaction($this->accountId, ['amount' => $amount, 'date' => $date, 'category_id' => $categoryId]);
		$in = $this->makeTransaction($this->savingsId, ['amount' => $amount, 'type' => 'credit', 'date' => $date, 'linked_transaction_id' => $out]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET linked_transaction_id = ? WHERE id = ?', [$in, $out]);
		return $out;
	}
}
