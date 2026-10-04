<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\DebtPayoffService;
use PHPUnit\Framework\TestCase;

/**
 * A payoff plan pays the same total every month (every minimum plus the
 * extra) until everything is paid: a cleared debt's minimum rolls on to the
 * next debt. It counted only in the month the debt was cleared, so a loan of
 * 5,000, a Visa of 300 and a card of 139.34 at 25 each paid 75, then 50,
 * then 25 a month, and took 199 months where the 75 the page showed clears
 * them in 73. The month a debt was cleared also paid more than the 75.
 */
class DebtPayoffRolloverTest extends TestCase {
	private DebtPayoffService $service;

	protected function setUp(): void {
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([
			$this->debt(1, 'Car loan', -5000.0, 6.0),
			$this->debt(2, 'Visa', -300.0, 19.9),
			$this->debt(3, 'Store card', -139.34, 0.0),
		]);
		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('getNetChangeAfterDateBatch')->willReturn([]);
		$this->service = new DebtPayoffService($accounts, $transactions);
	}

	private function debt(int $id, string $name, float $balance, float $rate): Account {
		$account = new Account();
		$account->setId($id);
		$account->setUserId('alice');
		$account->setName($name);
		$account->setType('credit_card');
		$account->setCurrency('GBP');
		$account->setBalance($balance);
		$account->setInterestRate($rate);
		$account->setMinimumPayment(25.0);
		return $account;
	}

	/**
	 * @return float[] what the plan pays in each month
	 */
	private static function monthlyTotals(array $plan): array {
		return array_map(
			static fn (array $month) => round(array_sum(array_column($month['payments'], 'payment')), 2),
			$plan['timeline']
		);
	}

	public function testWithoutInterestThePlanPaysSeventyFiveAMonthUntilAllIsPaid(): void {
		$this->service = new DebtPayoffService(
			$this->accountsWithoutInterest(),
			$this->noFutureRows()
		);

		$plan = $this->service->calculatePayoffPlan('alice', 'snowball', 0.0);
		$totals = self::monthlyTotals($plan);

		// 5,439.34 at 75 a month: 72 full months and 39.34 in the last
		$this->assertSame(73, $plan['totalMonths']);
		$this->assertSame(array_fill(0, 72, 75.0), array_slice($totals, 0, 72));
		$this->assertSame(39.34, $totals[72]);
		$this->assertEqualsWithDelta(5439.34, $plan['totalPaid'], 0.01);
		$payoff = array_column($plan['debts'], 'payoffMonth', 'id');
		ksort($payoff);
		$this->assertSame([1 => 73, 2 => 9, 3 => 6], $payoff);
	}

	/**
	 * @return array{0: string, 1: string}
	 */
	public static function strategies(): array {
		return ['snowball' => ['snowball'], 'avalanche' => ['avalanche']];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider('strategies')]
	public function testEveryMonthPaysTheWholeBudgetAndNeverMore(string $strategy): void {
		$plan = $this->service->calculatePayoffPlan('alice', $strategy, 30.0);
		$totals = self::monthlyTotals($plan);

		// Every minimum plus the extra, each month but the last: the Monthly
		// Payment the page shows (the loan's minimum is raised to 35 to cover
		// its interest, so 35 + 25 + 25 + 30)
		$budget = array_sum(array_column($plan['debts'], 'minimumPayment')) + $plan['extraPayment'];
		$this->assertEqualsWithDelta(115.0, $budget, 0.001);
		foreach (array_slice($totals, 0, -1) as $i => $total) {
			$this->assertEqualsWithDelta($budget, $total, 0.011, 'month ' . ($i + 1));
		}
		$this->assertLessThanOrEqual($budget + 0.001, end($totals));
		// Well inside the years the shrinking payment took
		$this->assertLessThan(70, $plan['totalMonths']);
	}

	public function testTheChartsReadEachDebtsBalanceAtTheEndOfTheMonth(): void {
		$plan = $this->service->calculatePayoffPlan('alice', 'snowball', 30.0);

		$first = $plan['timeline'][0]['payments'];
		$card = array_values(array_filter($first, static fn (array $p) => $p['debtId'] === 3));
		// 139.34 less its 25 minimum and the 30 extra the budget has left
		$this->assertCount(2, $card);
		$this->assertSame([84.34, 84.34], array_column($card, 'remainingBalance'));
	}

	private function accountsWithoutInterest(): AccountMapper {
		$accounts = $this->createMock(AccountMapper::class);
		$accounts->method('findAll')->willReturn([
			$this->debt(1, 'Car loan', -5000.0, 0.0),
			$this->debt(2, 'Visa', -300.0, 0.0),
			$this->debt(3, 'Store card', -139.34, 0.0),
		]);
		return $accounts;
	}

	private function noFutureRows(): TransactionMapper {
		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('getNetChangeAfterDateBatch')->willReturn([]);
		return $transactions;
	}
}
