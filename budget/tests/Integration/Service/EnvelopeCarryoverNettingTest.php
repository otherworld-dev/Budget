<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionSplitMapper;
use OCA\Budget\Service\BudgetCarryoverService;
use OCA\Budget\Service\BudgetStatusService;
use OCA\Budget\Service\SettingService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * An envelope carries into next month what the Budget page showed as left
 * this month. The page nets a month's spending (a refund takes money back
 * off it, and the two legs of a transfer filed under the category cancel
 * out), while the carryover chain summed the debits alone: a refunded
 * purchase was spent in full, and a monthly transfer to savings filed under
 * a Savings category overspent the envelope by the whole transfer every
 * month.
 */
class EnvelopeCarryoverNettingTest extends IntegrationTestCase {
	private int $current;
	private int $savings;

	protected function setUp(): void {
		parent::setUp();
		$this->current = $this->makeAccount(['name' => 'Current'])->getId();
		$this->savings = $this->makeAccount(['name' => 'Savings', 'type' => 'savings'])->getId();
	}

	public function testRefundsSplitRefundsAndTransferLegsNetOutOfTheChain(): void {
		$bills = $this->envelope('Bills', '100', '2025-01');
		$other = $this->makeCategory(['name' => 'Other']);

		// January: 100 spent, 30 of it refunded. The page: spent 70, left 30
		$this->makeTransaction($this->current, ['category_id' => $bills, 'amount' => '100.00', 'date' => '2025-01-05']);
		$this->makeTransaction($this->current, ['category_id' => $bills, 'amount' => '30.00', 'type' => 'credit', 'date' => '2025-01-20']);

		// February: 50 spent, a 200 transfer to savings filed under Bills on
		// both legs (nets to 0), a split receipt with 25 under Bills and a
		// split refund giving 10 of it back. The page: spent 65
		$this->makeTransaction($this->current, ['category_id' => $bills, 'amount' => '50.00', 'date' => '2025-02-05']);
		$this->makeTransfer('200.00', '2025-02-10', $bills);
		$this->makeSplitTransaction($this->current, [[$bills, '25.00'], [$other, '15.00']], ['date' => '2025-02-12']);
		$this->makeSplitTransaction($this->current, [[$bills, '10.00']], ['date' => '2025-02-14', 'type' => 'credit']);

		$carry = $this->service(BudgetCarryoverService::class);
		$this->assertEqualsWithDelta(30.0, $carry->getCarryovers($this->userId, '2025-02')[$bills] ?? null, 0.001);
		// 100 + 30 carried - 65 spent
		$this->assertEqualsWithDelta(65.0, $carry->getCarryovers($this->userId, '2025-03')[$bills] ?? null, 0.001);
	}

	public function testTheCarryIsWhatTheBudgetPageShowedAsLeft(): void {
		$bills = $this->envelope('Bills', '100', '2025-01');
		$this->makeTransaction($this->current, ['category_id' => $bills, 'amount' => '100.00', 'date' => '2025-01-05']);
		$this->makeTransaction($this->current, ['category_id' => $bills, 'amount' => '30.00', 'type' => 'credit', 'date' => '2025-01-20']);
		$this->makeTransaction($this->current, ['category_id' => $bills, 'amount' => '50.00', 'date' => '2025-02-05']);
		$this->makeTransfer('200.00', '2025-02-10', $bills);

		$status = $this->service(BudgetStatusService::class);
		$january = $this->line($status->forMonth($this->userId, '2025-01'), $bills);
		$february = $this->line($status->forMonth($this->userId, '2025-02'), $bills);
		$march = $this->line($status->forMonth($this->userId, '2025-03'), $bills);

		$this->assertEqualsWithDelta(30.0, (float)$january['remaining'], 0.001);
		$this->assertEqualsWithDelta((float)$january['remaining'], (float)$february['carried'], 0.001);
		$this->assertEqualsWithDelta(80.0, (float)$february['remaining'], 0.001);
		$this->assertEqualsWithDelta((float)$february['remaining'], (float)$march['carried'], 0.001);
	}

