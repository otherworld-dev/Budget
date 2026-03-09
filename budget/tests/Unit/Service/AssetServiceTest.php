<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Asset;
use OCA\Budget\Db\AssetMapper;
use OCA\Budget\Db\AssetSnapshot;
use OCA\Budget\Db\AssetSnapshotMapper;
use OCA\Budget\Service\AssetService;
use OCA\Budget\Service\CurrencyConversionService;
use PHPUnit\Framework\TestCase;

class AssetServiceTest extends TestCase {
    private AssetService $service;
    private AssetMapper $assetMapper;
    private AssetSnapshotMapper $snapshotMapper;
    private CurrencyConversionService $conversionService;

    protected function setUp(): void {
        $this->assetMapper = $this->createMock(AssetMapper::class);
        $this->snapshotMapper = $this->createMock(AssetSnapshotMapper::class);
        $this->conversionService = $this->createMock(CurrencyConversionService::class);

        $this->service = new AssetService(
            $this->assetMapper,
            $this->snapshotMapper,
            $this->conversionService
        );
    }

    private function makeAsset(array $overrides = []): Asset {
        $asset = new Asset();
        $defaults = [
            'id' => 1,
            'userId' => 'user1',
            'name' => 'My House',
            'type' => 'real_estate',
            'description' => null,
            'currency' => 'GBP',
            'currentValue' => 250000.0,
            'purchasePrice' => 200000.0,
            'purchaseDate' => '2020-01-01',
            'annualChangeRate' => 0.05,
        ];
        $data = array_merge($defaults, $overrides);

        $asset->setId($data['id']);
        $asset->setUserId($data['userId']);
        $asset->setName($data['name']);
        $asset->setType($data['type']);
        $asset->setDescription($data['description']);
        $asset->setCurrency($data['currency']);
        $asset->setCurrentValue($data['currentValue']);
        $asset->setPurchasePrice($data['purchasePrice']);
        $asset->setPurchaseDate($data['purchaseDate']);
        $asset->setAnnualChangeRate($data['annualChangeRate']);

        return $asset;
    }

    // ===== findAll / find =====

    public function testFindAllDelegatesToMapper(): void {
        $assets = [$this->makeAsset()];
        $this->assetMapper->expects($this->once())->method('findAll')
            ->with('user1')->willReturn($assets);

        $result = $this->service->findAll('user1');
        $this->assertSame($assets, $result);
    }

    public function testFindDelegatesToMapper(): void {
        $asset = $this->makeAsset();
        $this->assetMapper->expects($this->once())->method('find')
            ->with(1, 'user1')->willReturn($asset);

        $result = $this->service->find(1, 'user1');
        $this->assertSame($asset, $result);
    }

    // ===== create =====

    public function testCreateInsertsAssetWithInitialSnapshot(): void {
        $this->assetMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (Asset $a) {
                $this->assertEquals('user1', $a->getUserId());
                $this->assertEquals('My Car', $a->getName());
                $this->assertEquals('vehicle', $a->getType());
                $this->assertEquals('USD', $a->getCurrency());
                $this->assertEquals(30000.0, $a->getCurrentValue());
                $a->setId(10);
                return $a;
            });

