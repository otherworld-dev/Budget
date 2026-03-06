<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Forecast;

use OCA\Budget\Service\Forecast\TrendCalculator;
use PHPUnit\Framework\TestCase;

class TrendCalculatorTest extends TestCase {
	private TrendCalculator $calculator;

	protected function setUp(): void {
		$this->calculator = new TrendCalculator();
	}

	// ── calculateTrend ──────────────────────────────────────────────

	public function testCalculateTrendWithEmptyArray(): void {
		$this->assertSame(0.0, $this->calculator->calculateTrend([]));
	}

	public function testCalculateTrendWithSingleValue(): void {
		$this->assertSame(0.0, $this->calculator->calculateTrend([42]));
	}

	public function testCalculateTrendWithConstantValues(): void {
		// All identical → slope should be zero
		$this->assertEqualsWithDelta(0.0, $this->calculator->calculateTrend([5, 5, 5, 5]), 1e-10);
	}

	public function testCalculateTrendWithPerfectLinearIncrease(): void {
		// y = x → slope = 1.0  (values at x=1,2,3,4,5)
		$slope = $this->calculator->calculateTrend([1, 2, 3, 4, 5]);
		$this->assertEqualsWithDelta(1.0, $slope, 1e-10);
	}

	public function testCalculateTrendWithPerfectLinearDecrease(): void {
		$slope = $this->calculator->calculateTrend([10, 8, 6, 4, 2]);
		$this->assertEqualsWithDelta(-2.0, $slope, 1e-10);
	}

	public function testCalculateTrendWithNoisyUpwardData(): void {
		// Generally increasing with noise
		$slope = $this->calculator->calculateTrend([100, 110, 105, 120, 115, 130]);
		$this->assertGreaterThan(0, $slope);
	}

	public function testCalculateTrendWithNoisyDownwardData(): void {
		$slope = $this->calculator->calculateTrend([130, 115, 120, 105, 110, 100]);
		$this->assertLessThan(0, $slope);
	}

	public function testCalculateTrendWithTwoValues(): void {
		// Two points define a perfect line: slope = (200-100) / (2-1) = 100
		$slope = $this->calculator->calculateTrend([100, 200]);
		$this->assertEqualsWithDelta(100.0, $slope, 1e-10);
	}

	public function testCalculateTrendWithNegativeValues(): void {
		$slope = $this->calculator->calculateTrend([-10, -5, 0, 5, 10]);
		$this->assertEqualsWithDelta(5.0, $slope, 1e-10);
	}

	// ── calculateVolatility ─────────────────────────────────────────

	public function testCalculateVolatilityWithEmptyArray(): void {
		$this->assertSame(0.0, $this->calculator->calculateVolatility([]));
	}

	public function testCalculateVolatilityWithConstantValues(): void {
		$this->assertEqualsWithDelta(0.0, $this->calculator->calculateVolatility([7, 7, 7, 7]), 1e-10);
	}

	public function testCalculateVolatilityWithKnownValues(): void {
		// [2, 4, 4, 4, 5, 5, 7, 9] → mean=5, stddev=2.0
		$vol = $this->calculator->calculateVolatility([2, 4, 4, 4, 5, 5, 7, 9]);
		$this->assertEqualsWithDelta(2.0, $vol, 1e-10);
	}

	public function testCalculateVolatilityWithSingleValue(): void {
		// Single value: stddev = 0
		$this->assertEqualsWithDelta(0.0, $this->calculator->calculateVolatility([42]), 1e-10);
	}

	public function testCalculateVolatilityIsAlwaysNonNegative(): void {
		$vol = $this->calculator->calculateVolatility([-100, 100, -50, 50]);
		$this->assertGreaterThanOrEqual(0, $vol);
	}

	// ── getTrendDirection ───────────────────────────────────────────

	public function testGetTrendDirectionUp(): void {
		// Trend > 1% of average → "up"
		$this->assertSame('up', $this->calculator->getTrendDirection(20.0, 100.0));
	}

	public function testGetTrendDirectionDown(): void {
		// Trend < -1% of average → "down"
		$this->assertSame('down', $this->calculator->getTrendDirection(-20.0, 100.0));
	}

	public function testGetTrendDirectionStable(): void {
		// Trend within ±1% of average → "stable"
		$this->assertSame('stable', $this->calculator->getTrendDirection(0.5, 100.0));
	}

