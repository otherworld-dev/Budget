<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\BillService;
use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\TransactionService;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Bills and recurring income placed on their own dates, against real rows:
 * a bill's scheduled placeholder, the credits Mark Received books, and
 * payments that aren't linked to their bill. Each must count once, either
 * on its date or in the average, never both. The unit tests mock the rows,
 * so they could not see a placeholder or a received income doubling up.
 */
class ForecastScheduledItemsTest extends IntegrationTestCase {
	private int $account;

	protected function setUp(): void {
		parent::setUp();
		$this->account = $this->makeAccount(['name' => 'Current', 'balance' => 2000.0])->getId();
	}

	/** "Create future transaction" (the default) leaves a placeholder: the bill still counts once. */
	public function testABillWithAPlaceholderCountsOnce(): void {
		$this->history(['400.00', '400.00', '400.00']);
		$this->service(BillService::class)->create($this->userId, 'Rent', 900.0, 'monthly', 5, accountId: $this->account);
		$this->assertSame(1, $this->countRows('budget_transactions', ['status' => 'scheduled', 'account_id' => $this->account]));

		foreach ($this->fullMonths($this->forecast()) as $month) {
			$this->assertSame(900.0, $month['scheduledExpenses'], $month['yearMonth']);
			$this->assertSame(1300.0, $month['expenses'], $month['yearMonth']);
		}
	}

	/** The credits Mark Received books carry the income's name, not its pattern. */
	public function testReceivedIncomeLeavesTheAverage(): void {
		$income = $this->service(RecurringIncomeService::class)->create($this->userId, 'Pension', 300.0, 'monthly', 3, accountId: $this->account);
		foreach ($this->lastMonths(3) as $month) {
			$this->service(TransactionService::class)->createFromIncome($this->userId, $income, $month->format('Y-m-03'), 'cleared');
			$this->makeTransaction($this->account, ['amount' => '400.00', 'date' => $month->format('Y-m-10')]);
			$this->pennies($month);
		}

		foreach ($this->fullMonths($this->forecast()) as $month) {
			// 300 on its date and the pennies' average, not 600
			$this->assertSame(300.02, $month['income'], $month['yearMonth']);
		}
	}

	/** A bill paid without the link to it is recognised by its name and leaves the average. */
	public function testAnUnlinkedBillPaymentLeavesTheAverage(): void {
		$this->service(BillService::class)->create($this->userId, 'Insurance', 50.0, 'monthly', 20, accountId: $this->account, createTransaction: false);
		foreach ($this->lastMonths(3) as $month) {
			$this->makeTransaction($this->account, ['amount' => '2500.00', 'type' => 'credit', 'date' => $month->format('Y-m-01')]);
			$this->makeTransaction($this->account, ['amount' => '400.00', 'date' => $month->format('Y-m-10')]);
			$this->makeTransaction($this->account, ['amount' => '50.00', 'vendor' => 'Insurance', 'description' => '', 'date' => $month->format('Y-m-20')]);
			$this->pennies($month);
		}

		foreach ($this->fullMonths($this->forecast()) as $month) {
			$this->assertSame(450.0, $month['expenses'], $month['yearMonth']);
		}
	}

	/** Without a pattern, an income takes one credit per date: its own, not a refund beside it. */
	public function testAnIncomeWithoutAPatternLeavesOtherCreditsAlone(): void {
		$this->service(RecurringIncomeService::class)->create($this->userId, 'Pension', 300.0, 'monthly', 3, accountId: $this->account);
		foreach ($this->lastMonths(3) as $month) {
			$this->makeTransaction($this->account, ['amount' => '300.00', 'type' => 'credit', 'vendor' => 'DRV BUND', 'date' => $month->format('Y-m-03')]);
			$this->makeTransaction($this->account, ['amount' => '290.00', 'type' => 'credit', 'vendor' => 'Refund', 'date' => $month->format('Y-m-04')]);
			$this->makeTransaction($this->account, ['amount' => '400.00', 'date' => $month->format('Y-m-10')]);
			$this->pennies($month);
		}

		foreach ($this->fullMonths($this->forecast()) as $month) {
			$this->assertSame(590.02, $month['income'], $month['yearMonth']);
		}
	}

	/** A savings account added last month drops only its own partial month. */
	public function testANewAccountKeepsTheOthersHistory(): void {
		$this->history(['400.00', '400.00', '400.00']);
		$savings = $this->makeAccount(['name' => 'Savings', 'openingBalance' => 1000.0, 'balance' => 1100.0])->getId();
		$lastMonth = $this->lastMonths(1)[0];
		$this->makeTransaction($savings, ['amount' => '100.00', 'type' => 'credit', 'date' => $lastMonth->format('Y-m-15')]);

		$forecast = $this->forecast();

		$this->assertSame(3, $forecast['dataQuality']['monthsOfData']);
		$this->assertTrue($forecast['dataQuality']['isReliable']);
	}

