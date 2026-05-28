<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\DebtScenarioController;
use OCA\Budget\Db\DebtScenario;
use OCA\Budget\Service\DebtScenarioService;
use OCA\Budget\Service\GranularShareService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class DebtScenarioControllerTest extends TestCase {
    private DebtScenarioController $controller;
    private DebtScenarioService $service;

    protected function setUp(): void {
        $request = $this->createMock(IRequest::class);
        $this->service = $this->createMock(DebtScenarioService::class);
        $logger = $this->createMock(LoggerInterface::class);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnCallback(function ($text, $parameters = []) {
            return vsprintf($text, $parameters);
        });

        $granularShareService = $this->createMock(GranularShareService::class);
        $granularShareService->method('canAccess')->willReturn(true);

        $this->controller = new DebtScenarioController(
            $request,
            $this->service,
            $granularShareService,
            $l,
            'user1',
            $logger
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
        $scenario->setIsActive($data['isActive']);
        $scenario->setOriginalTotalDebt($data['originalTotalDebt']);
        return $scenario;
    }

    // ── index ───────────────────────────────────────────────────────

    public function testIndexReturnsScenarios(): void {
        $scenarios = [
            $this->makeScenario(['id' => 1]),
            $this->makeScenario(['id' => 2, 'name' => 'Second']),
        ];
        $this->service->method('findAll')->with('user1')->willReturn($scenarios);

        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(2, $response->getData());
    }

    public function testIndexHandlesException(): void {
        $this->service->method('findAll')
            ->willThrowException(new \RuntimeException('DB error'));

        $response = $this->controller->index();

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('Failed to retrieve debt scenarios', $response->getData()['error']);
    }

    // ── create ──────────────────────────────────────────────────────

    public function testCreateWithValidDataReturns201(): void {
        $scenario = $this->makeScenario();
        $this->service->expects($this->once())
            ->method('create')
            ->with('user1', $this->callback(function ($params) {
                return $params['name'] === 'My Plan'
                    && $params['strategy'] === 'snowball'
                    && $params['extraPayment'] === 200.0;
            }))
            ->willReturn($scenario);

        $response = $this->controller->create('My Plan', 'snowball', 200.0);

        $this->assertSame(Http::STATUS_CREATED, $response->getStatus());
    }

    public function testCreateWithEmptyNameReturns400(): void {
        $response = $this->controller->create('');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Scenario name is required', $response->getData()['error']);
    }

    public function testCreateWithInvalidStrategyReturns400(): void {
        $response = $this->controller->create('Valid Name', 'invalid_strategy');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Invalid strategy', $response->getData()['error']);
    }

    public function testCreateWithNegativeExtraPaymentReturns400(): void {
        $response = $this->controller->create('Valid Name', 'avalanche', -50.0);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Payment amounts cannot be negative', $response->getData()['error']);
    }

    public function testCreateWithNegativeLumpSumReturns400(): void {
        $response = $this->controller->create('Valid Name', 'avalanche', 0, -100.0);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Payment amounts cannot be negative', $response->getData()['error']);
    }

    public function testCreateWithLumpSumMonthBelowOneReturns400(): void {
        $response = $this->controller->create('Valid Name', 'avalanche', 0, 0, 0);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Lump sum month must be at least 1', $response->getData()['error']);
    }

    public function testCreateHandlesServiceException(): void {
        $this->service->method('create')
            ->willThrowException(new \RuntimeException('DB error'));

        $response = $this->controller->create('Valid Name');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('Failed to create debt scenario', $response->getData()['error']);
    }

    // ── update ──────────────────────────────────────────────────────

    public function testUpdateWithValidData(): void {
        $scenario = $this->makeScenario(['name' => 'Updated']);
        $this->service->expects($this->once())
            ->method('update')
            ->with(1, 'user1', $this->callback(function ($params) {
                return $params['name'] === 'Updated' && $params['strategy'] === 'snowball';
            }))
            ->willReturn($scenario);

        $response = $this->controller->update(1, 'Updated', 'snowball');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testUpdateNotFoundReturns404(): void {
        $this->service->method('update')
            ->willThrowException(new DoesNotExistException(''));

        $response = $this->controller->update(999, 'New Name');

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame('Scenario not found', $response->getData()['error']);
    }

    public function testUpdateWithEmptyNameReturns400(): void {
        $response = $this->controller->update(1, '');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Scenario name is required', $response->getData()['error']);
    }

    public function testUpdateWithInvalidStrategyReturns400(): void {
        $response = $this->controller->update(1, null, 'bad');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Invalid strategy', $response->getData()['error']);
    }

    public function testUpdateWithNegativeExtraPaymentReturns400(): void {
        $response = $this->controller->update(1, null, null, -10.0);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Payment amounts cannot be negative', $response->getData()['error']);
    }

    public function testUpdateWithNegativeLumpSumReturns400(): void {
        $response = $this->controller->update(1, null, null, null, -5.0);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Payment amounts cannot be negative', $response->getData()['error']);
    }

    public function testUpdateWithLumpSumMonthBelowOneReturns400(): void {
        $response = $this->controller->update(1, null, null, null, null, 0);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('Lump sum month must be at least 1', $response->getData()['error']);
    }

    public function testUpdateOnlyIncludesProvidedParams(): void {
        $scenario = $this->makeScenario();
        $this->service->expects($this->once())
            ->method('update')
            ->with(1, 'user1', $this->callback(function ($params) {
                // Only 'name' should be in the params, not strategy/extraPayment/etc.
                return array_keys($params) === ['name'];
            }))
            ->willReturn($scenario);

        $response = $this->controller->update(1, 'Just Name');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    // ── destroy ─────────────────────────────────────────────────────

    public function testDestroySuccess(): void {
        $this->service->expects($this->once())
            ->method('delete')
            ->with(1, 'user1');

        $response = $this->controller->destroy(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testDestroyNotFoundReturns404(): void {
        $this->service->method('delete')
            ->willThrowException(new DoesNotExistException(''));

        $response = $this->controller->destroy(999);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame('Scenario not found', $response->getData()['error']);
    }

    public function testDestroyHandlesGenericException(): void {
        $this->service->method('delete')
            ->willThrowException(new \RuntimeException('DB error'));

        $response = $this->controller->destroy(1);

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('Failed to delete debt scenario', $response->getData()['error']);
    }

    // ── activate ────────────────────────────────────────────────────

    public function testActivateSuccess(): void {
        $scenario = $this->makeScenario(['isActive' => true]);
        $this->service->expects($this->once())
            ->method('activate')
            ->with(1, 'user1')
            ->willReturn($scenario);

        $response = $this->controller->activate(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
    }

    public function testActivateNotFoundReturns404(): void {
        $this->service->method('activate')
            ->willThrowException(new DoesNotExistException(''));

        $response = $this->controller->activate(999);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame('Scenario not found', $response->getData()['error']);
    }

    public function testActivateHandlesGenericException(): void {
        $this->service->method('activate')
            ->willThrowException(new \RuntimeException('DB error'));

        $response = $this->controller->activate(1);

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('Failed to activate debt scenario', $response->getData()['error']);
    }

    // ── calculate ───────────────────────────────────────────────────

    public function testCalculateSuccess(): void {
        $plan = ['months' => 24, 'totalInterest' => 1500.0];
        $this->service->expects($this->once())
            ->method('calculate')
            ->with(1, 'user1')
            ->willReturn($plan);

        $response = $this->controller->calculate(1);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($plan, $response->getData());
    }

    public function testCalculateNotFoundReturns404(): void {
        $this->service->method('calculate')
            ->willThrowException(new DoesNotExistException(''));

        $response = $this->controller->calculate(999);

        $this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
        $this->assertSame('Scenario not found', $response->getData()['error']);
    }

    public function testCalculateHandlesGenericException(): void {
        $this->service->method('calculate')
            ->willThrowException(new \RuntimeException('error'));

        $response = $this->controller->calculate(1);

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('Failed to calculate debt scenario plan', $response->getData()['error']);
    }

    // ── compare ─────────────────────────────────────────────────────

    public function testCompareWithValidIds(): void {
        $results = [
            ['scenario' => $this->makeScenario(['id' => 1]), 'plan' => ['months' => 20]],
            ['scenario' => $this->makeScenario(['id' => 2]), 'plan' => ['months' => 24]],
        ];
        $this->service->expects($this->once())
            ->method('compareScenarios')
            ->with('user1', [1, 2])
            ->willReturn($results);

        $response = $this->controller->compare('1,2');

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertCount(2, $response->getData());
    }

    public function testCompareWithEmptyIdsReturns400(): void {
        $response = $this->controller->compare('');

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
        $this->assertSame('No scenario IDs provided', $response->getData()['error']);
    }

    public function testCompareHandlesException(): void {
        $this->service->method('compareScenarios')
            ->willThrowException(new \RuntimeException('error'));

        $response = $this->controller->compare('1,2');

        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
        $this->assertSame('Failed to compare debt scenarios', $response->getData()['error']);
    }
}
