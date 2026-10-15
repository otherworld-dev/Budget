<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\Forecast\ForecastProjector;
use OCA\Budget\Service\Forecast\PatternAnalyzer;
use OCA\Budget\Service\Forecast\ScenarioBuilder;
use OCA\Budget\Service\Forecast\TrendCalculator;
use OCA\Budget\Service\ForecastService;
use OCA\Budget\Service\UserClock;
use PHPUnit\Framework\TestCase;

/**
 * The live forecast's history is the twelve complete months before the
 * user's current month. It ran to today, so the days of the month in
 * progress counted as a whole month of income and spending.
 */
class ForecastHistoryWindowTest extends TestCase {
	public function testTheHistoryIsTheTwelveCompleteMonthsBeforeThisOne(): void {
		$transactions = $this->createMock(TransactionMapper::class);
		$transactions->method('getNetChangeAfterDateForAccounts')->willReturn([]);
		$transactions->expects($this->once())->method('findAllByUserAndDateRange')
			->with('alice', '2029-10-01', '2030-09-30')
			->willReturn([]);
		$clock = $this->createMock(UserClock::class);
		$clock->method('today')->willReturn('2030-10-04');

		$service = new ForecastService(
			$this->createMock(AccountMapper::class),
			$transactions,
			$this->createMock(PatternAnalyzer::class),
			$this->createMock(TrendCalculator::class),
			$this->createMock(ScenarioBuilder::class),
			$this->createMock(ForecastProjector::class),
			null,
			$clock
		);

		$forecast = $service->getLiveForecast('alice', 3);

		$this->assertSame(['2030-11', '2030-12', '2031-01'], array_column($forecast['monthlyProjections'], 'yearMonth'));
	}
}
