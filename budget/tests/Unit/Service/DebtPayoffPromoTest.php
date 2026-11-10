<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\DebtPayoffService;
use OCA\Budget\Service\UserClock;
use PHPUnit\Framework\TestCase;

/**
 * The payoff plan charges a debt its promotional rate (for example 0% on a
 * balance transfer) in the months the promo covers and its normal rate after,
 * ranks it by the rate it carries that month, and raises its minimum when the
 * normal rate comes back and the old minimum no longer covers the interest.
 * Today is fixed at 2026-11-09, so month m is the 9th, m - 1 months on.
 */
class DebtPayoffPromoTest extends TestCase {
	private const TODAY = '2026-11-09';

	/**
	 * @param Account[] $accounts
	 */
	private function service(array $accounts, string $today = self::TODAY): DebtPayoffService {
		$mapper = $this->createMock(AccountMapper::class);
		$mapper->method('findAll')->willReturn($accounts);
		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('getNetChangeAfterDateBatch')->willReturn([]);
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn($today);
		return new DebtPayoffService($mapper, $transactions, null, $clock);
	}

	private function card(
		int $id,
		float $balance,
		float $rate,
		float $minimum,
		?float $promoRate = null,
		?string $promoStart = null,
		?string $promoEnd = null,
	): Account {
		$account = new Account();
		$account->setId($id);
		$account->setUserId('alice');
		$account->setName('Card ' . $id);
		$account->setType('credit_card');
		$account->setCurrency('GBP');
		$account->setBalance($balance);
		$account->setInterestRate($rate);
		$account->setMinimumPayment($minimum);
		$account->setPromoRate($promoRate);
		$account->setPromoStart($promoStart);
		$account->setPromoEnd($promoEnd);
		return $account;
	}

	/**
	 * The interest charged on $debtId in each month of the plan.
	 *
	 * @return array<int, float>
	 */
	private static function interestByMonth(array $plan, int $debtId): array {
		$out = [];
		foreach ($plan['timeline'] as $month) {
			foreach ($month['payments'] as $payment) {
				if ($payment['debtId'] === $debtId && $payment['type'] === 'minimum') {
					$out[$month['month']] = $payment['interest'];
				}
			}
		}
		return $out;
	}

	/** The debt the month's extra payment went to first, or null. */
	private static function extraTarget(array $month): ?int {
		foreach ($month['payments'] as $payment) {
			if ($payment['type'] === 'extra') {
				return $payment['debtId'];
			}
		}
		return null;
	}

	public function testPromoMonthsChargeNoInterest(): void {
		$service = $this->service([
			$this->card(1, -1200.0, 22.9, 100.0, 0.0, '2026-11-01', '2027-11-01'),
		]);

		$plan = $service->calculatePayoffPlan('alice', 'avalanche', 0.0);

		$this->assertEquals(0.0, $plan['totalInterest']);
		$this->assertSame(12, $plan['totalMonths']);
	}

	public function testInterestStartsWhenPromoEnds(): void {
		$service = $this->service([
			$this->card(1, -1200.0, 22.9, 50.0, 0.0, '2026-11-01', '2027-11-01'),
		]);

		$plan = $service->calculatePayoffPlan('alice', 'avalanche', 0.0);
		$interest = self::interestByMonth($plan, 1);

		// Months 1-12 fall on 2026-11-09 .. 2027-10-09, inside the promo
		for ($m = 1; $m <= 12; $m++) {
			$this->assertEquals(0.0, $interest[$m], 'month ' . $m);
		}
		// Month 13 is 2027-11-09: 600 left at 22.9%
		$this->assertGreaterThan(0.0, $interest[13]);
		$this->assertEqualsWithDelta(600 * 0.229 / 12, $interest[13], 0.01);
	}

	public function testAvalancheRanksPromoCardLastUntilItEnds(): void {
		$service = $this->service([
			$this->card(1, -3000.0, 29.9, 50.0, 0.0, '2026-11-01', '2027-05-01'),
			$this->card(2, -3000.0, 9.9, 50.0),
		]);

		$plan = $service->calculatePayoffPlan('alice', 'avalanche', 100.0);

		// Month 1: card 1 is at 0%, so the 9.9% card comes first
		$this->assertSame(2, self::extraTarget($plan['timeline'][0]));

		// The first month dated on or after 2027-05-01 with both still open
		$found = false;
		foreach ($plan['timeline'] as $month) {
			$date = date('Y-m-d', strtotime(self::TODAY . ' +' . ($month['month'] - 1) . ' months'));
			if ($date < '2027-05-01') {
				continue;
			}
			$this->assertSame([], $month['debtsPaidOff']);
			$this->assertSame(1, self::extraTarget($month), 'month ' . $month['month']);
			$found = true;
			break;
		}
		$this->assertTrue($found);
	}

