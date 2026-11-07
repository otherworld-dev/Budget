<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\Forecast\ForecastWarningService;
use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\UserClock;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * Projected spending follows its trend only upwards: a falling trend is
 * projected as the average of the complete months the forecast learns
 * from. The forecast is there to warn before money runs out, so it errs on
 * spending more. Followed down, a line
 * through three uneven months (1,060, 11 and 131) projected next to no
 * spending, and a reliable-looking forecast showed six months of income
 * saved whole. Bills come on top of the average, on their own dates.
 */
class ForecastSpendingFloorTest extends IntegrationTestCase {
	private int $account;

	protected function setUp(): void {
		parent::setUp();
		$this->account = $this->makeAccount(['name' => 'Current', 'balance' => 2000.0])->getId();
	}

	public function testAFallingTrendIsProjectedAsTheAverage(): void {
		$this->history(['1060.00', '11.00', '131.00']);

		$forecast = $this->forecast();

		$this->assertTrue($forecast['dataQuality']['isReliable']);
		// (1,060 + 11 + 131) / 3
		// The rest of this month from the unrounded average, as the service
		// computes it: rounding first is a cent off on some days
		$this->assertSame($this->withRestOfThisMonth(array_fill(0, 6, 400.67), (1060 + 11 + 131) / 3), array_column($forecast['monthlyProjections'], 'expenses'));
		// The history's own direction is still shown as it is
		$this->assertSame('down', $forecast['trends']['expenseDirection']);
	}

	public function testARisingTrendIsFollowed(): void {
		$this->history(['300.00', '400.00', '500.00']);

		// The rest of this month as the months before had it, not on the trend
		$this->assertSame($this->withRestOfThisMonth([500.0, 600.0, 700.0, 800.0, 900.0, 1000.0], 400.0), array_column($this->forecast()['monthlyProjections'], 'expenses'));
	}

	public function testASteadyHistoryIsProjectedAtItsAverage(): void {
		$this->history(['400.00', '410.00', '390.00']);

		$this->assertSame($this->withRestOfThisMonth(array_fill(0, 6, 400.0), 400.0), array_column($this->forecast()['monthlyProjections'], 'expenses'));
	}

	public function testBillsComeOnTopOnTheirDates(): void {
		$this->history(['1060.00', '11.00', '131.00']);
		$this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Rent', 'amount' => '450.00', 'frequency' => 'monthly',
			'account_id' => $this->account, 'is_active' => true, 'created_at' => $this->now(), 'due_day' => 5,
		]);
		$this->insertRow('budget_bills', [
			'user_id' => $this->userId, 'name' => 'Insurance', 'amount' => '600.00', 'frequency' => 'yearly',
			'account_id' => $this->account, 'is_active' => true, 'created_at' => $this->now(), 'due_day' => 5, 'due_month' => 3,
		]);

		// Not paid from the history yet, so new: the average (400.67) and
		// rent every month, the yearly insurance in March only
		// The months after this one: what is left of this one has rent
		// in it or not depending on the day
		$months = $this->fullMonths($this->forecast());
		$expected = array_map(static fn (string $m) => substr($m, 5, 2) === '03' ? 1450.67 : 850.67, array_column($months, 'yearMonth'));
		$this->assertSame($expected, array_column($months, 'expenses'));
	}

	public function testFallingSpendingThatStillOutrunsIncomeWarns(): void {
		// 2,000 in, and 3,000, 2,600 and 2,200 out: still 600 a month more
		// than comes in on average. Followed down, the trend saw the gap
		// close and the balance never went below zero
		$this->history(['3000.00', '2600.00', '2200.00'], '2000.00');
		$this->db()->executeStatement('UPDATE *PREFIX*budget_accounts SET balance = ? WHERE id = ?', ['1000.00', $this->account]);

		$balances = array_column($this->fullMonths($this->forecast()), 'balance');
		// 600 a month more out than in, after what is left of this month:
		// the spending on the 10th (2,600 on average) if that is still to come
		$first = 1000.0 - 600.0 - ($this->stillToCome(10) ? 2600.0 : 0.0);
		$this->assertEqualsWithDelta($first, $balances[0], 0.1);
		$this->assertEqualsWithDelta($first - 600.0, $balances[1], 0.1);

		$this->assertTrue($this->service(ForecastWarningService::class)->checkAndNotify($this->userId));
	}

	private function forecast(): array {
		return $this->service(ForecastService::class)->getLiveForecast($this->userId, 6);
	}

	/** The months projected after this one, without what is left of it */
	private function fullMonths(array $forecast): array {
		return array_values(array_filter($forecast['monthlyProjections'], static fn (array $m) => !$m['partial']));
	}

	/**
	 * Complete months up to last month, oldest first: $income in on the
	 * 1st and each month's spending on the 10th, plus two pennies in so
	 * the history has the ten transactions the forecast calls reliable.
	 *
	 * @param string[] $spending
	 */
	private function history(array $spending, string $income = '2500.00'): void {
		$thisMonth = new \DateTimeImmutable(date('Y-m-01'));
		foreach (array_values($spending) as $i => $spent) {
			$month = $thisMonth->modify('-' . (count($spending) - $i) . ' months');
			$this->makeTransaction($this->account, ['amount' => $income, 'type' => 'credit', 'date' => $month->format('Y-m-01')]);
			$this->makeTransaction($this->account, ['amount' => $spent, 'date' => $month->format('Y-m-10')]);
			$this->makeTransaction($this->account, ['amount' => '0.01', 'type' => 'credit', 'date' => $month->format('Y-m-11')]);
			$this->makeTransaction($this->account, ['amount' => '0.01', 'type' => 'credit', 'date' => $month->format('Y-m-12')]);
		}
	}

	/**
	 * Whether the day of the month $day is still to come this month, on
	 * the user's calendar as the forecast reads it.
	 */
	private function stillToCome(int $day): bool {
		return (int)(new \DateTimeImmutable($this->service(UserClock::class)->today($this->userId)))->format('j') < $day;
	}

	/**
	 * Monthly figures after what is left of this month, which comes first
	 * unless today is the month's last day: the spending on the 10th the
	 * months before had ($average), if the 10th is still to come.
	 *
	 * @param float[] $monthly
	 * @return float[]
	 */
	private function withRestOfThisMonth(array $monthly, float $average): array {
		$today = new \DateTimeImmutable($this->service(UserClock::class)->today($this->userId));
		if ($today->format('j') === $today->format('t')) {
			return $monthly;
		}
		return [round($this->stillToCome(10) ? $average : 0.0, 2), ...$monthly];
	}
}
