<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionAccountMapper;
use OCA\Budget\Db\PensionContribution;
use OCA\Budget\Db\PensionContributionMapper;
use OCA\Budget\Db\PensionSnapshot;
use OCA\Budget\Db\PensionSnapshotMapper;
use OCA\Budget\Service\CurrencyConversionService;
use OCA\Budget\Service\PensionService;
use PHPUnit\Framework\TestCase;

class PensionServiceTest extends TestCase {
    private PensionService $service;
    private PensionAccountMapper $pensionMapper;
    private PensionSnapshotMapper $snapshotMapper;
    private PensionContributionMapper $contributionMapper;
    private CurrencyConversionService $conversionService;

    protected function setUp(): void {
        $this->pensionMapper = $this->createMock(PensionAccountMapper::class);
        $this->snapshotMapper = $this->createMock(PensionSnapshotMapper::class);
        $this->contributionMapper = $this->createMock(PensionContributionMapper::class);
        $this->conversionService = $this->createMock(CurrencyConversionService::class);

        $this->service = new PensionService(
            $this->pensionMapper,
            $this->snapshotMapper,
            $this->contributionMapper,
            $this->conversionService
        );
    }

    private function makePension(array $overrides = []): PensionAccount {
        $pension = new PensionAccount();
        $defaults = [
            'id' => 1,
            'userId' => 'user1',
            'name' => 'Work Pension',
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
        $pension->setUserId($data['userId']);
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

    // ===== findAll / find =====

    public function testFindAllDelegatesToMapper(): void {
        $pensions = [$this->makePension()];
        $this->pensionMapper->expects($this->once())->method('findAll')
            ->with('user1')->willReturn($pensions);

        $result = $this->service->findAll('user1');
        $this->assertSame($pensions, $result);
    }

    public function testFindDelegatesToMapper(): void {
        $pension = $this->makePension();
        $this->pensionMapper->expects($this->once())->method('find')
            ->with(1, 'user1')->willReturn($pension);

        $result = $this->service->find(1, 'user1');
        $this->assertSame($pension, $result);
    }

    // ===== create =====

    public function testCreateInsertsPensionAccount(): void {
        $this->pensionMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (PensionAccount $p) {
                $this->assertEquals('user1', $p->getUserId());
                $this->assertEquals('My Pension', $p->getName());
                $this->assertEquals('personal', $p->getType());
                $this->assertEquals('USD', $p->getCurrency());
                $p->setId(10);
                return $p;
            });

        // personal is DC type, so snapshot should be created
        $this->pensionMapper->expects($this->once())->method('find')
            ->willReturnCallback(function () {
                return $this->makePension(['id' => 10, 'type' => 'personal', 'currentBalance' => 10000.0]);
            });
        $this->snapshotMapper->expects($this->once())->method('insert')
            ->willReturnCallback(fn($s) => $s);
        $this->pensionMapper->expects($this->once())->method('update')
            ->willReturnCallback(fn($p) => $p);

        $result = $this->service->create('user1', 'My Pension', 'personal', null, 'USD', 10000.0);
        $this->assertEquals('My Pension', $result->getName());
    }

    public function testCreateWithDefaultCurrency(): void {
        $this->pensionMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (PensionAccount $p) {
                $this->assertEquals('GBP', $p->getCurrency());
                $p->setId(1);
                // state pension, no snapshot
                $p->setType('state');
                return $p;
            });

        $this->service->create('user1', 'State Pension', 'state');
    }

    // ===== update =====

    public function testUpdateAppliesOnlyNonNullFields(): void {
        $pension = $this->makePension();
        $this->pensionMapper->method('find')->willReturn($pension);
        $this->pensionMapper->expects($this->once())->method('update')
            ->willReturnCallback(function (PensionAccount $p) {
                $this->assertEquals('Updated Name', $p->getName());
                $this->assertEquals('workplace', $p->getType()); // unchanged
                return $p;
            });

        $this->service->update(1, 'user1', 'Updated Name');
    }

    // ===== delete =====

    public function testDeleteRemovesRelatedData(): void {
        $pension = $this->makePension();
        $this->pensionMapper->method('find')->willReturn($pension);

        $this->snapshotMapper->expects($this->once())->method('deleteByPension')->with(1, 'user1');
        $this->contributionMapper->expects($this->once())->method('deleteByPension')->with(1, 'user1');
        $this->pensionMapper->expects($this->once())->method('delete')->with($pension);

        $this->service->delete(1, 'user1');
    }

