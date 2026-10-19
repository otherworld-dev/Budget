<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\BillController;
use OCA\Budget\Db\Bill;
use OCA\Budget\Service\BillService;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The bill endpoints that settle an occurrence pay it once (#399): Skip
 * names the occurrence the page showed, Mark Paid asks before booking a
 * second payment of one the ledger already has, and Undo Skip only puts
 * back a date the bill was due on.
 */
class BillControllerPayOnceTest extends TestCase {
	private BillService $service;
	private IRequest $request;
	/** @var array<string, mixed> the request's parameters */
	private array $params = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn () => $this->params);
		$this->service = $this->createMock(BillService::class);
	}

	private function controller(?ValidationService $validation = null): BillController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []) => vsprintf($text, $parameters));
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('canAccess')->willReturn(true);
		$shares->method('resolveOwner')->willReturn('user1');
		return new BillController(
			$this->request,
			$this->service,
			$validation ?? new ValidationService($l),
			$shares,
			$this->createMock(\OCA\Budget\Service\Bill\BillSuggestionService::class),
			$this->createMock(\OCA\Budget\Service\UpcomingBillsService::class),
			$l,
			'user1',
			$this->createMock(LoggerInterface::class),
		);
	}

	// ── Mark Paid ───────────────────────────────────────────────────

	private const CEMBRAPAY = ['id' => 1350727, 'date' => '2026-09-27', 'amount' => 151.67];

	public function testAPaymentTheLedgerAlreadyHasIsReportedNotBooked(): void {
		// The re-opened CembraPay bill: one click paid it a second time
		$this->params = ['recordPayment' => true, 'dueDate' => '2026-09-28'];
		$this->service->method('recordedPaymentForPendingOccurrence')->with(1, 'user1', '2026-09-28')->willReturn(self::CEMBRAPAY);
		$this->service->expects($this->never())->method('markPaid');

		$response = $this->controller()->markPaid(1);

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('payment_recorded', $response->getData()['code']);
		$this->assertSame(self::CEMBRAPAY, $response->getData()['existing']);
		$this->assertSame('This bill already has a payment on 2026-09-27.', $response->getData()['error']);
	}

	public function testASecondPaymentIsBookedWhenConfirmed(): void {
		$this->params = ['recordPayment' => true, 'dueDate' => '2026-09-28', 'confirmSecondPayment' => true];
		$this->service->expects($this->never())->method('recordedPaymentForPendingOccurrence');
		$this->service->expects($this->once())->method('markPaid')
			->with(1, 'user1', null, true, null, '2026-09-28')
			->willReturn(['bill' => new Bill()]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->markPaid(1)->getStatus());
	}

	public function testAConfirmationSentAsTextIsReadAsSuch(): void {
		// filter_var, not a cast: (bool)"false" is true
		$this->params = ['recordPayment' => 'true', 'dueDate' => '2026-09-28', 'confirmSecondPayment' => 'false'];
		$this->service->method('recordedPaymentForPendingOccurrence')->willReturn(self::CEMBRAPAY);
		$this->service->expects($this->never())->method('markPaid');

		$this->assertSame(Http::STATUS_CONFLICT, $this->controller()->markPaid(1)->getStatus());
	}

	public function testUsingThePaymentAlreadyThereAsksNothing(): void {
		// "Use that payment": marked paid on its date, nothing booked
		$this->params = ['recordPayment' => false, 'dueDate' => '2026-09-28'];
		$this->service->expects($this->never())->method('recordedPaymentForPendingOccurrence');
		$this->service->expects($this->once())->method('markPaid')
			->with(1, 'user1', '2026-09-27', false, null, '2026-09-28', 'user1')
			->willReturn(['bill' => new Bill()]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->markPaid(1, '2026-09-27')->getStatus());
	}

	public function testLinkingARowAsksNothing(): void {
		$this->params = ['recordPayment' => false, 'existingTransactionId' => 77, 'dueDate' => '2026-09-28'];
		$this->service->expects($this->never())->method('recordedPaymentForPendingOccurrence');
		$this->service->expects($this->once())->method('markPaid')->willReturn(['bill' => new Bill()]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->markPaid(1)->getStatus());
	}

	public function testABillWithNoPaymentInTheLedgerIsPaid(): void {
		$this->params = ['recordPayment' => true, 'dueDate' => '2026-10-28'];
		$this->service->method('recordedPaymentForPendingOccurrence')->willReturn(null);
		$this->service->expects($this->once())->method('markPaid')->willReturn(['bill' => new Bill()]);

		$this->assertSame(Http::STATUS_OK, $this->controller()->markPaid(1)->getStatus());
	}

	// ── Skip ────────────────────────────────────────────────────────

	public function testSkipNamesTheOccurrenceThePageShowed(): void {
		// A second click or a stale tab is refused instead of skipping the
		// next occurrence too
		$this->params = ['dueDate' => '2026-10-15'];
		$this->service->expects($this->once())->method('skipPayment')
			->with(1, 'user1', '2026-10-15')
			->willReturn(['bill' => new Bill(), 'previousNextDueDate' => '2026-10-15']);

		$this->assertSame(Http::STATUS_OK, $this->controller()->skipPayment(1)->getStatus());
	}

	public function testASkipFromAnOlderPageStillSkips(): void {
		$this->service->expects($this->once())->method('skipPayment')
			->with(1, 'user1', null)
			->willReturn(['bill' => new Bill(), 'previousNextDueDate' => '2026-10-15']);

		$this->assertSame(Http::STATUS_OK, $this->controller()->skipPayment(1)->getStatus());
	}

	public function testARefusedSkipSaysWhy(): void {
		$this->params = ['dueDate' => '2026-10-15'];
		$this->service->method('skipPayment')
			->willThrowException(new \InvalidArgumentException('This payment was already recorded. Reload the page to see the next one.'));

		$response = $this->controller()->skipPayment(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('This payment was already recorded. Reload the page to see the next one.', $response->getData()['error']);
	}

	// ── Undo skip ───────────────────────────────────────────────────

	public function testUndoSkipRefusesSomethingThatIsNotADate(): void {
		// It was stored as the due date as it came, and the annual overview
		// then failed for the whole user
		$this->params = ['previousNextDueDate' => 'not-a-date'];
		$this->service->expects($this->never())->method('undoSkip');

		$response = $this->controller()->undoSkip(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Previous due date must be in YYYY-MM-DD format', $response->getData()['error']);
	}

	public function testUndoSkipStillNeedsTheDate(): void {
		$this->service->expects($this->never())->method('undoSkip');

		$response = $this->controller()->undoSkip(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Missing previous due date', $response->getData()['error']);
	}

	public function testTheUndoSkipTheReporterWasGivenStillWorks(): void {
		// CSX Pro, moved back from 28 November to 28 October (#399)
		$this->params = ['previousNextDueDate' => '2026-10-28'];
		$this->service->expects($this->once())->method('undoSkip')
			->with(1, 'user1', '2026-10-28')
			->willReturn(new Bill());

		$this->assertSame(Http::STATUS_OK, $this->controller()->undoSkip(1)->getStatus());
	}

	public function testUndoSkipSaysWhyTheBillRefusedTheDate(): void {
		$this->params = ['previousNextDueDate' => '2026-10-15'];
		$this->service->method('undoSkip')
			->willThrowException(new \InvalidArgumentException("That date isn't one of this bill's due dates"));

		$response = $this->controller()->undoSkip(1);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame("That date isn't one of this bill's due dates", $response->getData()['error']);
	}
}