        // Snapshot created because currentValue is not null
        $this->snapshotMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AssetSnapshot $s) {
                $this->assertEquals(10, $s->getAssetId());
                $this->assertEquals(30000.0, $s->getValue());
                return $s;
            });

        // createSnapshot calls find + update
        $this->assetMapper->method('find')->willReturnCallback(function () {
            return $this->makeAsset(['id' => 10, 'currentValue' => 30000.0]);
        });
        $this->assetMapper->method('update')->willReturnCallback(fn($a) => $a);

        $result = $this->service->create('user1', 'My Car', 'vehicle', null, 'USD', 30000.0);
        $this->assertEquals('My Car', $result->getName());
    }

    public function testCreateWithoutValueSkipsSnapshot(): void {
        $this->assetMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (Asset $a) {
                $this->assertEquals('USD', $a->getCurrency()); // defaults to USD when null
                $a->setId(1);
                return $a;
            });

        // No snapshot when currentValue is null
        $this->snapshotMapper->expects($this->never())->method('insert');

        $this->service->create('user1', 'Painting', 'collectibles', null, null, null);
    }

    public function testCreateDefaultsCurrencyToUSD(): void {
        $this->assetMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (Asset $a) {
                $this->assertEquals('USD', $a->getCurrency());
                $a->setId(1);
                return $a;
            });

        $this->service->create('user1', 'Art', 'other');
    }

    // ===== update =====

    public function testUpdateAppliesOnlyNonNullFields(): void {
        $asset = $this->makeAsset();
        $this->assetMapper->method('find')->willReturn($asset);
        $this->assetMapper->expects($this->once())->method('update')
            ->willReturnCallback(function (Asset $a) {
                $this->assertEquals('Updated Name', $a->getName());
                $this->assertEquals('real_estate', $a->getType()); // unchanged
                return $a;
            });

        $this->service->update(1, 'user1', 'Updated Name');
    }

    // ===== delete =====

    public function testDeleteRemovesSnapshotsAndAsset(): void {
        $asset = $this->makeAsset();
        $this->assetMapper->method('find')->willReturn($asset);

        $this->snapshotMapper->expects($this->once())->method('deleteByAsset')->with(1, 'user1');
        $this->assetMapper->expects($this->once())->method('delete')->with($asset);

        $this->service->delete(1, 'user1');
    }

    // ===== snapshots =====

    public function testGetSnapshotsVerifiesOwnership(): void {
        $asset = $this->makeAsset();
        $this->assetMapper->expects($this->once())->method('find')->with(1, 'user1')
            ->willReturn($asset);
        $this->snapshotMapper->expects($this->once())->method('findByAsset')
            ->with(1, 'user1')->willReturn([]);

        $this->service->getSnapshots(1, 'user1');
    }

    public function testCreateSnapshotUpdatesAssetValue(): void {
        $asset = $this->makeAsset(['currentValue' => 200000.0]);
        $this->assetMapper->method('find')->willReturn($asset);

        $this->snapshotMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (AssetSnapshot $s) {
                $this->assertEquals(275000.0, $s->getValue());
                $this->assertEquals('2026-03-01', $s->getDate());
                return $s;
            });

        $this->assetMapper->expects($this->once())->method('update')
            ->willReturnCallback(function (Asset $a) {
                $this->assertEquals(275000.0, $a->getCurrentValue());
                return $a;
            });

        $this->service->createSnapshot(1, 'user1', 275000.0, '2026-03-01');
    }

    public function testDeleteSnapshotDelegatesToMapper(): void {
        $snapshot = $this->createMock(AssetSnapshot::class);
        $this->snapshotMapper->expects($this->once())->method('find')
            ->with(5, 'user1')->willReturn($snapshot);
        $this->snapshotMapper->expects($this->once())->method('delete')->with($snapshot);

        $this->service->deleteSnapshot(5, 'user1');
    }

    // ===== getSummary =====

    public function testGetSummaryGroupsByTypeWithConversion(): void {
        $house = $this->makeAsset(['id' => 1, 'type' => 'real_estate', 'currentValue' => 250000.0, 'currency' => 'GBP']);
        $car = $this->makeAsset(['id' => 2, 'type' => 'vehicle', 'currentValue' => 30000.0, 'currency' => 'GBP']);
        $ring = $this->makeAsset(['id' => 3, 'type' => 'jewelry', 'currentValue' => 5000.0, 'currency' => 'USD']);

        $this->assetMapper->method('findAll')->willReturn([$house, $car, $ring]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');
        $this->conversionService->method('canConvert')->willReturn(true);
        $this->conversionService->method('convertToBaseFloat')->willReturn(4000.0); // 5000 USD → 4000 GBP

        $result = $this->service->getSummary('user1');

        $this->assertEquals(3, $result['assetCount']);
        $this->assertEquals('GBP', $result['baseCurrency']);
        // 250000 + 30000 + 4000 = 284000
        $this->assertEquals(284000.0, $result['totalAssetWorth']);
        $this->assertEquals(1, $result['assetsByType']['real_estate']['count']);
        $this->assertEquals(250000.0, $result['assetsByType']['real_estate']['value']);
        $this->assertEquals(1, $result['assetsByType']['vehicle']['count']);
        $this->assertEquals(30000.0, $result['assetsByType']['vehicle']['value']);
        $this->assertEquals(1, $result['assetsByType']['jewelry']['count']);
        $this->assertEquals(4000.0, $result['assetsByType']['jewelry']['value']);
    }

    public function testGetSummaryNoAssets(): void {
        $this->assetMapper->method('findAll')->willReturn([]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        $result = $this->service->getSummary('user1');

        $this->assertEquals(0, $result['assetCount']);
        $this->assertEquals(0.0, $result['totalAssetWorth']);
    }

    public function testGetSummarySkipsConversionForSameCurrency(): void {
        $asset = $this->makeAsset(['currency' => 'GBP', 'currentValue' => 100000.0]);

        $this->assetMapper->method('findAll')->willReturn([$asset]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');
        // convertToBaseFloat should NOT be called for same currency
        $this->conversionService->expects($this->never())->method('convertToBaseFloat');

        $result = $this->service->getSummary('user1');
        $this->assertEquals(100000.0, $result['totalAssetWorth']);
    }

    public function testGetSummaryInitializesAllValidTypes(): void {
        $this->assetMapper->method('findAll')->willReturn([]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        $result = $this->service->getSummary('user1');

        foreach (Asset::VALID_TYPES as $type) {
            $this->assertArrayHasKey($type, $result['assetsByType']);
            $this->assertEquals(0, $result['assetsByType'][$type]['count']);
            $this->assertEquals(0.0, $result['assetsByType'][$type]['value']);
        }
    }
}
