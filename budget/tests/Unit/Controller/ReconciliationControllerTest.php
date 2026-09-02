<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\ReconciliationController;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ReconciliationConflictException;
use OCA\Budget\Service\ReconciliationService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Constructing the controller is itself the key regression: the constructor
 * called setInputValidator() without using InputValidationTrait, so every
 * reconciliation endpoint 500'd before this was caught (#283). No test had
 * ever instantiated the controller.
 */
class ReconciliationControllerTest extends TestCase {
    private ReconciliationController $controller;
    private ReconciliationService $service;
    private GranularShareService $granularShareService;

    private const USER = 'alice';

    protected function setUp(): void {
        $this->service = $this->createMock(ReconciliationService::class);
        $this->granularShareService = $this->createMock(GranularShareService::class);
        $l = $this->createMock(IL10N::class);
        $l->method('t')->willReturnArgument(0);

        $this->controller = new ReconciliationController(
            $this->createMock(IRequest::class),
            $this->service,
            $this->createMock(ValidationService::class),
            $this->granularShareService,
            $l,
            self::USER,
            $this->createMock(LoggerInterface::class)
        );
    }

    public function testConstructs(): void {
        // If the trait wiring is wrong the constructor throws (the #283 bug)
        $this->assertInstanceOf(ReconciliationController::class, $this->controller);
    }

    public function testHistoryReturnsServiceData(): void {
        $rows = [['id' => 1, 'statementBalance' => 100.0]];
        $this->service->expects($this->once())
            ->method('getHistory')
            ->with(7, self::USER, 20, 0)
            ->willReturn($rows);

        $response = $this->controller->history(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($rows, $response->getData());
    }

    public function testGetSessionReturnsNullSessionWhenNone(): void {
        $this->service->method('getActiveSession')->with(7, self::USER)->willReturn(null);

        $response = $this->controller->getSession(7);

        $this->assertSame(['session' => null], $response->getData());
    }

    public function testStartReturns409OnConflict(): void {
        $existing = ['session' => ['id' => 3], 'difference' => 10.0];
        $this->service->method('startSession')
            ->willThrowException(new ReconciliationConflictException($existing));

        $response = $this->controller->start(7, 100.0, '2026-06-30');

        $this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
        $this->assertSame($existing, $response->getData()['existing']);
    }

    public function testTickAllReturnsTheUpdatedSessionState(): void {
        $state = ['tickedCount' => 860, 'untickedCount' => 0, 'isBalanced' => true];
        $this->service->method('tickAllUpToStatementDate')->with(7, self::USER)->willReturn($state);

        $response = $this->controller->tickAll(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($state, $response->getData());
    }

    public function testTickAllWithoutASessionIsAValidationError(): void {
        $this->service->method('tickAllUpToStatementDate')
            ->willThrowException(new \InvalidArgumentException('No reconciliation in progress for this account'));

        $response = $this->controller->tickAll(7);

        $this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
    }

    public function testCompleteReturnsServiceResult(): void {
        $result = ['reconciledCount' => 4, 'untickedBeforeStatementDate' => 1];
        $this->service->method('complete')->with(7, self::USER)->willReturn($result);

        $response = $this->controller->complete(7);

        $this->assertSame(Http::STATUS_OK, $response->getStatus());
        $this->assertSame($result, $response->getData());
    }
}
