<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\DebtScenario;
use OCA\Budget\Db\DebtScenarioMapper;
use OCA\Budget\Service\DebtPayoffService;
use OCA\Budget\Service\DebtScenarioService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DebtScenarioServiceTest extends TestCase {
    private DebtScenarioService $service;
    private DebtScenarioMapper $mapper;
    private DebtPayoffService $payoffService;
    private LoggerInterface $logger;

    protected function setUp(): void {
        $this->mapper = $this->createMock(DebtScenarioMapper::class);
        $this->payoffService = $this->createMock(DebtPayoffService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new DebtScenarioService(
            $this->mapper,
            $this->payoffService,
            $this->logger
        );
    }

    private function makeScenario(array $overrides = []): DebtScenario {
        $scenario = new DebtScenario();
        $defaults = [
            'id' => 1,
            'userId' => 'user1',
            'name' => 'Test Scenario',
            'strategy' => 'avalanche',
            'extraPayment' => 100.0,
            'lumpSum' => 0.0,
            'lumpSumMonth' => 1,
            'selectedDebtIds' => null,
            'rateOverrides' => null,
            'isActive' => false,
            'originalTotalDebt' => 5000.0,
        ];
        $data = array_merge($defaults, $overrides);

        $scenario->setId($data['id']);
        $scenario->setUserId($data['userId']);
        $scenario->setName($data['name']);
        $scenario->setStrategy($data['strategy']);
        $scenario->setExtraPayment($data['extraPayment']);
        $scenario->setLumpSum($data['lumpSum']);
        $scenario->setLumpSumMonth($data['lumpSumMonth']);
        $scenario->setSelectedDebtIds($data['selectedDebtIds']);
        $scenario->setRateOverrides($data['rateOverrides']);
        $scenario->setIsActive($data['isActive']);
        $scenario->setOriginalTotalDebt($data['originalTotalDebt']);
        return $scenario;
    }

    private function makeAccount(int $id = 1, float $balance = 0.0): Account {
        $account = new Account();
        $account->setId($id);
        $account->setBalance($balance);
        return $account;
    }

    // ── findAll ─────────────────────────────────────────────────────

    public function testFindAllDelegatesToMapper(): void {
        $scenarios = [
            $this->makeScenario(['id' => 1]),
            $this->makeScenario(['id' => 2, 'name' => 'Second']),
        ];
        $this->mapper->expects($this->once())
            ->method('findAll')
            ->with('user1')
            ->willReturn($scenarios);

        $result = $this->service->findAll('user1');

        $this->assertCount(2, $result);
        $this->assertSame($scenarios, $result);
    }

    // ── find ────────────────────────────────────────────────────────

    public function testFindDelegatesToMapper(): void {
        $scenario = $this->makeScenario();
        $this->mapper->expects($this->once())
            ->method('find')
            ->with(1, 'user1')
            ->willReturn($scenario);

        $result = $this->service->find(1, 'user1');

        $this->assertSame($scenario, $result);
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->mapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);

        $this->service->find(999, 'user1');
    }

    // ── create ──────────────────────────────────────────────────────

    public function testCreateSetsAllFields(): void {
        $this->payoffService->method('getSummary')
            ->with('user1')
            ->willReturn(['totalBalance' => -15000.0]);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (DebtScenario $scenario) {
                $this->assertEquals('user1', $scenario->getUserId());
                $this->assertEquals('Aggressive', $scenario->getName());
                $this->assertEquals('snowball', $scenario->getStrategy());
                $this->assertEquals(200.0, $scenario->getExtraPayment());
                $this->assertEquals(500.0, $scenario->getLumpSum());
                $this->assertEquals(3, $scenario->getLumpSumMonth());
                $this->assertNull($scenario->getSelectedDebtIds());
                $this->assertNull($scenario->getRateOverrides());
                $this->assertFalse($scenario->getIsActive());
                $this->assertEquals(15000.0, $scenario->getOriginalTotalDebt());
                $this->assertNotNull($scenario->getCreatedAt());
                $this->assertNotNull($scenario->getUpdatedAt());
                $scenario->setId(1);
                return $scenario;
            });

        $result = $this->service->create('user1', [
            'name' => 'Aggressive',
            'strategy' => 'snowball',
            'extraPayment' => 200,
            'lumpSum' => 500,
            'lumpSumMonth' => 3,
        ]);

        $this->assertEquals('Aggressive', $result->getName());
    }

    public function testCreateUsesDefaults(): void {
        $this->payoffService->method('getSummary')
            ->willReturn(['totalBalance' => -8000.0]);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (DebtScenario $scenario) {
                $this->assertEquals('avalanche', $scenario->getStrategy());
                $this->assertEquals(0.0, $scenario->getExtraPayment());
                $this->assertEquals(0.0, $scenario->getLumpSum());
                $this->assertEquals(1, $scenario->getLumpSumMonth());
                $scenario->setId(1);
                return $scenario;
            });

        $this->service->create('user1', ['name' => 'Default']);
    }

    public function testCreateWithSelectedDebtIdsJsonEncodes(): void {
        $debt1 = $this->makeAccount(10, -3000.0);
        $debt2 = $this->makeAccount(20, -2000.0);

        $this->payoffService->method('getDebts')
            ->with('user1')
            ->willReturn([$debt1, $debt2]);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (DebtScenario $scenario) {
                $this->assertEquals('[10,20]', $scenario->getSelectedDebtIds());
                $this->assertEquals(5000.0, $scenario->getOriginalTotalDebt());
                $scenario->setId(1);
                return $scenario;
            });

        $this->service->create('user1', [
            'name' => 'Selective',
            'selectedDebtIds' => [10, 20],
        ]);
    }

    public function testCreateWithRateOverridesJsonEncodes(): void {
        $this->payoffService->method('getSummary')
            ->willReturn(['totalBalance' => -5000.0]);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (DebtScenario $scenario) {
                $decoded = json_decode($scenario->getRateOverrides(), true);
                $this->assertEquals(['10' => 5.5, '20' => 3.2], $decoded);
                $scenario->setId(1);
                return $scenario;
            });

        $this->service->create('user1', [
            'name' => 'Custom Rates',
            'rateOverrides' => ['10' => 5.5, '20' => 3.2],
        ]);
    }

    // ── update ───────────────────────────────────────────────────────

    public function testUpdateJsonEncodesSelectedDebtIds(): void {
        $scenario = $this->makeScenario();
        $this->mapper->method('find')->with(1, 'user1')->willReturn($scenario);
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (DebtScenario $s) {
                $this->assertEquals('[5,10]', $s->getSelectedDebtIds());
                return $s;
            });

        $this->service->update(1, 'user1', ['selectedDebtIds' => [5, 10]]);
    }

    public function testUpdateJsonEncodesRateOverrides(): void {
        $scenario = $this->makeScenario();
        $this->mapper->method('find')->with(1, 'user1')->willReturn($scenario);
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (DebtScenario $s) {
                $decoded = json_decode($s->getRateOverrides(), true);
                $this->assertEquals(['1' => 4.0], $decoded);
                return $s;
            });

        $this->service->update(1, 'user1', ['rateOverrides' => ['1' => 4.0]]);
    }

    public function testUpdateSetsNullForNullArrayFields(): void {
        $scenario = $this->makeScenario(['selectedDebtIds' => '[1,2]']);
        $this->mapper->method('find')->with(1, 'user1')->willReturn($scenario);
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (DebtScenario $s) {
                $this->assertNull($s->getSelectedDebtIds());
                return $s;
            });

        $this->service->update(1, 'user1', ['selectedDebtIds' => null]);
    }

    public function testUpdateScalarFields(): void {
        $scenario = $this->makeScenario();
        $this->mapper->method('find')->with(1, 'user1')->willReturn($scenario);
        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (DebtScenario $s) {
                $this->assertEquals('Renamed', $s->getName());
                $this->assertEquals('snowball', $s->getStrategy());
                $this->assertEquals(300.0, $s->getExtraPayment());
                return $s;
            });

        $this->service->update(1, 'user1', [
            'name' => 'Renamed',
            'strategy' => 'snowball',
            'extraPayment' => 300.0,
        ]);
    }

    // ── delete (inherited) ──────────────────────────────────────────

    public function testDeleteDelegatesToMapper(): void {
        $scenario = $this->makeScenario();
        $this->mapper->method('find')->with(1, 'user1')->willReturn($scenario);
        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($scenario);

        $this->service->delete(1, 'user1');
    }

    public function testDeleteThrowsWhenNotFound(): void {
        $this->mapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);

        $this->service->delete(999, 'user1');
    }

    // ── activate ────────────────────────────────────────────────────

    public function testActivateDeactivatesAllThenActivatesOne(): void {
        $scenario = $this->makeScenario(['isActive' => false]);

        $this->mapper->expects($this->once())
            ->method('deactivateAll')
            ->with('user1');

        $this->mapper->method('find')->with(1, 'user1')->willReturn($scenario);

        $this->payoffService->method('getSummary')
            ->with('user1')
            ->willReturn(['totalBalance' => -12000.0]);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (DebtScenario $s) {
                $this->assertTrue($s->getIsActive());
                $this->assertEquals(12000.0, $s->getOriginalTotalDebt());
                $this->assertNotNull($s->getUpdatedAt());
                return $s;
            });

        $result = $this->service->activate(1, 'user1');

        $this->assertTrue($result->getIsActive());
    }

    public function testActivateWithSelectedDebtIdsSnapshotsSubset(): void {
        $scenario = $this->makeScenario([
            'selectedDebtIds' => json_encode([10, 20]),
        ]);

        $this->mapper->method('deactivateAll');
        $this->mapper->method('find')->with(1, 'user1')->willReturn($scenario);

        $debt1 = $this->makeAccount(10, -4000.0);
        $debt2 = $this->makeAccount(20, -1000.0);
        $debt3 = $this->makeAccount(30, -9000.0);

        $this->payoffService->method('getDebts')
            ->with('user1')
            ->willReturn([$debt1, $debt2, $debt3]);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnCallback(function (DebtScenario $s) {
                // Only debts 10 and 20 should be included (4000 + 1000)
                $this->assertEquals(5000.0, $s->getOriginalTotalDebt());
                return $s;
            });

        $this->service->activate(1, 'user1');
    }

    public function testActivateThrowsWhenNotFound(): void {
        $this->mapper->method('deactivateAll');
        $this->mapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);

        $this->service->activate(999, 'user1');
    }

    // ── calculate ───────────────────────────────────────────────────

    public function testCalculateDelegatesToPayoffService(): void {
        $scenario = $this->makeScenario([
            'strategy' => 'snowball',
            'extraPayment' => 150.0,
            'lumpSum' => 500.0,
            'lumpSumMonth' => 3,
        ]);
        $this->mapper->method('find')->with(1, 'user1')->willReturn($scenario);

        $expectedPlan = ['months' => 18, 'totalInterest' => 1200.0];
        $this->payoffService->expects($this->once())
            ->method('calculatePayoffPlan')
            ->with('user1', 'snowball', 150.0, null, 500.0, 3, null)
            ->willReturn($expectedPlan);

        $result = $this->service->calculate(1, 'user1');

        $this->assertSame($expectedPlan, $result);
    }

    public function testCalculateWithSelectedDebtIdsAndRateOverrides(): void {
        $scenario = $this->makeScenario([
            'strategy' => 'avalanche',
            'extraPayment' => 0.0,
            'lumpSum' => 0.0,
            'lumpSumMonth' => 1,
            'selectedDebtIds' => json_encode([5, 10]),
            'rateOverrides' => json_encode(['5' => 3.5]),
        ]);
        $this->mapper->method('find')->with(2, 'user1')->willReturn($scenario);

        $this->payoffService->expects($this->once())
            ->method('calculatePayoffPlan')
            ->with('user1', 'avalanche', null, [5, 10], 0.0, 1, ['5' => 3.5])
            ->willReturn(['months' => 30]);

        $result = $this->service->calculate(2, 'user1');

        $this->assertEquals(30, $result['months']);
    }

    public function testCalculateThrowsWhenNotFound(): void {
        $this->mapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);

        $this->service->calculate(999, 'user1');
    }

    // ── compareScenarios ────────────────────────────────────────────

    public function testCompareScenariosReturnsPlansForEach(): void {
        $scenario1 = $this->makeScenario(['id' => 1, 'strategy' => 'avalanche']);
        $scenario2 = $this->makeScenario(['id' => 2, 'strategy' => 'snowball']);

        $this->mapper->method('find')
            ->willReturnMap([
                [1, 'user1', $scenario1],
                [2, 'user1', $scenario2],
            ]);

        $this->payoffService->method('calculatePayoffPlan')
            ->willReturnOnConsecutiveCalls(
                ['months' => 20],
                ['months' => 24]
            );

        $results = $this->service->compareScenarios('user1', [1, 2]);

        $this->assertCount(2, $results);
        $this->assertSame($scenario1, $results[0]['scenario']);
        $this->assertEquals(20, $results[0]['plan']['months']);
        $this->assertSame($scenario2, $results[1]['scenario']);
        $this->assertEquals(24, $results[1]['plan']['months']);
    }

    public function testCompareScenariosSkipsMissingScenarios(): void {
        $scenario1 = $this->makeScenario(['id' => 1]);

        $this->mapper->method('find')
            ->willReturnCallback(function (int $id, string $userId) use ($scenario1) {
                if ($id === 1) {
                    return $scenario1;
                }
                throw new DoesNotExistException('');
            });

        $this->payoffService->method('calculatePayoffPlan')
            ->willReturn(['months' => 20]);

        $this->logger->expects($this->once())
            ->method('warning')
            ->with('Scenario not found during comparison', $this->anything());

        $results = $this->service->compareScenarios('user1', [1, 999]);

        $this->assertCount(1, $results);
        $this->assertSame($scenario1, $results[0]['scenario']);
    }

    public function testCompareScenariosEmptyArrayReturnsEmpty(): void {
        $results = $this->service->compareScenarios('user1', []);

        $this->assertEmpty($results);
    }
}
