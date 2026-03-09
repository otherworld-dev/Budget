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
        $this->assertArrayHasKey('growthProjection', $result);
        $this->assertArrayHasKey('recommendations', $result);
    }

    public function testGetProjectionDCWithoutAge(): void {
        $pension = $this->makePension();
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1');

        // Default 25 years when no age provided
        $this->assertEquals(25, $result['yearsToRetirement']);
    }

    public function testGetProjectionDCZeroRate(): void {
        $pension = $this->makePension([
            'currentBalance' => 10000.0,
            'monthlyContribution' => 100.0,
            'expectedReturnRate' => 0.0,
        ]);
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1', 40);

        // 25 years, 0% rate: 10000 + (100 * 300 months) = 40000
        $this->assertEqualsWithDelta(40000.0, $result['projectedValue'], 0.01);
    }

    public function testGetProjectionDCAlreadyRetired(): void {
        $pension = $this->makePension([
            'currentBalance' => 200000.0,
            'retirementAge' => 65,
        ]);
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1', 70);

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
        $this->assertEquals(27, $result['yearsToRetirement']); // 67-40 (default state pension age)
        $this->assertEmpty($result['recommendations']);
    }

    // ===== DC recommendations =====

    public function testDCRecommendationsNoContribution(): void {
        $pension = $this->makePension(['monthlyContribution' => 0.0]);
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1', 40);

        $this->assertNotEmpty($result['recommendations']);
        $this->assertStringContainsString('Start making regular contributions', $result['recommendations'][0]);
    }

    // ===== getCombinedProjection =====

    public function testGetCombinedProjectionSumsAllTypes(): void {
        $dc = $this->makePension(['id' => 1, 'type' => 'workplace', 'currentBalance' => 50000.0, 'currency' => 'GBP']);
        $db = $this->makePension(['id' => 2, 'type' => 'defined_benefit', 'annualIncome' => 12000.0, 'transferValue' => 200000.0, 'currency' => 'GBP']);
        $state = $this->makePension(['id' => 3, 'type' => 'state', 'annualIncome' => 11000.0, 'currency' => 'GBP']);

        $this->pensionMapper->method('findAll')->willReturn([$dc, $db, $state]);
        $this->pensionMapper->method('find')->willReturnMap([
            [1, 'user1', $dc],
            [2, 'user1', $db],
            [3, 'user1', $state],
        ]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        $result = $this->projector->getCombinedProjection('user1', 40);

        $this->assertEquals(3, $result['pensionCount']);
        $this->assertArrayHasKey('totalCurrentValue', $result);
        $this->assertArrayHasKey('totalProjectedAnnualIncome', $result);
        $this->assertArrayHasKey('totalProjectedMonthlyIncome', $result);
        $this->assertEquals('GBP', $result['baseCurrency']);
        // DC current value = 50000, DB transfer = 200000
        $this->assertEquals(250000.0, $result['totalCurrentValue']);
    }

    public function testGetCombinedProjectionEmpty(): void {
        $this->pensionMapper->method('findAll')->willReturn([]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        $result = $this->projector->getCombinedProjection('user1');

        $this->assertEquals(0, $result['pensionCount']);
        $this->assertEquals(0.0, $result['totalCurrentValue']);
        $this->assertEmpty($result['projections']);
    }

    // ===== growthProjection =====

    public function testGrowthProjectionHasCorrectLength(): void {
        $pension = $this->makePension();
        $this->pensionMapper->method('find')->willReturn($pension);

        $result = $this->projector->getProjection(1, 'user1', 55);

        // 10 years to retirement = 11 data points (year 0..10)
        $this->assertCount(11, $result['growthProjection']);
        $this->assertEquals(50000.0, $result['growthProjection'][0]['value']);
    }
}
