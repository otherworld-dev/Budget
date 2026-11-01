<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\Forecast\ForecastWarningService;
use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\UserClock;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * The forecast projects from complete months, extrapolates a trend only
 * from enough of them, and never projects income below the recurring
 * income the user has set up.
 *
 * It counted the month in progress as a month of history and drew a
 * straight line through as few as two points: a user who joined in
 * September, with a 3,100 salary that month and 300 so far in October,
 * was projected no income at all from November and got a warning of a
 * negative balance in February.
 */
class ForecastHistoryTest extends IntegrationTestCase {
	private int $account;
	private \DateTimeImmutable $thisMonth;

	protected function setUp(): void {
		parent::setUp();
		$this->thisMonth = new \DateTimeImmutable(date('Y-m-01'));
		$this->account = $this->makeAccount(['name' => 'Current'])->getId();
	}

	public function testThePartialMonthAndATwoPointTrendDoNotProjectAwayTheSalary(): void {
		$this->setBalance(3925.0);
		$this->recurringIncome('3100.00');
		// Last month: the salary and the month's spending
		$this->monthOf(1, '3100.00', ['1200.00', '800.00']);
		// This month so far: 300 in, nothing out yet
		$this->makeTransaction($this->account, ['amount' => '300.00', 'type' => 'credit', 'date' => $this->thisMonth->format('Y-m-d')]);

		$forecast = $this->service(ForecastService::class)->getLiveForecast($this->userId, 6);

		$rest = $this->restOfThisMonth();
		$months = $this->fullMonths($forecast);
		$this->assertSame(array_fill(0, 6, 3100.0), array_column($months, 'income'));
		$this->assertSame(array_fill(0, 6, 2000.0), array_column($months, 'expenses'));
		// What is left of this month comes first, with its share of spending
		if ($rest > 0) {
			$this->assertTrue($forecast['monthlyProjections'][0]['partial']);
			$this->assertSame(round(2000.0 * $rest, 2), $forecast['monthlyProjections'][0]['expenses']);
		}
		$this->assertEqualsWithDelta(3925.0 + 6 * 1100.0 - 2000.0 * $rest, $forecast['projectedBalance'], 0.01);
		$this->assertSame(1, $forecast['dataQuality']['monthsOfData']);
		$this->assertFalse($forecast['dataQuality']['isReliable']);
	}

	public function testAnUnreliableForecastSendsNoWarning(): void {
		// One complete month spending more than comes in: the projection
		// does go negative, but one month is no basis for a warning
		$this->setBalance(500.0);
		$this->monthOf(1, '1000.00', ['900.00', '900.00']);

		$this->assertFalse($this->service(ForecastWarningService::class)->checkAndNotify($this->userId));
	}

	public function testASteadyHistoryStillWarnsOfARealDip(): void {
		// Six complete months of 2,000 in and 2,600 out
		$this->setBalance(1000.0);
		for ($back = 1; $back <= 6; $back++) {
			$this->monthOf($back, '2000.00', ['1300.00', '1300.00']);
		}

		$forecast = $this->service(ForecastService::class)->getLiveForecast($this->userId, 6);
		$this->assertTrue($forecast['dataQuality']['isReliable']);
		// 600 a month more out than in, after the rest of this month's share
		$first = round(1000.0 - 600.0 * (1 + $this->restOfThisMonth()), 2);
		$this->assertSame([$first, round($first - 600.0, 2)], array_slice(array_column($this->fullMonths($forecast), 'balance'), 0, 2));

		$this->assertTrue($this->service(ForecastWarningService::class)->checkAndNotify($this->userId));
	}

	public function testAFallingTrendIsNotProjectedBelowRecurringIncome(): void {
		$this->setBalance(5000.0);
		$this->recurringIncome('3100.00');
		// A bonus that stopped: 5,100, 5,100, 3,100, 3,100 in
		foreach ([4 => '5100.00', 3 => '5100.00', 2 => '3100.00', 1 => '3100.00'] as $back => $income) {
			$this->monthOf($back, $income, ['500.00', '500.00', '500.00']);
		}

		$forecast = $this->service(ForecastService::class)->getLiveForecast($this->userId, 6);

		$months = $this->fullMonths($forecast);
		foreach ($months as $month) {
			$this->assertGreaterThanOrEqual(3100.0, $month['income'], $month['yearMonth']);
		}
		// The bonus stopped: it is not carried on as if it still came
		$this->assertLessThan(5100.0, $months[0]['income']);
	}

	/**
	 * One complete month $back months ago: $income in on the 1st, each of
	 * $spending out on the days after.
	 *
	 * @param string[] $spending
	 */
	private function monthOf(int $back, string $income, array $spending): void {
		$month = $this->thisMonth->modify("-{$back} months");
		$this->makeTransaction($this->account, ['amount' => $income, 'type' => 'credit', 'date' => $month->format('Y-m-01')]);
		foreach ($spending as $i => $amount) {
			$this->makeTransaction($this->account, ['amount' => $amount, 'date' => $month->format('Y-m-') . sprintf('%02d', 10 + $i)]);
		}
	}

	/** The months projected after this one, without what is left of it */
	private function fullMonths(array $forecast): array {
		return array_values(array_filter($forecast['monthlyProjections'], static fn (array $m) => !$m['partial']));
	}

	private function recurringIncome(string $amount): void {
		$this->insertRow('budget_recurring_income', [
			'user_id' => $this->userId, 'name' => 'Salary', 'amount' => $amount, 'frequency' => 'monthly',
			'account_id' => $this->account, 'is_active' => true, 'created_at' => $this->now(),
		]);
	}

	private function setBalance(float $balance): void {
		$this->db()->executeStatement(
			'UPDATE *PREFIX*budget_accounts SET balance = ? WHERE id = ?',
			[number_format($balance, 2, '.', ''), $this->account]
		);
	}

	/**
	 * The share of this month still to come after today, on the user's
	 * calendar as the forecast reads it: what is left of this month
	 * comes first, with this share of the usual income and spending.
	 */
	private function restOfThisMonth(): float {
		$today = new \DateTimeImmutable($this->service(UserClock::class)->today($this->userId));
		$days = (int)$today->format('t');
		return ($days - (int)$today->format('j')) / $days;
	}
}
