<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Asset;
use OCA\Budget\Db\AssetMapper;
use OCA\Budget\Service\AssetProjector;
use OCA\Budget\Service\CurrencyConversionService;
use PHPUnit\Framework\TestCase;

class AssetProjectorTest extends TestCase {
    private AssetProjector $projector;
    private AssetMapper $assetMapper;
    private CurrencyConversionService $conversionService;

    protected function setUp(): void {
        $this->assetMapper = $this->createMock(AssetMapper::class);
        $this->conversionService = $this->createMock(CurrencyConversionService::class);
        $this->projector = new AssetProjector($this->assetMapper, $this->conversionService);
    }

    private function makeAsset(array $overrides = []): Asset {
        $asset = new Asset();
        $defaults = [
            'id' => 1,
            'name' => 'House',
            'type' => 'property',
            'currentValue' => 300000.00,
            'annualChangeRate' => 0.03,
            'currency' => 'GBP',
        ];
        $data = array_merge($defaults, $overrides);

        $asset->setId($data['id']);
        $asset->setName($data['name']);
        $asset->setType($data['type']);
        $asset->setCurrentValue($data['currentValue']);
        $asset->setAnnualChangeRate($data['annualChangeRate']);
        $asset->setCurrency($data['currency']);

        return $asset;
    }

    // ===== getProjection =====

    public function testGetProjectionCalculatesCompoundGrowth(): void {
        $asset = $this->makeAsset(['currentValue' => 100000.00, 'annualChangeRate' => 0.05]);
        $this->assetMapper->method('find')->willReturn($asset);

        $result = $this->projector->getProjection(1, 'user1', 10);

        // 100000 * (1.05)^10 ≈ 162889.46
        $this->assertEqualsWithDelta(162889.46, $result['projectedValue'], 0.01);
        $this->assertEquals(100000.00, $result['currentValue']);
        $this->assertEquals('House', $result['assetName']);
    }

    public function testGetProjectionWithZeroRate(): void {
        $asset = $this->makeAsset(['currentValue' => 50000.00, 'annualChangeRate' => 0.0]);
        $this->assetMapper->method('find')->willReturn($asset);

        $result = $this->projector->getProjection(1, 'user1', 5);

        $this->assertEquals(50000.00, $result['projectedValue']);
        $this->assertEquals(0, $result['totalChange']);
    }

    public function testGetProjectionWithNegativeRate(): void {
        $asset = $this->makeAsset(['currentValue' => 20000.00, 'annualChangeRate' => -0.10]);
        $this->assetMapper->method('find')->willReturn($asset);

        $result = $this->projector->getProjection(1, 'user1', 3);

        // 20000 * (0.9)^3 = 14580
        $this->assertEqualsWithDelta(14580.00, $result['projectedValue'], 0.01);
        $this->assertLessThan(0, $result['totalChange']);
    }

    public function testGetProjectionIncludesGrowthTimeline(): void {
        $asset = $this->makeAsset(['currentValue' => 100.00, 'annualChangeRate' => 0.10]);
        $this->assetMapper->method('find')->willReturn($asset);

        $result = $this->projector->getProjection(1, 'user1', 3);

        // Year 0 through year 3 = 4 data points
        $this->assertCount(4, $result['growthProjection']);
        $this->assertEquals(100.00, $result['growthProjection'][0]['value']);
        $this->assertEqualsWithDelta(110.00, $result['growthProjection'][1]['value'], 0.01);
    }

    public function testGetProjectionWithZeroValue(): void {
        $asset = $this->makeAsset(['currentValue' => 0.0, 'annualChangeRate' => 0.05]);
        $this->assetMapper->method('find')->willReturn($asset);

        $result = $this->projector->getProjection(1, 'user1', 5);

        $this->assertEquals(0.0, $result['projectedValue']);
        $this->assertEquals(0, $result['totalChangePercent']);
    }

    // ===== getCombinedProjection =====

    public function testGetCombinedProjectionSumsValues(): void {
        $assets = [
            $this->makeAsset(['id' => 1, 'currentValue' => 100000, 'annualChangeRate' => 0.0, 'currency' => 'GBP']),
            $this->makeAsset(['id' => 2, 'currentValue' => 50000, 'annualChangeRate' => 0.0, 'currency' => 'GBP']),
        ];
        $this->assetMapper->method('findAll')->willReturn($assets);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        $result = $this->projector->getCombinedProjection('user1', 5);

        $this->assertEquals(150000.00, $result['totalCurrentValue']);
        $this->assertEquals(150000.00, $result['totalProjectedValue']);
        $this->assertEquals(2, $result['assetCount']);
        $this->assertEquals('GBP', $result['baseCurrency']);
    }

    public function testGetCombinedProjectionConvertsCurrencies(): void {
        $assets = [
            $this->makeAsset(['id' => 1, 'currentValue' => 100000, 'annualChangeRate' => 0.0, 'currency' => 'GBP']),
            $this->makeAsset(['id' => 2, 'currentValue' => 50000, 'annualChangeRate' => 0.0, 'currency' => 'USD']),
        ];
        $this->assetMapper->method('findAll')->willReturn($assets);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');
        // USD 50000 -> GBP 40000
        $this->conversionService->method('convertToBaseFloat')->willReturn(40000.00);

        $result = $this->projector->getCombinedProjection('user1', 5);

        $this->assertEquals(140000.00, $result['totalCurrentValue']);
    }

    public function testGetCombinedProjectionWithNoAssets(): void {
        $this->assetMapper->method('findAll')->willReturn([]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        $result = $this->projector->getCombinedProjection('user1');

        $this->assertEquals(0.0, $result['totalCurrentValue']);
        $this->assertEquals(0, $result['assetCount']);
        $this->assertEmpty($result['projections']);
    }
}
