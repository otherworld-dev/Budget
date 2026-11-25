<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Integration\Service;

use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\UserClock;
use OCA\Budget\Tests\Integration\IntegrationTestCase;

/**
 * What is left of the running month gets what the same days of the months
 * learned from had, not a share of their average by days. Money moves with
 * the salary: most of a month's spending leaves in the days after it comes
 * in. Spread by days, a month whose history spent 82% in its first 12 days
 * was given 61% of its spending in the 19 left, and looked to end in the red.
 */
class ForecastRestOfMonthTest extends IntegrationTestCase {
	private int $account;

	protected function setUp(): void {
		parent::setUp();
		$this->account = $this->makeAccount(['name' => 'Current', 'balance' => 2000.0])->getId();
	}

	public function testTheRestOfTheMonthGetsWhatTheSameDaysHad(): void {
		$thisMonth = new \DateTimeImmutable(substr($this->today(), 0, 7) . '-01');
		foreach ([3, 2, 1] as $back) {
			$month = $thisMonth->modify("-{$back} months");
			// Salary and the standing orders on the 1st, a little on the 28th
			$this->makeTransaction($this->account, ['amount' => '2500.00', 'type' => 'credit', 'date' => $month->format('Y-m-01')]);
			$this->makeTransaction($this->account, ['amount' => '900.00', 'date' => $month->format('Y-m-01')]);
			$this->makeTransaction($this->account, ['amount' => '100.00', 'date' => $month->format('Y-m-28')]);
		}

		$projections = $this->service(ForecastService::class)->getLiveForecast($this->userId, 6)['monthlyProjections'];

		$today = new \DateTimeImmutable($this->today());
		if ($today->format('j') === $today->format('t')) {
			// Nothing of this month left: the next one comes first, whole
			$this->assertFalse($projections[0]['partial']);
			return;
		}
		$this->assertTrue($projections[0]['partial']);
		// The 1st is past: neither the salary nor the standing orders come
		// again this month, only the 28th's 100 if it is still to come
		$this->assertSame(0.0, $projections[0]['income']);
		$this->assertSame((int)$today->format('j') < 28 ? 100.0 : 0.0, $projections[0]['expenses']);
		// The months after it are whole
		$this->assertSame(2500.0, $projections[1]['income']);
		$this->assertSame(1000.0, $projections[1]['expenses']);
	}

	private function today(): string {
		return $this->service(UserClock::class)->today($this->userId);
	}
}
