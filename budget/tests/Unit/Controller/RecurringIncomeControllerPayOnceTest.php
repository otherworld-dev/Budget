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
