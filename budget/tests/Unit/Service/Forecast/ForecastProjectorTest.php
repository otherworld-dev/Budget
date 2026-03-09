<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service\Forecast;

use OCA\Budget\Db\Category;
use OCA\Budget\Db\CategoryMapper;
use OCA\Budget\Service\Forecast\ForecastProjector;
use OCA\Budget\Service\Forecast\TrendCalculator;
use PHPUnit\Framework\TestCase;

class ForecastProjectorTest extends TestCase {
    private ForecastProjector $projector;
    private TrendCalculator $trendCalculator;
    private CategoryMapper $categoryMapper;

    protected function setUp(): void {
        $this->trendCalculator = $this->createMock(TrendCalculator::class);
        $this->categoryMapper = $this->createMock(CategoryMapper::class);

        $this->projector = new ForecastProjector(
            $this->trendCalculator,
            $this->categoryMapper
        );
    }

    // ===== projectMonthlyIncome =====

    public function testProjectMonthlyIncomeBasic(): void {
        $patterns = [
            'monthly' => [
                'income' => ['average' => 5000.0, 'trend' => 50.0],
            ],
        ];

        $result = $this->projector->projectMonthlyIncome($patterns, 3);

        // base + trend * months = 5000 + 50*3 = 5150
        $this->assertEqualsWithDelta(5150.0, $result, 0.01);
    }

    public function testProjectMonthlyIncomeWithSeasonality(): void {
        $futureMonth = (int) date('n', strtotime('+2 months'));
        $patterns = [
            'monthly' => [
                'income' => ['average' => 5000.0, 'trend' => 0.0],
            ],
            'seasonality' => [$futureMonth => 1.2],
        ];

        $result = $this->projector->projectMonthlyIncome($patterns, 2);

        $this->assertEqualsWithDelta(6000.0, $result, 0.01);
    }

    public function testProjectMonthlyIncomeEmptyReturnsZero(): void {
        $this->assertEquals(0.0, $this->projector->projectMonthlyIncome([], 1));
    }

    public function testProjectMonthlyIncomeNegativeClampsToZero(): void {
        $patterns = [
            'monthly' => [
                'income' => ['average' => 100.0, 'trend' => -200.0],
            ],
        ];

        $result = $this->projector->projectMonthlyIncome($patterns, 1);

        $this->assertEquals(0.0, $result);
    }

    // ===== projectMonthlyExpenses =====

    public function testProjectMonthlyExpensesBasic(): void {
        $patterns = [
            'monthly' => [
                'expenses' => ['average' => 3000.0, 'trend' => 20.0],
            ],
        ];

        $result = $this->projector->projectMonthlyExpenses($patterns, 2);

        $this->assertEqualsWithDelta(3040.0, $result, 0.01);
    }

    public function testProjectMonthlyExpensesEmptyReturnsZero(): void {
        $this->assertEquals(0.0, $this->projector->projectMonthlyExpenses([], 1));
    }

    // ===== generateCategoryForecasts =====

    public function testGenerateCategoryForecastsReturnsForecastData(): void {
        $cat = new Category();
        $cat->setId(1);
        $cat->setName('Food');

        $this->categoryMapper->method('findByIds')->willReturn([1 => $cat]);
        $this->trendCalculator->method('getTrendLabel')->willReturn('increasing');

        $patterns = [
            'categories' => [
                1 => ['average' => 400.0, 'trend' => 10.0, 'volatility' => 50.0, 'frequency' => 0.8],
            ],
        ];

        $result = $this->projector->generateCategoryForecasts('user1', $patterns, 3);

        $this->assertCount(1, $result);
        $this->assertEquals('Food', $result[0]['categoryName']);
        $this->assertEquals(400.0, $result[0]['currentMonthlyAverage']);
        $this->assertCount(3, $result[0]['projectedMonthly']);
        $this->assertEquals('increasing', $result[0]['trend']);
    }

    public function testGenerateCategoryForecastsSkipsMissingCategories(): void {
        $this->categoryMapper->method('findByIds')->willReturn([]);

        $patterns = [
            'categories' => [
                999 => ['average' => 100.0, 'trend' => 0.0, 'volatility' => 10.0, 'frequency' => 0.5],
            ],
        ];

        $result = $this->projector->generateCategoryForecasts('user1', $patterns, 3);

        $this->assertEmpty($result);
    }

