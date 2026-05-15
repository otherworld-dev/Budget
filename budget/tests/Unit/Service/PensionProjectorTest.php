<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\PensionProjector;
use OCA\Budget\Service\PensionService;
use PHPUnit\Framework\TestCase;

class PensionProjectorTest extends TestCase {
    private PensionProjector $projector;
    private PensionAccountMapper $pensionMapper;
    private PensionService $pensionService;
    private CurrencyConversionService $conversionService;

    protected function setUp(): void {
        $this->pensionMapper = $this->createMock(PensionAccountMapper::class);
        $this->pensionService = $this->createMock(PensionService::class);
        $this->conversionService = $this->createMock(CurrencyConversionService::class);

        $this->projector = new PensionProjector(
            $this->pensionMapper,
            $this->pensionService,
            $this->conversionService
        );
    }

    private function makePension(array $overrides = []): PensionAccount {
        $pension = new PensionAccount();
        $defaults = [
            'id' => 1,
            'name' => 'Work DC',
            'type' => 'workplace',
            'currency' => 'GBP',
            'currentBalance' => 50000.0,
            'monthlyContribution' => 500.0,
            'expectedReturnRate' => 0.05,
            'retirementAge' => 65,
            'annualIncome' => null,
            'transferValue' => null,
        ];
        $data = array_merge($defaults, $overrides);

        $pension->setId($data['id']);
        $pension->setName($data['name']);
        $pension->setType($data['type']);
        $pension->setCurrency($data['currency']);
        $pension->setCurrentBalance($data['currentBalance']);
        $pension->setMonthlyContribution($data['monthlyContribution']);
        $pension->setExpectedReturnRate($data['expectedReturnRate']);
        $pension->setRetirementAge($data['retirementAge']);
        $pension->setAnnualIncome($data['annualIncome']);
        $pension->setTransferValue($data['transferValue']);

        return $pension;
    }

    // ===== getProjection - DC pension =====

    public function testGetProjectionDCWithAge(): void {
        $pension = $this->makePension([
            'currentBalance' => 100000.0,
            'monthlyContribution' => 1000.0,
            'expectedReturnRate' => 0.06,
            'retirementAge' => 65,
        ]);
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1', 35);

        $this->assertEquals('workplace', $result['type']);
        $this->assertEquals(100000.0, $result['currentBalance']);
        $this->assertEquals(30, $result['yearsToRetirement']);
        $this->assertGreaterThan(100000.0, $result['projectedValue']);
        // 4% withdrawal rate on projected value
        $this->assertEqualsWithDelta($result['projectedValue'] * 0.04, $result['estimatedAnnualIncome'], 0.01);
        $this->assertEqualsWithDelta($result['estimatedAnnualIncome'] / 12, $result['estimatedMonthlyIncome'], 0.01);
        $this->assertArrayHasKey('growthProjection', $result);
        $this->assertArrayHasKey('recommendations', $result);
        $this->assertArrayHasKey('requiredMonthlyFor500k', $result);
    }

    public function testGetProjectionDCWithoutAge(): void {
        $pension = $this->makePension();
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1');

        // Default 25 years when no age provided
        $this->assertEquals(25, $result['yearsToRetirement']);
    }

    public function testGetProjectionDCZeroReturnRate(): void {
        $pension = $this->makePension([
            'currentBalance' => 10000.0,
            'monthlyContribution' => 100.0,
            'expectedReturnRate' => 0.0,
        ]);
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1', 40);

        // 25 years to retirement, 0% rate: FV = PV + PMT * months = 10000 + (100 * 300) = 40000
        $this->assertEqualsWithDelta(40000.0, $result['projectedValue'], 0.01);
        // 4% of 40000 = 1600
        $this->assertEqualsWithDelta(1600.0, $result['estimatedAnnualIncome'], 0.01);
    }

    public function testGetProjectionDCZeroContribution(): void {
        $pension = $this->makePension([
            'currentBalance' => 50000.0,
            'monthlyContribution' => 0.0,
            'expectedReturnRate' => 0.05,
            'retirementAge' => 65,
        ]);
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1', 45);

        // 20 years, 5% annual return, no contributions: FV = 50000 * (1 + 0.05/12)^240
        $monthlyRate = 0.05 / 12;
        $expected = 50000.0 * pow(1 + $monthlyRate, 240);
        $this->assertEqualsWithDelta(round($expected, 2), $result['projectedValue'], 0.01);
        $this->assertEquals(0.0, $result['monthlyContribution']);
    }

