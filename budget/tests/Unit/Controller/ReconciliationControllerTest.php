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
		// Account 7 is alice's own
		$this->granularShareService->method('resolveOwner')
			->willReturnCallback(fn (string $user, string $type, int $id) => $id === 7 ? self::USER : null);
		$this->granularShareService->method('canWrite')->willReturn(true);
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

	// ── an account shared with the user (V4-2) ──────────────────────

	/**
	 * Account 9 is owen's, shared with alice; at write unless $readOnly.
	 * Account 12 is someone's she can't see.
	 */
	private function controllerOnASharedAccount(bool $readOnly = false): ReconciliationController {
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('resolveOwner')->willReturnCallback(
			fn (string $user, string $type, int $id) => $id === 9 ? 'owen' : null
		);
		$shares->method('canWrite')->willReturn(!$readOnly);
		$shares->method('requireWriteAccess')->willReturnCallback(function () use ($readOnly): void {
			if ($readOnly) {
				throw new \OCA\Budget\Exception\ReadOnlyShareException();
			}
		});
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $p = []) => vsprintf($text, $p));
		return new ReconciliationController($this->createMock(IRequest::class), $this->service,
			$this->createMock(ValidationService::class), $shares, $l, self::USER, $this->createMock(LoggerInterface::class));
	}

	public function testAWriteRecipientReconcilesAsTheAccountOwner(): void {
		// The service scopes the account and its sessions to the owner;
		// as alice it found neither and the toast showed the SQL
		$this->service->expects($this->once())->method('startSession')
			->with(9, 'owen', 100.0, '2026-06-30')->willReturn(['session' => ['id' => 1]]);

		$response = $this->controllerOnASharedAccount()->start(9, 100.0, '2026-06-30');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testEverySessionActionRunsAsTheOwner(): void {
		$controller = $this->controllerOnASharedAccount();
		$this->service->expects($this->once())->method('getActiveSession')->with(9, 'owen')->willReturn(null);
		$this->service->expects($this->once())->method('updateSession')->with(9, 'owen', 5.0, null)->willReturn([]);
		$this->service->expects($this->once())->method('tick')->with(9, 'owen', [1, 2], true)->willReturn([]);
		$this->service->expects($this->once())->method('tickAllUpToStatementDate')->with(9, 'owen')->willReturn([]);
		$this->service->expects($this->once())->method('complete')->with(9, 'owen')->willReturn([]);
		$this->service->expects($this->once())->method('cancel')->with(9, 'owen');
		$this->service->expects($this->once())->method('getHistory')->with(9, 'owen', 20, 0)->willReturn([]);

		foreach ([
			$controller->getSession(9),
			$controller->update(9, 5.0),
			$controller->tick(9, [1, 2]),
			$controller->tickAll(9),
			$controller->complete(9),
			$controller->cancel(9),
			$controller->history(9),
		] as $response) {
			$this->assertSame(Http::STATUS_OK, $response->getStatus());
		}
	}

	public function testAReadOnlyRecipientCannotReconcile(): void {
		$this->service->expects($this->never())->method('startSession');

		$response = $this->controllerOnASharedAccount(true)->start(9, 100.0, '2026-06-30');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testAReadOnlyRecipientCanReadTheHistory(): void {
		$this->service->expects($this->once())->method('getHistory')->with(9, 'owen', 20, 0)->willReturn([]);

		$response = $this->controllerOnASharedAccount(true)->history(9);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testAnAccountTheUserCannotSeeIsNotFound(): void {
		$this->service->expects($this->never())->method('startSession');
		$this->service->expects($this->never())->method('getHistory');
		$controller = $this->controllerOnASharedAccount();

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->start(12, 100.0, '2026-06-30')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->history(12)->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->tick(12, [1])->getStatus());
	}

	public function testAFailedLookupNeverShowsItsQuery(): void {
		$this->service->method('startSession')->willThrowException(new \OCP\AppFramework\Db\DoesNotExistException(
			'Did expect one result but found none when executing: query "SELECT * FROM `*PREFIX*budget_accounts`"'
		));

		$response = $this->controllerOnASharedAccount()->start(9, 100.0, '2026-06-30');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertStringNotContainsString('SELECT', json_encode($response->getData()));
	}

	public function testAnUnexpectedErrorNeverShowsItsMessage(): void {
		$this->service->method('complete')->willThrowException(new \RuntimeException('SQLSTATE[HY000]: something internal'));

		$response = $this->controllerOnASharedAccount()->complete(9);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertStringNotContainsString('SQLSTATE', json_encode($response->getData()));
	}

	public function testARefusalStillSaysWhy(): void {
		$this->service->method('complete')
			->willThrowException(new \InvalidArgumentException('The difference must be zero before finishing'));

		$response = $this->controllerOnASharedAccount()->complete(9);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('The difference must be zero before finishing', $response->getData()['error']);
	}
}