	public function testMinimumRaisedWhenPromoEnds(): void {
		// 25 a month never covers 24.9% on ~4,925 once the promo ends
		$service = $this->service([
			$this->card(1, -5000.0, 24.9, 25.0, 0.0, '2026-11-01', '2027-02-01'),
		]);

		$plan = $service->calculatePayoffPlan('alice', 'avalanche', 0.0);

		$this->assertNotNull($plan['payoffDate']);
		$this->assertLessThan(480, $plan['totalMonths']);
	}

	public function testMinimumPaymentIsThisMonthsDuringPromo(): void {
		// The testMinimumRaisedWhenPromoEnds card: 25 a month at 0% now,
		// raised once 24.9% comes back in February
		$service = $this->service([
			$this->card(1, -5000.0, 24.9, 25.0, 0.0, '2026-11-01', '2027-02-01'),
		]);

		$plan = $service->calculatePayoffPlan('alice', 'avalanche', 0.0);
		$debt = $plan['debts'][0];

		$this->assertSame(25.0, $debt['minimumPayment']);
		$this->assertNotNull($debt['minimumAfterPromo']);
		$this->assertGreaterThan(25.0, $debt['minimumAfterPromo']);
		// The first month pays the account's minimum, not the raised one
		$this->assertSame(25.0, $plan['timeline'][0]['payments'][0]['payment']);
	}

	public function testMinimumAfterPromoNullWhenNotRaised(): void {
		$service = $this->service([
			$this->card(1, -1200.0, 22.9, 100.0, 0.0, '2026-11-01', '2027-02-01'),
			$this->card(2, -1000.0, 19.9, 50.0),
		]);

		$plan = $service->calculatePayoffPlan('alice', 'avalanche', 0.0);
		$debts = array_column($plan['debts'], null, 'id');

		$this->assertSame(100.0, $debts[1]['minimumPayment']);
		$this->assertNull($debts[1]['minimumAfterPromo']);
		$this->assertNull($debts[2]['minimumAfterPromo']);
	}

	public function testMonthDateClampsToTheEndOfAShortMonth(): void {
		// Today is 31 January: month 2 is 28 February, not 3 March, so a
		// promo ending 1 March still covers it
		$service = $this->service([
			$this->card(1, -1200.0, 22.9, 50.0, 0.0, '2027-01-01', '2027-03-01'),
		], '2027-01-31');

		$plan = $service->calculatePayoffPlan('alice', 'avalanche', 0.0);
		$interest = self::interestByMonth($plan, 1);

		$this->assertEquals(0.0, $interest[1]);
		$this->assertEquals(0.0, $interest[2]);
		// Month 3 is 31 March, after the promo
		$this->assertGreaterThan(0.0, $interest[3]);
	}

	public function testRateOverrideKeepsPromo(): void {
		$service = $this->service([
			$this->card(1, -1200.0, 22.9, 50.0, 0.0, '2026-11-01', '2027-11-01'),
		]);

		$plan = $service->calculatePayoffPlan('alice', 'avalanche', 0.0, null, 0, 1, ['1' => 10.0]);
		$interest = self::interestByMonth($plan, 1);

		for ($m = 1; $m <= 12; $m++) {
			$this->assertEquals(0.0, $interest[$m], 'month ' . $m);
		}
		// After the promo the override applies, not the account's 22.9%
		$this->assertEqualsWithDelta(600 * 0.10 / 12, $interest[13], 0.01);
		$this->assertEquals(10.0, $plan['debts'][0]['interestRate']);
	}

	public function testOutputCarriesActivePromoOnly(): void {
		$service = $this->service([
			$this->card(1, -1000.0, 19.9, 50.0, 0.0, '2025-01-01', '2026-01-01'),
			$this->card(2, -1000.0, 22.9, 50.0, 0.0, '2026-11-01', '2027-11-01'),
		]);

		$plan = $service->calculatePayoffPlan('alice', 'avalanche', 0.0);
		$debts = array_column($plan['debts'], null, 'id');

		$this->assertNull($debts[1]['promoRate']);
		$this->assertNull($debts[1]['promoStart']);
		$this->assertNull($debts[1]['promoEnd']);
		$this->assertSame(0.0, $debts[2]['promoRate']);
		$this->assertSame('2026-11-01', $debts[2]['promoStart']);
		$this->assertSame('2027-11-01', $debts[2]['promoEnd']);
		// interestRate stays the normal rate
		$this->assertEquals(22.9, $debts[2]['interestRate']);
		$this->assertEquals(19.9, $debts[1]['interestRate']);
	}

	public function testSummaryHighestRateUsesCurrentRate(): void {
		$service = $this->service([
			$this->card(1, -1000.0, 29.9, 50.0, 0.0, '2026-11-01', '2027-11-01'),
		]);

		$summary = $service->getSummary('alice');

		$this->assertEquals(0.0, $summary['highestInterestRate']);
	}
}
