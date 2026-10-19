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
}