	/** A transfer bill to an account outside the forecast is money leaving it. */
	public function testATransferBillOutOfTheForecastIsSpending(): void {
		$this->history(['400.00', '400.00', '400.00']);
		$outside = $this->makeAccount(['name' => 'Broker', 'excludedFromReports' => true])->getId();
		$this->service(BillService::class)->create($this->userId, 'Savings plan', 200.0, 'monthly', 15,
			accountId: $this->account, createTransaction: false, isTransfer: true, destinationAccountId: $outside);

		foreach ($this->fullMonths($this->forecast()) as $month) {
			$this->assertSame(200.0, $month['scheduledExpenses'], $month['yearMonth']);
		}
	}

	/** A bill past its due date and not paid is still to go out, in the first column. */
	public function testAnOverdueUnpaidBillCountsFirst(): void {
		$this->history(['400.00', '400.00', '400.00']);
		$this->overdueBill('Plumber', 75.0);

		$this->assertSame(75.0, $this->forecast()['monthlyProjections'][0]['scheduledExpenses']);
	}

	/** Paid through the bank without being marked paid, it is in the balance already. */
	public function testAnOverdueBillPaidThroughTheBankIsNotCountedAgain(): void {
		$this->history(['400.00', '400.00', '400.00']);
		$this->overdueBill('Plumber', 75.0);
		$this->makeTransaction($this->account, ['amount' => '75.00', 'vendor' => 'Plumber', 'description' => '', 'date' => $this->today()]);

		$this->assertSame(0.0, $this->forecast()['monthlyProjections'][0]['scheduledExpenses']);
	}

	/** Paid by the bank under its own name: on the income's account, near its date and amount */
	public function testAnIncomeWithoutAPatternPaidUnderAnotherNameLeavesTheAverage(): void {
		$this->service(RecurringIncomeService::class)->create($this->userId, 'Salary', 2500.0, 'monthly', 1, accountId: $this->account);
		foreach ($this->lastMonths(3) as $month) {
			$this->makeTransaction($this->account, ['amount' => '2500.00', 'type' => 'credit', 'vendor' => 'ACME LTD', 'description' => 'PAYROLL', 'date' => $month->format('Y-m-02')]);
			$this->makeTransaction($this->account, ['amount' => '400.00', 'date' => $month->format('Y-m-10')]);
			$this->pennies($month);
		}

		foreach ($this->fullMonths($this->forecast()) as $month) {
			// 2,500 on its date and the pennies' average, not 5,000
			$this->assertSame(2500.02, $month['income'], $month['yearMonth']);
		}
	}

	public function testABillPaidUnderAnotherNameLeavesTheAverage(): void {
		$this->service(BillService::class)->create($this->userId, 'Insurance', 50.0, 'monthly', 20, accountId: $this->account, createTransaction: false);
		foreach ($this->lastMonths(3) as $month) {
			$this->makeTransaction($this->account, ['amount' => '2500.00', 'type' => 'credit', 'date' => $month->format('Y-m-01')]);
			$this->makeTransaction($this->account, ['amount' => '400.00', 'date' => $month->format('Y-m-10')]);
			$this->makeTransaction($this->account, ['amount' => '50.00', 'vendor' => 'ALLIANZ VERS', 'description' => '', 'date' => $month->format('Y-m-21')]);
			$this->pennies($month);
		}

		foreach ($this->fullMonths($this->forecast()) as $month) {
			$this->assertSame(450.0, $month['expenses'], $month['yearMonth']);
		}
	}

	/** Overtime: the salary is recognised by its pattern however far it is off */
	public function testASalaryWithOvertimeStaysTheIncomes(): void {
		$this->service(RecurringIncomeService::class)->create($this->userId, 'Salary', 2500.0, 'monthly', 1, accountId: $this->account, autoDetectPattern: 'ACME');
		foreach ($this->lastMonths(3) as $i => $month) {
			$this->makeTransaction($this->account, ['amount' => $i === 2 ? '3250.00' : '2500.00', 'type' => 'credit', 'description' => 'Salary ACME', 'date' => $month->format('Y-m-01')]);
			$this->makeTransaction($this->account, ['amount' => '400.00', 'date' => $month->format('Y-m-10')]);
			$this->pennies($month);
		}

		foreach ($this->fullMonths($this->forecast()) as $month) {
			$this->assertSame(2500.02, $month['income'], $month['yearMonth']);
		}
	}

	/** A generic pattern takes one row per due date, not every purchase it mentions */
	public function testAGenericPatternTakesOneRowPerDueDate(): void {
		$this->service(BillService::class)->create($this->userId, 'Prime', 10.0, 'monthly', 5, accountId: $this->account, autoDetectPattern: 'AMAZON', createTransaction: false);
		foreach ($this->lastMonths(3) as $month) {
			$this->makeTransaction($this->account, ['amount' => '2500.00', 'type' => 'credit', 'date' => $month->format('Y-m-01')]);
			$this->makeTransaction($this->account, ['amount' => '400.00', 'date' => $month->format('Y-m-10')]);
			$this->makeTransaction($this->account, ['amount' => '10.00', 'description' => 'AMAZON PRIME', 'date' => $month->format('Y-m-05')]);
			$this->makeTransaction($this->account, ['amount' => '9.00', 'description' => 'AMAZON MKTPLACE', 'date' => $month->format('Y-m-06')]);
			$this->makeTransaction($this->account, ['amount' => '11.00', 'description' => 'AMAZON MKTPLACE', 'date' => $month->format('Y-m-15')]);
			$this->pennies($month);
		}

		foreach ($this->fullMonths($this->forecast()) as $month) {
			// 400 and the two purchases averaged, Prime's 10 on its date
			$this->assertSame(430.0, $month['expenses'], $month['yearMonth']);
		}
	}