    public function testGetProjectionDCAlreadyRetired(): void {
        $pension = $this->makePension([
            'currentBalance' => 200000.0,
            'retirementAge' => 65,
        ]);
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1', 70);

        // Age > retirement age means 0 years to retirement, returns current balance as-is
        $this->assertEquals(0, $result['yearsToRetirement']);
        $this->assertEquals(200000.0, $result['projectedValue']);
    }

    // ===== getProjection - DB pension =====

    public function testGetProjectionDBPension(): void {
        $pension = $this->makePension([
            'type' => 'defined_benefit',
            'annualIncome' => 15000.0,
            'transferValue' => 300000.0,
        ]);
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1', 45);

        $this->assertEquals('defined_benefit', $result['type']);
        $this->assertEquals(15000.0, $result['annualIncome']);
        $this->assertEquals(1250.0, $result['monthlyIncome']);
        $this->assertEquals(300000.0, $result['transferValue']);
        $this->assertEquals(20, $result['yearsToRetirement']);
        $this->assertArrayHasKey('recommendations', $result);
        // Should not have DC-specific fields
        $this->assertArrayNotHasKey('projectedValue', $result);
        $this->assertArrayNotHasKey('growthProjection', $result);
    }

    // ===== getProjection - State pension =====

    public function testGetProjectionStatePension(): void {
        $pension = $this->makePension([
            'type' => 'state',
            'annualIncome' => 11500.0,
            'retirementAge' => null,
        ]);
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1', 40);

        $this->assertEquals('state', $result['type']);
        $this->assertEquals(11500.0, $result['annualIncome']);
        $this->assertEqualsWithDelta(958.33, $result['monthlyIncome'], 0.01);
        // Default state pension age is 67
        $this->assertEquals(27, $result['yearsToRetirement']);
        $this->assertEmpty($result['recommendations']);
        // Should not have DC or DB-specific fields
        $this->assertArrayNotHasKey('projectedValue', $result);
        $this->assertArrayNotHasKey('transferValue', $result);
    }

    // ===== getCombinedProjection =====