	public function testALateStartDayNetsEachPeriod(): void {
		$this->service(SettingService::class)->set($this->userId, 'budget_start_day', '25');
		$groceries = $this->envelope('Groceries', '400', '2025-01');

		// Budget month 2025-01 runs 25 Dec - 24 Jan: 350 spent, 20 back
		$this->makeTransaction($this->current, ['category_id' => $groceries, 'amount' => '350.00', 'date' => '2025-01-01']);
		$this->makeTransaction($this->current, ['category_id' => $groceries, 'amount' => '20.00', 'type' => 'credit', 'date' => '2025-01-10']);
		// Budget month 2025-02 runs 25 Jan - 24 Feb: 380 spent
		$this->makeTransaction($this->current, ['category_id' => $groceries, 'amount' => '380.00', 'date' => '2025-02-01']);

		$carry = $this->service(BudgetCarryoverService::class)->getCarryovers($this->userId, '2025-03');

		// 400 - 330 = 70 left in January, 400 + 70 - 380 = 90 in February
		$this->assertEqualsWithDelta(90.0, $carry[$groceries] ?? null, 0.001);
	}

	public function testBucketQueriesNetTheOppositeDirection(): void {
		$food = $this->makeCategory(['name' => 'Food']);
		$this->makeTransaction($this->current, ['category_id' => $food, 'amount' => '40.00', 'date' => '2025-02-01']);
		$this->makeTransaction($this->current, ['category_id' => $food, 'amount' => '15.00', 'type' => 'credit', 'date' => '2025-02-02']);
		$this->makeSplitTransaction($this->current, [[$food, '9.00']], ['date' => '2025-02-03']);
		$this->makeSplitTransaction($this->current, [[$food, '4.00']], ['date' => '2025-02-04', 'type' => 'credit']);

		$direct = $this->service(TransactionMapper::class)
			->getCategorySpendingByBucketBatch($this->userId, '2025-02-01', '2025-02-28');
		$split = $this->service(TransactionSplitMapper::class)
			->getCategoryTotalsByBucket($this->userId, '2025-02-01', '2025-02-28');
		$byDay = $this->service(TransactionMapper::class)
			->getCategorySpendingByBucketBatch($this->userId, '2025-02-01', '2025-02-28', true);

		$this->assertEqualsWithDelta(25.0, $direct[$food]['2025-02'] ?? null, 0.001);
		$this->assertEqualsWithDelta(5.0, $split[$food]['2025-02'] ?? null, 0.001);
		$this->assertEqualsWithDelta(-15.0, $byDay[$food]['2025-02-02'] ?? null, 0.001);
	}

	/**
	 * A monthly expense category with an envelope running from $anchor.
	 */
	private function envelope(string $name, string $budget, string $anchor): int {
		return $this->makeCategory([
			'name' => $name,
			'budget_amount' => $budget,
			'budget_period' => 'monthly',
			'budget_rollover' => true,
			'rollover_start' => $anchor,
		]);
	}

	/**
	 * A linked transfer from the current account to savings, both legs filed
	 * under $categoryId, as an auto-categorising rule or import leaves it.
	 */
	private function makeTransfer(string $amount, string $date, int $categoryId): void {
		$out = $this->makeTransaction($this->current, ['amount' => $amount, 'date' => $date, 'category_id' => $categoryId]);
		$in = $this->makeTransaction($this->savings, ['amount' => $amount, 'type' => 'credit', 'date' => $date, 'category_id' => $categoryId, 'linked_transaction_id' => $out]);
		$this->db()->executeStatement('UPDATE *PREFIX*budget_transactions SET linked_transaction_id = ? WHERE id = ?', [$in, $out]);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function line(array $status, int $categoryId): array {
		foreach ($status['categories'] as $line) {
			if ($line['categoryId'] === $categoryId) {
				return $line;
			}
		}
		$this->fail('No budget line for category ' . $categoryId . ' in ' . $status['month']);
	}
}