    public function testGenerateCategoryForecastsEmptyPatterns(): void {
        $result = $this->projector->generateCategoryForecasts('user1', [], 3);
        $this->assertEmpty($result);
    }

    // ===== calculateConfidence =====

    public function testCalculateConfidenceDecaysOverTime(): void {
        $patterns = [
            'monthly' => [
                'income' => ['volatility' => 100],
                'expenses' => ['volatility' => 100],
            ],
        ];

        $conf1 = $this->projector->calculateConfidence($patterns, 1);
        $conf6 = $this->projector->calculateConfidence($patterns, 6);

        $this->assertGreaterThan($conf6, $conf1);
        $this->assertGreaterThanOrEqual(0.1, $conf6);
    }

    public function testCalculateConfidenceHighVolatilityReducesScore(): void {
        $low = [
            'monthly' => [
                'income' => ['volatility' => 10],
                'expenses' => ['volatility' => 10],
            ],
        ];
        $high = [
            'monthly' => [
                'income' => ['volatility' => 500],
                'expenses' => ['volatility' => 500],
            ],
        ];

        $this->assertGreaterThan(
            $this->projector->calculateConfidence($high, 1),
            $this->projector->calculateConfidence($low, 1)
        );
    }

    // ===== calculateDataConfidence =====

    public function testCalculateDataConfidenceMoreDataHigherScore(): void {
        $this->trendCalculator->method('calculateVolatility')->willReturn(100.0);

        $lowData = $this->projector->calculateDataConfidence(2, 10, [1000, 1100], [800, 900]);
        $highData = $this->projector->calculateDataConfidence(12, 200, [1000, 1100], [800, 900]);

        $this->assertGreaterThan($lowData, $highData);
    }

    public function testCalculateDataConfidenceBounds(): void {
        $this->trendCalculator->method('calculateVolatility')->willReturn(0.0);

        $result = $this->projector->calculateDataConfidence(12, 200, [5000, 5000], [3000, 3000]);

        $this->assertLessThanOrEqual(100, $result);
        $this->assertGreaterThanOrEqual(0, $result);
    }

    // ===== calculateCategoryConfidence =====

    public function testCalculateCategoryConfidence(): void {
        $result = $this->projector->calculateCategoryConfidence([
            'frequency' => 0.8,
            'volatility' => 50.0,
        ]);

        $this->assertGreaterThanOrEqual(0.1, $result);
        $this->assertLessThanOrEqual(1.0, $result);
    }

    // ===== calculateOverallConfidence =====

    public function testCalculateOverallConfidence(): void {
        $patterns = [
            'monthly' => ['net' => [100, 200, 300, 400, 500, 600]],
            'recurring' => ['bill1', 'bill2'],
        ];

        $result = $this->projector->calculateOverallConfidence($patterns, 3);

        $this->assertGreaterThanOrEqual(0.1, $result);
        $this->assertLessThanOrEqual(1.0, $result);
    }

    // ===== generateMonthlyProjections =====

    public function testGenerateMonthlyProjectionsAccumulatesBalance(): void {
        $patterns = [
            'monthly' => [
                'income' => ['average' => 5000.0, 'trend' => 0.0, 'volatility' => 100],
                'expenses' => ['average' => 3000.0, 'trend' => 0.0, 'volatility' => 100],
            ],
        ];

        $result = $this->projector->generateMonthlyProjections(10000.0, $patterns, 3);

        $this->assertCount(3, $result);
        // First month: income 5000, expenses 3000, net +2000
        $this->assertEqualsWithDelta(5000.0, $result[0]['projectedIncome'], 1.0);
        $this->assertEqualsWithDelta(3000.0, $result[0]['projectedExpenses'], 1.0);
        $this->assertEqualsWithDelta(12000.0, $result[0]['endingBalance'], 1.0);
        // Balance accumulates
        $this->assertGreaterThan($result[0]['endingBalance'], $result[2]['endingBalance']);
    }
}