    // ===== snapshots =====

    public function testGetSnapshotsVerifiesPensionOwnership(): void {
        $pension = $this->makePension();
        $this->pensionMapper->expects($this->once())->method('find')->with(1, 'user1')
            ->willReturn($pension);
        $this->snapshotMapper->expects($this->once())->method('findByPension')
            ->with(1, 'user1')->willReturn([]);

        $this->service->getSnapshots(1, 'user1');
    }

    public function testCreateSnapshotUpdatesBalance(): void {
        $pension = $this->makePension(['currentBalance' => 40000.0]);
        $this->pensionMapper->method('find')->willReturn($pension);

        $this->snapshotMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (PensionSnapshot $s) {
                $this->assertEquals(55000.0, $s->getBalance());
                $this->assertEquals('2026-03-01', $s->getDate());
                return $s;
            });

        $this->pensionMapper->expects($this->once())->method('update')
            ->willReturnCallback(function (PensionAccount $p) {
                $this->assertEquals(55000.0, $p->getCurrentBalance());
                return $p;
            });

        $this->service->createSnapshot(1, 'user1', 55000.0, '2026-03-01');
    }

    // ===== contributions =====

    public function testCreateContributionVerifiesPension(): void {
        $pension = $this->makePension();
        $this->pensionMapper->expects($this->once())->method('find')->with(1, 'user1')
            ->willReturn($pension);

        $this->contributionMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (PensionContribution $c) {
                $this->assertEquals(500.0, $c->getAmount());
                $this->assertEquals('2026-03-01', $c->getDate());
                return $c;
            });

        $this->service->createContribution(1, 'user1', 500.0, '2026-03-01');
    }

    public function testGetTotalContributions(): void {
        $pension = $this->makePension();
        $this->pensionMapper->method('find')->willReturn($pension);
        $this->contributionMapper->expects($this->once())->method('getTotalByPension')
            ->with(1, 'user1')->willReturn(6000.0);

        $result = $this->service->getTotalContributions(1, 'user1');
        $this->assertEquals(6000.0, $result);
    }

    // ===== getSummary =====

    public function testGetSummaryCategorizesAllPensionTypes(): void {
        $dc = $this->makePension(['id' => 1, 'type' => 'workplace', 'currentBalance' => 50000.0, 'currency' => 'GBP']);
        $db = $this->makePension(['id' => 2, 'type' => 'defined_benefit', 'transferValue' => 100000.0, 'annualIncome' => 10000.0, 'currency' => 'GBP']);
        $state = $this->makePension(['id' => 3, 'type' => 'state', 'annualIncome' => 11500.0, 'currency' => 'GBP']);

        $this->pensionMapper->method('findAll')->willReturn([$dc, $db, $state]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        $result = $this->service->getSummary('user1');

        $this->assertEquals(50000.0, $result['totalDCBalance']);
        $this->assertEquals(100000.0, $result['totalDBTransferValue']);
        $this->assertEquals(10000.0, $result['totalDBIncome']);
        $this->assertEquals(11500.0, $result['stateIncome']);
        $this->assertEquals(150000.0, $result['totalPensionWorth']); // DC + DB transfer
        $this->assertEquals(21500.0, $result['totalProjectedIncome']); // DB + state
        $this->assertEquals(3, $result['pensionCount']);
        $this->assertEquals(1, $result['dcCount']);
        $this->assertEquals(1, $result['dbCount']);
        $this->assertEquals(1, $result['stateCount']);
    }

    public function testGetSummaryConvertsCurrencies(): void {
        $dc = $this->makePension(['id' => 1, 'type' => 'workplace', 'currentBalance' => 50000.0, 'currency' => 'USD']);

        $this->pensionMapper->method('findAll')->willReturn([$dc]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');
        $this->conversionService->method('convertToBaseFloat')->willReturn(40000.0);

        $result = $this->service->getSummary('user1');

        $this->assertEquals(40000.0, $result['totalDCBalance']);
    }

    public function testGetSummaryWithNoPensions(): void {
        $this->pensionMapper->method('findAll')->willReturn([]);
        $this->conversionService->method('getBaseCurrency')->willReturn('GBP');

        $result = $this->service->getSummary('user1');

        $this->assertEquals(0.0, $result['totalPensionWorth']);
        $this->assertEquals(0, $result['pensionCount']);
    }
}
