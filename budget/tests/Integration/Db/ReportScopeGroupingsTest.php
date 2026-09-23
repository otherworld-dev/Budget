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