    public function testGetCombinedProjectionEmpty(): void {
        $this->pensionMapper->method('findAll')->willReturn([]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        $result = $this->projector->getCombinedProjection('user1');

        $this->assertEquals(0, $result['pensionCount']);
        $this->assertEquals(0.0, $result['totalCurrentValue']);
        $this->assertEquals(0.0, $result['totalProjectedValue']);
        $this->assertEquals(0.0, $result['totalProjectedAnnualIncome']);
        $this->assertEquals(0.0, $result['totalProjectedMonthlyIncome']);
        $this->assertEmpty($result['projections']);
        $this->assertEquals('GBP', $result['baseCurrency']);
    }

    public function testGetCombinedProjectionMixedTypes(): void {
        $dc = $this->makePension([
            'id' => 1,
            'type' => 'workplace',
            'currentBalance' => 50000.0,
            'currency' => 'GBP',
        ]);
        $db = $this->makePension([
            'id' => 2,
            'type' => 'defined_benefit',
            'annualIncome' => 12000.0,
            'transferValue' => 200000.0,
            'currency' => 'GBP',
        ]);
        $state = $this->makePension([
            'id' => 3,
            'type' => 'state',
            'annualIncome' => 11000.0,
            'currency' => 'GBP',
        ]);

        $this->pensionMapper->method('findAll')->willReturn([$dc, $db, $state]);
        $this->pensionMapper->method('find')->willReturnMap([
            [1, 'user1', $dc],
            [2, 'user1', $db],
            [3, 'user1', $state],
        ]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        $result = $this->projector->getCombinedProjection('user1', 40);

        $this->assertEquals(3, $result['pensionCount']);
        $this->assertCount(3, $result['projections']);
        $this->assertEquals('GBP', $result['baseCurrency']);
        // DC current value (50000) + DB transfer value (200000) = 250000
        $this->assertEquals(250000.0, $result['totalCurrentValue']);
        // DC projected value should be > 50000 (has contributions + returns)
        $this->assertGreaterThan(0.0, $result['totalProjectedValue']);
        // Annual income includes DB (12000) + state (11000) + 4% of DC projected
        $this->assertGreaterThan(23000.0, $result['totalProjectedAnnualIncome']);
        $this->assertEqualsWithDelta(
            $result['totalProjectedAnnualIncome'] / 12,
            $result['totalProjectedMonthlyIncome'],
            0.01
        );
    }

    public function testGetCombinedProjectionConvertsDifferentCurrencies(): void {
        $dcUsd = $this->makePension([
            'id' => 1,
            'type' => 'sipp',
            'currentBalance' => 100000.0,
            'monthlyContribution' => 0.0,
            'expectedReturnRate' => 0.0,
            'retirementAge' => 65,
            'currency' => 'USD',
        ]);
        $dbEur = $this->makePension([
            'id' => 2,
            'type' => 'defined_benefit',
            'annualIncome' => 10000.0,
            'transferValue' => 150000.0,
            'currency' => 'EUR',
        ]);

        $this->pensionMapper->method('findAll')->willReturn([$dcUsd, $dbEur]);
        $this->pensionMapper->method('find')->willReturnMap([
            [1, 'user1', $dcUsd],
            [2, 'user1', $dbEur],
        ]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        // USD->GBP: multiply by 0.8; EUR->GBP: multiply by 0.85
        $this->conversionService->method('convertToBaseFloat')
            ->willReturnCallback(function (float $amount, string $from, string $userId): float {
                if ($from === 'USD') {
                    return $amount * 0.8;
                }
                if ($from === 'EUR') {
                    return $amount * 0.85;
                }
                return $amount;
            });

        $result = $this->projector->getCombinedProjection('user1', 40);

        // DC: 0% return, 0 contribution, 25 years => projectedValue = currentBalance = 100000
        // DC current value: 100000 USD * 0.8 = 80000 GBP
        // DC projected value: 100000 USD * 0.8 = 80000 GBP
        // DB transfer value: 150000 EUR * 0.85 = 127500 GBP
        $this->assertEqualsWithDelta(80000.0 + 127500.0, $result['totalCurrentValue'], 0.01);
        $this->assertEqualsWithDelta(80000.0, $result['totalProjectedValue'], 0.01);

        // DC income: projectedValue 100000 * 0.8 (conversion) * 0.04 = 3200
        // DB income: 10000 EUR * 0.85 = 8500
        $expectedAnnualIncome = 3200.0 + 8500.0;
        $this->assertEqualsWithDelta($expectedAnnualIncome, $result['totalProjectedAnnualIncome'], 0.01);
    }

    public function testGetCombinedProjectionSameCurrencySkipsConversion(): void {
        $dc = $this->makePension([
            'id' => 1,
            'type' => 'workplace',
            'currentBalance' => 30000.0,
            'monthlyContribution' => 0.0,
            'expectedReturnRate' => 0.0,
            'retirementAge' => 65,
            'currency' => 'GBP',
        ]);

        $this->pensionMapper->method('findAll')->willReturn([$dc]);
        $this->pensionMapper->method('find')->willReturn($dc);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        // convertToBaseFloat should never be called when currencies match
        $this->conversionService->expects($this->never())->method('convertToBaseFloat');

        $result = $this->projector->getCombinedProjection('user1', 40);

        // 0% return, 0 contributions => projected = current = 30000
        $this->assertEqualsWithDelta(30000.0, $result['totalCurrentValue'], 0.01);
        $this->assertEqualsWithDelta(30000.0, $result['totalProjectedValue'], 0.01);
    }

    // ===== Growth projection structure =====

    public function testGrowthProjectionHasCorrectLength(): void {
        $pension = $this->makePension();
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1', 55);

        // 10 years to retirement (65 - 55) = 11 data points (year 0..10)
        $this->assertCount(11, $result['growthProjection']);
        $this->assertEquals(50000.0, $result['growthProjection'][0]['value']);
        // Each subsequent year should be larger
        $this->assertGreaterThan(
            $result['growthProjection'][0]['value'],
            $result['growthProjection'][1]['value']
        );
    }
}