	/** The owner's bill isn't placed in the forecast of the person the account is shared with */
	public function testTheOwnersBillStaysInTheAverageOfWhoTheAccountIsSharedWith(): void {
		$rent = $this->service(BillService::class)->create($this->userId, 'Rent', 900.0, 'monthly', 5, accountId: $this->account, createTransaction: false);
		foreach ($this->lastMonths(3) as $month) {
			$this->makeTransaction($this->account, ['amount' => '2500.00', 'type' => 'credit', 'date' => $month->format('Y-m-01')]);
			$this->makeTransaction($this->account, ['amount' => '900.00', 'vendor' => 'Rent', 'bill_id' => $rent->getId(), 'date' => $month->format('Y-m-05')]);
			$this->makeTransaction($this->account, ['amount' => '400.00', 'date' => $month->format('Y-m-10')]);
			$this->pennies($month);
		}

		$viewer = $this->newUserId();
		$shared = $this->service(ForecastService::class)->getLiveForecast($viewer, 6, [$this->account]);

		foreach ($this->fullMonths($shared) as $month) {
			$this->assertSame(1300.0, $month['expenses'], $month['yearMonth']);
		}
	}

	/** Months of a bill nobody marked paid: only the latest is still owed */
	public function testABillLongUnmarkedCountsOnceFirst(): void {
		$this->history(['400.00', '400.00', '400.00']);
		$bill = $this->service(BillService::class)->create($this->userId, 'Gym', 30.0, 'monthly', 1, accountId: $this->account, createTransaction: false);
		$due = $this->lastMonths(3)[0]->format('Y-m-01');
		$this->db()->executeStatement(
			'UPDATE *PREFIX*budget_bills SET next_due_date = ?, created_at = ? WHERE id = ?',
			[$due, $due . ' 00:00:00', $bill->getId()]
		);

		$first = $this->forecast()['monthlyProjections'][0];
		// Not the three or four dates missed. On the month's last day the
		// first column is next month, with its own 1st in it as well
		$this->assertSame($first['partial'] ? 30.0 : 60.0, $first['scheduledExpenses']);
	}

	private function overdueBill(string $name, float $amount): void {
		$yesterday = (new \DateTimeImmutable($this->today()))->modify('-1 day')->format('Y-m-d');
		$bill = $this->service(BillService::class)->create($this->userId, $name, $amount, 'one-time', accountId: $this->account, createTransaction: false, startDate: $yesterday);
		$this->db()->executeStatement(
			'UPDATE *PREFIX*budget_bills SET next_due_date = ?, start_date = ?, created_at = ? WHERE id = ?',
			[$yesterday, $yesterday, $yesterday . ' 00:00:00', $bill->getId()]
		);
	}

	private function forecast(): array {
		return $this->service(ForecastService::class)->getLiveForecast($this->userId, 6);
	}

	private function today(): string {
		return $this->service(\OCA\Budget\Service\UserClock::class)->today($this->userId);
	}

	/** The months projected after this one, without what is left of it */
	private function fullMonths(array $forecast): array {
		return array_values(array_filter($forecast['monthlyProjections'], static fn (array $m) => !$m['partial']));
	}

	/**
	 * The first days of the $count complete months before this one, oldest first.
	 *
	 * @return \DateTimeImmutable[]
	 */
	private function lastMonths(int $count): array {
		$thisMonth = new \DateTimeImmutable(substr($this->today(), 0, 7) . '-01');
		return array_map(static fn (int $i) => $thisMonth->modify('-' . ($count - $i) . ' months'), range(0, $count - 1));
	}

	/** Two pennies in, so the history has the ten transactions the forecast calls reliable */
	private function pennies(\DateTimeImmutable $month): void {
		$this->makeTransaction($this->account, ['amount' => '0.01', 'type' => 'credit', 'date' => $month->format('Y-m-11')]);
		$this->makeTransaction($this->account, ['amount' => '0.01', 'type' => 'credit', 'date' => $month->format('Y-m-12')]);
	}

	/**
	 * Complete months up to last month: 2,500 in on the 1st and each
	 * month's spending on the 10th, plus the pennies.
	 *
	 * @param string[] $spending
	 */
	private function history(array $spending): void {
		foreach ($this->lastMonths(count($spending)) as $i => $month) {
			$this->makeTransaction($this->account, ['amount' => '2500.00', 'type' => 'credit', 'date' => $month->format('Y-m-01')]);
			$this->makeTransaction($this->account, ['amount' => $spending[$i], 'date' => $month->format('Y-m-10')]);
			$this->pennies($month);
		}
	}
}