	public function testGetTrendDirectionStableWhenAverageIsZero(): void {
		$this->assertSame('stable', $this->calculator->getTrendDirection(100.0, 0.0));
	}

	public function testGetTrendDirectionThresholdBoundary(): void {
		// Exactly at 1% threshold: trend = 1.0, average = 100 → threshold = 1.0
		// trend > threshold is false (equal), so should be "stable"
		$this->assertSame('stable', $this->calculator->getTrendDirection(1.0, 100.0));
	}

	public function testGetTrendDirectionWithNegativeAverage(): void {
		// Threshold uses abs(average), so -100 average → threshold = 1.0
		$this->assertSame('up', $this->calculator->getTrendDirection(2.0, -100.0));
	}

	// ── getTrendLabel ───────────────────────────────────────────────

	public function testGetTrendLabelIncreasing(): void {
		$this->assertSame('increasing', $this->calculator->getTrendLabel(0.5));
	}

	public function testGetTrendLabelDecreasing(): void {
		$this->assertSame('decreasing', $this->calculator->getTrendLabel(-0.5));
	}

	public function testGetTrendLabelStable(): void {
		$this->assertSame('stable', $this->calculator->getTrendLabel(0.0));
	}

	// ── calculateRelativeVolatility ─────────────────────────────────

	public function testCalculateRelativeVolatilityWithEmptyArray(): void {
		$this->assertSame(0.0, $this->calculator->calculateRelativeVolatility([]));
	}

	public function testCalculateRelativeVolatilityWithZeroMean(): void {
		// Mean = 0 → returns 0.0 to avoid division by zero
		$this->assertSame(0.0, $this->calculator->calculateRelativeVolatility([-5, 5]));
	}

	public function testCalculateRelativeVolatilityWithKnownValues(): void {
		// [10, 10, 10] → volatility=0, mean=10 → relative=0
		$this->assertEqualsWithDelta(0.0, $this->calculator->calculateRelativeVolatility([10, 10, 10]), 1e-10);
	}

	public function testCalculateRelativeVolatilityScalesWithSpread(): void {
		$low = $this->calculator->calculateRelativeVolatility([99, 100, 101]);
		$high = $this->calculator->calculateRelativeVolatility([50, 100, 150]);
		$this->assertGreaterThan($low, $high);
	}

	// ── calculateMovingAverage ──────────────────────────────────────

	public function testCalculateMovingAverageWithInsufficientData(): void {
		$this->assertSame([], $this->calculator->calculateMovingAverage([1, 2], 3));
	}

	public function testCalculateMovingAverageWithExactPeriod(): void {
		$result = $this->calculator->calculateMovingAverage([3, 6, 9], 3);
		$this->assertCount(1, $result);
		$this->assertEqualsWithDelta(6.0, $result[0], 1e-10);
	}

	public function testCalculateMovingAverageDefaultPeriod(): void {
		// Default period = 3
		$result = $this->calculator->calculateMovingAverage([2, 4, 6, 8, 10]);
		$this->assertCount(3, $result);
		$this->assertEqualsWithDelta(4.0, $result[0], 1e-10);  // (2+4+6)/3
		$this->assertEqualsWithDelta(6.0, $result[1], 1e-10);  // (4+6+8)/3
		$this->assertEqualsWithDelta(8.0, $result[2], 1e-10);  // (6+8+10)/3
	}

	public function testCalculateMovingAverageWithCustomPeriod(): void {
		$result = $this->calculator->calculateMovingAverage([1, 2, 3, 4, 5], 2);
		$this->assertCount(4, $result);
		$this->assertEqualsWithDelta(1.5, $result[0], 1e-10);
		$this->assertEqualsWithDelta(2.5, $result[1], 1e-10);
		$this->assertEqualsWithDelta(3.5, $result[2], 1e-10);
		$this->assertEqualsWithDelta(4.5, $result[3], 1e-10);
	}

	public function testCalculateMovingAverageOutputLength(): void {
		$values = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10];
		$period = 4;
		$result = $this->calculator->calculateMovingAverage($values, $period);
		// Output length should be count - period + 1
		$this->assertCount(count($values) - $period + 1, $result);
	}

	public function testCalculateMovingAverageWithEmptyArray(): void {
		$this->assertSame([], $this->calculator->calculateMovingAverage([], 3));
	}
}
