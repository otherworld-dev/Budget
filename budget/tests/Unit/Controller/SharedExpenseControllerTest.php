<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\SharedExpenseController;
use OCA\Budget\Db\Contact;
use OCA\Budget\Db\ExpenseShare;
use OCA\Budget\Db\Settlement;
use OCA\Budget\Service\SharedExpenseService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SharedExpenseControllerTest extends TestCase {
	private SharedExpenseController $controller;
	private SharedExpenseService $service;
	private IRequest $request;
	private LoggerInterface $logger;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(SharedExpenseService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new SharedExpenseController(
			$this->request,
			$this->service,
			'user1',
			$this->logger
		);
	}

	private function makeContact(): Contact {
		$c = new Contact();
		$c->setId(1);
		$c->setUserId('user1');
		$c->setName('Alice');
		return $c;
	}

	private function makeShare(): ExpenseShare {
		$s = new ExpenseShare();
		$s->setId(1);
		$s->setUserId('user1');
		$s->setTransactionId(10);
		$s->setContactId(1);
		$s->setAmount(50.00);
		return $s;
	}

	private function makeSettlement(): Settlement {
		$s = new Settlement();
		$s->setId(1);
		$s->setUserId('user1');
		$s->setContactId(1);
		$s->setAmount(50.00);
		$s->setDate('2026-03-01');
		return $s;
	}

	// ── contacts ────────────────────────────────────────────────────

	public function testContactsReturnsContacts(): void {
		$this->service->method('getContacts')->with('user1')->willReturn([$this->makeContact()]);

		$response = $this->controller->contacts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testContactsHandlesError(): void {
		$this->service->method('getContacts')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->contacts();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	// ── createContact ───────────────────────────────────────────────

	public function testCreateContactReturnsCreated(): void {
		$this->service->method('createContact')->willReturn($this->makeContact());

		$response = $this->controller->createContact('Alice');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateContactHandlesError(): void {
		$this->service->method('createContact')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->createContact('Alice');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
	}

	// ── updateContact ───────────────────────────────────────────────

	public function testUpdateContactReturnsUpdated(): void {
		$this->service->method('updateContact')->willReturn($this->makeContact());

		$response = $this->controller->updateContact(1, 'Alice Updated');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── destroyContact ──────────────────────────────────────────────

	public function testDestroyContactReturnsDeleted(): void {
		$this->service->expects($this->once())->method('deleteContact')->with(1, 'user1');

		$response = $this->controller->destroyContact(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('deleted', $response->getData()['status']);
	}

	// ── contactDetails ──────────────────────────────────────────────

	public function testContactDetailsReturnsData(): void {
		$details = ['contact' => ['id' => 1], 'shares' => [], 'balance' => 0];
		$this->service->method('getContactDetails')->willReturn($details);

		$response = $this->controller->contactDetails(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── balances ────────────────────────────────────────────────────

	public function testBalancesReturnsSummary(): void {
		$summary = ['contacts' => [], 'totalOwed' => 0, 'totalOwing' => 0];
		$this->service->method('getBalanceSummary')->willReturn($summary);

		$response = $this->controller->balances();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── shareExpense ────────────────────────────────────────────────

	public function testShareExpenseReturnsCreated(): void {
		$this->service->method('shareExpense')->willReturn($this->makeShare());

		$response = $this->controller->shareExpense(10, 1, 50.00);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	// ── splitFiftyFifty ─────────────────────────────────────────────

	public function testSplitFiftyFiftyReturnsCreated(): void {
		$this->service->method('splitFiftyFifty')->willReturn($this->makeShare());

		$response = $this->controller->splitFiftyFifty(10, 1);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	// ── transactionShares ───────────────────────────────────────────

	public function testTransactionSharesReturnsShares(): void {
		$this->service->method('getSharesByTransaction')->willReturn([$this->makeShare()]);

		$response = $this->controller->transactionShares(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	// ── updateShare ─────────────────────────────────────────────────

	public function testUpdateShareReturnsUpdated(): void {
		$this->service->method('updateExpenseShare')->willReturn($this->makeShare());

		$response = $this->controller->updateShare(1, 75.00);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── markSettled ─────────────────────────────────────────────────

	public function testMarkSettledReturnsUpdated(): void {
		$this->service->method('markShareSettled')->willReturn($this->makeShare());

		$response = $this->controller->markSettled(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	// ── destroyShare ────────────────────────────────────────────────

	public function testDestroyShareReturnsDeleted(): void {
		$this->service->expects($this->once())->method('deleteExpenseShare')->with(1, 'user1');

		$response = $this->controller->destroyShare(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('deleted', $response->getData()['status']);
	}

	// ── recordSettlement ────────────────────────────────────────────

	public function testRecordSettlementReturnsCreated(): void {
		$this->service->method('recordSettlement')->willReturn($this->makeSettlement());

		$response = $this->controller->recordSettlement(1, 50.00, '2026-03-01');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	// ── settleWithContact ───────────────────────────────────────────

	public function testSettleWithContactReturnsCreated(): void {
		$this->service->method('settleWithContact')->willReturn($this->makeSettlement());

		$response = $this->controller->settleWithContact(1, '2026-03-01');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	// ── settlements ─────────────────────────────────────────────────

	public function testSettlementsReturnsData(): void {
		$this->service->method('getSettlements')->willReturn([$this->makeSettlement()]);

		$response = $this->controller->settlements();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	// ── destroySettlement ───────────────────────────────────────────

	public function testDestroySettlementReturnsDeleted(): void {
		$this->service->expects($this->once())->method('deleteSettlement')->with(1, 'user1');

		$response = $this->controller->destroySettlement(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('deleted', $response->getData()['status']);
	}
}
