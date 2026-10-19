<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\RecurringIncomeController;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\RecurringIncomeService;
use OCA\Budget\Service\ValidationService;
use OCP\AppFramework\Http;
use OCP\IL10N;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The income endpoints that settle a payment take it once (#399): Skip
 * names the payment the page showed, and Mark Received asks before booking
 * a second credit for one the ledger already has.
 */
class RecurringIncomeControllerPayOnceTest extends TestCase {
	private RecurringIncomeService $service;
	private IRequest $request;
	/** @var array<string, mixed> the request's parameters */
	private array $params = [];

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->request->method('getParams')->willReturnCallback(fn () => $this->params);
		$this->service = $this->createMock(RecurringIncomeService::class);
	}

	private function controller(): RecurringIncomeController {
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(fn (string $text, array $parameters = []) => vsprintf($text, $parameters));
		$shares = $this->createMock(GranularShareService::class);
		$shares->method('canAccess')->willReturn(true);
		$shares->method('resolveOwner')->willReturn('user1');
		return new RecurringIncomeController(
			$this->request,
			$this->service,
			new ValidationService($l),
			$shares,
			$l,
			'user1',
			$this->createMock(LoggerInterface::class),
		);
	}

	// ── Mark Received ───────────────────────────────────────────────

	private const SBB_CREDIT = ['id' => 1350741, 'date' => '2026-09-29', 'amount' => 100.0];

	public function testACreditTheLedgerAlreadyHasIsReportedNotBooked(): void {
		// The reporter's SBB Abo: one click booked another 100
		$this->params = ['createTransaction' => true, 'expectedDate' => '2026-09-29'];
		$this->service->method('recordedReceiptForPendingOccurrence')
			->with(1, 'user1', '2026-09-29', 'user1')->willReturn(self::SBB_CREDIT);
		$this->service->expects($this->never())->method('markReceived');

		$response = $this->controller()->markReceived(1, '2026-10-19');

		$this->assertSame(Http::STATUS_CONFLICT, $response->getStatus());
		$this->assertSame('receipt_recorded', $response->getData()['code']);
		$this->assertSame(self::SBB_CREDIT, $response->getData()['existing']);
		$this->assertSame('This income already has a payment on 2026-09-29.', $response->getData()['error']);
	}

	public function testASecondCreditIsBookedWhenConfirmed(): void {
		$this->params = ['createTransaction' => true, 'expectedDate' => '2026-09-29', 'confirmSecondPayment' => true];
		$this->service->expects($this->never())->method('recordedReceiptForPendingOccurrence');
		$this->service->expects($this->once())->method('markReceived')
			->with(1, 'user1', '2026-10-19', true, '2026-09-29', 'user1')
			->willReturn(new RecurringIncome());

		$this->assertSame(Http::STATUS_OK, $this->controller()->markReceived(1, '2026-10-19')->getStatus());
	}

	public function testUsingTheCreditAlreadyThereAsksNothing(): void {
		$this->params = ['createTransaction' => false, 'expectedDate' => '2026-09-29'];
		$this->service->expects($this->never())->method('recordedReceiptForPendingOccurrence');
		$this->service->expects($this->once())->method('markReceived')
			->with(1, 'user1', '2026-09-29', false, '2026-09-29')
			->willReturn(new RecurringIncome());

		$this->assertSame(Http::STATUS_OK, $this->controller()->markReceived(1, '2026-09-29')->getStatus());
	}

	// ── Skip ────────────────────────────────────────────────────────

	public function testSkipNamesThePaymentThePageShowed(): void {
		$this->params = ['expectedDate' => '2026-09-29'];
		$this->service->expects($this->once())->method('skipPayment')
			->with(1, 'user1', '2026-09-29')
			->willReturn(['income' => new RecurringIncome(), 'previousNextExpectedDate' => '2026-09-29']);

		$this->assertSame(Http::STATUS_OK, $this->controller()->skipPayment(1)->getStatus());
	}

	public function testASkipFromAnOlderPageStillSkips(): void {
		$this->service->expects($this->once())->method('skipPayment')
			->with(1, 'user1', null)
			->willReturn(['income' => new RecurringIncome(), 'previousNextExpectedDate' => '2026-09-29']);

		$this->assertSame(Http::STATUS_OK, $this->controller()->skipPayment(1)->getStatus());
	}
}
