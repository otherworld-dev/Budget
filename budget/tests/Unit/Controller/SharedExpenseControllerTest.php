<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Controller;

use OCA\Budget\Controller\SharedExpenseController;
use OCA\Budget\Db\Contact;
use OCA\Budget\Db\ExpenseShare;
use OCA\Budget\Db\Settlement;
use OCA\Budget\Service\GranularShareService;
use OCA\Budget\Service\SharedExpenseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\Collaboration\Collaborators\ISearch;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IUserManager;
use OCP\Share\IShare;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SharedExpenseControllerTest extends TestCase {
	private SharedExpenseController $controller;
	private SharedExpenseService $service;
	private IRequest $request;
	private LoggerInterface $logger;
	private ISearch $collaboratorSearch;

	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->service = $this->createMock(SharedExpenseService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$l = $this->createMock(IL10N::class);
		$l->method('t')->willReturnCallback(function ($text, $parameters = []) {
			return vsprintf($text, $parameters);
		});

		$granularShareService = $this->createMock(GranularShareService::class);
		$granularShareService->method('canAccess')->willReturn(true);
		$userManager = $this->createMock(IUserManager::class);
		$this->collaboratorSearch = $this->createMock(ISearch::class);

		$this->controller = new SharedExpenseController(
			$this->request,
			$this->service,
			$granularShareService,
			$userManager,
			$l,
			'user1',
			$this->logger,
			$this->collaboratorSearch
		);
	}

	// ── searchUsers ─────────────────────────────────────────────────

	private static function sharee(string $uid, string $label): array {
		return ['label' => $label, 'value' => ['shareType' => IShare::TYPE_USER, 'shareWith' => $uid]];
	}

	/**
	 * The search goes through Nextcloud's sharee search, which applies the
	 * admin's enumeration and group settings; '*' asks it for whoever those
	 * settings allow (an empty search), never a raw user-manager listing.
	 */
	public function testSearchUsersUsesTheShareeSearchForAWildcard(): void {
		$this->collaboratorSearch->expects($this->once())->method('search')
			->with('', [IShare::TYPE_USER], false, 50, 0)
			->willReturn([['exact' => ['users' => []], 'users' => [self::sharee('bob', 'Bob')]], false]);

		$response = $this->controller->searchUsers('*');

		$this->assertSame([['uid' => 'bob', 'displayName' => 'Bob']], $response->getData());
	}

	public function testSearchUsersMergesExactMatchesFirstAndDropsDuplicatesAndSelf(): void {
		$this->collaboratorSearch->method('search')->willReturn([[
			'exact' => ['users' => [self::sharee('alice', 'Alice')]],
			'users' => [self::sharee('alice', 'Alice'), self::sharee('user1', 'Me'), self::sharee('alicia', 'Alicia')],
		], false]);

		$response = $this->controller->searchUsers('ali');

		$this->assertSame([
			['uid' => 'alice', 'displayName' => 'Alice'],
			['uid' => 'alicia', 'displayName' => 'Alicia'],
		], $response->getData());
	}

	/** With enumeration disabled core returns nothing for a partial query, and so do we. */
	public function testSearchUsersReturnsWhatTheShareeSearchAllowsAndNothingMore(): void {
		$this->collaboratorSearch->method('search')->willReturn([['exact' => ['users' => []], 'users' => []], false]);

		$this->assertSame([], $this->controller->searchUsers('*')->getData());
	}

	public function testSearchUsersNeedsTwoCharactersUnlessWildcard(): void {
		$this->collaboratorSearch->expects($this->never())->method('search');

		$this->assertSame([], $this->controller->searchUsers('a')->getData());
	}

	private function makeContact(int $id = 1, string $name = 'Alice', ?string $email = null): Contact {
		$c = new Contact();
		$c->setId($id);
		$c->setUserId('user1');
		$c->setName($name);
		$c->setEmail($email);
		return $c;
	}

	private function makeShare(int $id = 1, float $amount = 50.00): ExpenseShare {
		$s = new ExpenseShare();
		$s->setId($id);
		$s->setUserId('user1');
		$s->setTransactionId(10);
		$s->setContactId(1);
		$s->setAmount($amount);
		return $s;
	}

	private function makeSettlement(int $id = 1, float $amount = 50.00): Settlement {
		$s = new Settlement();
		$s->setId($id);
		$s->setUserId('user1');
		$s->setContactId(1);
		$s->setAmount($amount);
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

	public function testContactsReturnsEmptyArray(): void {
		$this->service->method('getContacts')->with('user1')->willReturn([]);

		$response = $this->controller->contacts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(0, $response->getData());
	}

	public function testContactsHandlesError(): void {
		$this->service->method('getContacts')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->contacts();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to get contacts', $response->getData()['error']);
	}

	// ── createContact ───────────────────────────────────────────────

	public function testCreateContactReturnsCreated(): void {
		$this->service->method('createContact')->willReturn($this->makeContact());

		$response = $this->controller->createContact('Alice');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateContactPassesCorrectArguments(): void {
		$this->service->expects($this->once())
			->method('createContact')
			->with('user1', 'Alice', null)
			->willReturn($this->makeContact());

		$this->controller->createContact('Alice');
	}

	public function testCreateContactWithEmail(): void {
		$contact = $this->makeContact(1, 'Alice', 'alice@example.com');
		$this->service->expects($this->once())
			->method('createContact')
			->with('user1', 'Alice', 'alice@example.com')
			->willReturn($contact);

		$response = $this->controller->createContact('Alice', 'alice@example.com');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testCreateContactHandlesError(): void {
		$this->service->method('createContact')->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->createContact('Alice');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to create contact', $response->getData()['error']);
	}

	// ── updateContact ───────────────────────────────────────────────

	public function testUpdateContactReturnsUpdated(): void {
		$this->service->method('updateContact')->willReturn($this->makeContact());

		$response = $this->controller->updateContact(1, 'Alice Updated');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateContactPassesCorrectArguments(): void {
		$this->service->expects($this->once())
			->method('updateContact')
			->with(1, 'user1', 'Alice Updated', null)
			->willReturn($this->makeContact(1, 'Alice Updated'));

		$this->controller->updateContact(1, 'Alice Updated');
	}

	public function testUpdateContactWithEmail(): void {
		$contact = $this->makeContact(1, 'Alice', 'new@example.com');
		$this->service->expects($this->once())
			->method('updateContact')
			->with(1, 'user1', 'Alice', 'new@example.com')
			->willReturn($contact);

		$response = $this->controller->updateContact(1, 'Alice', 'new@example.com');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateContactHandlesError(): void {
		$this->service->method('updateContact')
			->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->updateContact(1, 'Alice Updated');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to update contact', $response->getData()['error']);
	}

	// ── destroyContact ──────────────────────────────────────────────

	public function testDestroyContactReturnsDeleted(): void {
		$this->service->expects($this->once())->method('deleteContact')->with(1, 'user1');

		$response = $this->controller->destroyContact(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('deleted', $response->getData()['status']);
	}

	public function testDestroyContactHandlesError(): void {
		$this->service->method('deleteContact')
			->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->destroyContact(1);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to delete contact', $response->getData()['error']);
	}

	// ── contactDetails ──────────────────────────────────────────────

	public function testContactDetailsReturnsData(): void {
		$details = ['contact' => ['id' => 1], 'shares' => [], 'balance' => 0];
		$this->service->method('getContactDetails')->willReturn($details);

		$response = $this->controller->contactDetails(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testContactDetailsPassesCorrectArguments(): void {
		$details = ['contact' => ['id' => 5], 'shares' => [], 'balance' => 100.0];
		$this->service->expects($this->once())
			->method('getContactDetails')
			->with(5, 'user1')
			->willReturn($details);

		$response = $this->controller->contactDetails(5);

		$this->assertSame($details, $response->getData());
	}

	public function testContactDetailsHandlesError(): void {
		$this->service->method('getContactDetails')
			->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->contactDetails(1);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to get contact details', $response->getData()['error']);
	}

	// ── balances ────────────────────────────────────────────────────

	public function testBalancesReturnsSummary(): void {
		$summary = ['contacts' => [], 'totalOwed' => 0, 'totalOwing' => 0];
		$this->service->method('getBalanceSummary')->willReturn($summary);

		$response = $this->controller->balances();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testBalancesPassesUserId(): void {
		$summary = ['contacts' => [], 'totalOwed' => 100, 'totalOwing' => 50];
		$this->service->expects($this->once())
			->method('getBalanceSummary')
			->with('user1')
			->willReturn($summary);

		$response = $this->controller->balances();

		$this->assertSame($summary, $response->getData());
	}

	public function testBalancesHandlesError(): void {
		$this->service->method('getBalanceSummary')
			->willThrowException(new \RuntimeException('db error'));

		$response = $this->controller->balances();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to get balances', $response->getData()['error']);
	}

	// ── shareExpense ────────────────────────────────────────────────

	public function testShareExpenseReturnsCreated(): void {
		$this->service->method('shareExpense')->willReturn($this->makeShare());

		$response = $this->controller->shareExpense(10, 1, 50.00);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testShareExpensePassesCorrectArguments(): void {
		$this->service->expects($this->once())
			->method('shareExpense')
			->with('user1', 10, 1, 50.00, null)
			->willReturn($this->makeShare());

		$this->controller->shareExpense(10, 1, 50.00);
	}

	public function testShareExpenseWithNotes(): void {
		$this->service->expects($this->once())
			->method('shareExpense')
			->with('user1', 10, 1, 50.00, 'dinner split')
			->willReturn($this->makeShare());

		$response = $this->controller->shareExpense(10, 1, 50.00, 'dinner split');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testShareExpenseHandlesError(): void {
		$this->service->method('shareExpense')
			->willThrowException(new \RuntimeException('transaction not found'));

		$response = $this->controller->shareExpense(10, 1, 50.00);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to share expense', $response->getData()['error']);
	}

	// ── splitFiftyFifty ─────────────────────────────────────────────

	public function testSplitFiftyFiftyReturnsCreated(): void {
		$this->service->method('splitFiftyFifty')->willReturn($this->makeShare());

		$response = $this->controller->splitFiftyFifty(10, 1);

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testSplitFiftyFiftyPassesCorrectArguments(): void {
		$this->service->expects($this->once())
			->method('splitFiftyFifty')
			->with('user1', 10, 1, null)
			->willReturn($this->makeShare());

		$this->controller->splitFiftyFifty(10, 1);
	}

	public function testSplitFiftyFiftyWithNotes(): void {
		$this->service->expects($this->once())
			->method('splitFiftyFifty')
			->with('user1', 10, 1, 'coffee run')
			->willReturn($this->makeShare());

		$response = $this->controller->splitFiftyFifty(10, 1, 'coffee run');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testSplitFiftyFiftyHandlesError(): void {
		$this->service->method('splitFiftyFifty')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->splitFiftyFifty(10, 1);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to split expense', $response->getData()['error']);
	}

	// ── setTransactionShares (#391) ─────────────────────────────────

	public function testSetTransactionSharesReturnsEverySplit(): void {
		$splits = [['contactId' => 1, 'amount' => 30], ['contactId' => 2, 'amount' => 30]];
		$this->service->expects($this->once())
			->method('setTransactionShares')
			->with('user1', 10, $splits, 'Tiles', [])
			->willReturn([$this->makeShare(1, 30.0), $this->makeShare(2, 30.0)]);

		$response = $this->controller->setTransactionShares(10, $splits, 'Tiles');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(2, $response->getData());
		$this->assertSame(30.0, $response->getData()[1]['amount']);
	}

	public function testSetTransactionSharesDefaultsToNoSplits(): void {
		$this->service->expects($this->once())
			->method('setTransactionShares')
			->with('user1', 10, [], null, [])
			->willReturn([]);

		$response = $this->controller->setTransactionShares(10);

		$this->assertSame([], $response->getData());
	}

	#[DataProvider('refusals')]
	public function testSetTransactionSharesExplainsARefusal(int $code, string $message): void {
		$this->service->method('setTransactionShares')
			->willThrowException(new \InvalidArgumentException('refused', $code));

		$response = $this->controller->setTransactionShares(10, [['contactId' => 1, 'amount' => 30]]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame($message, $response->getData()['error']);
	}

	public static function refusals(): array {
		return [
			'duplicate' => [SharedExpenseService::SPLIT_ERR_DUPLICATE, 'Each person can only be in a split once'],
			'settled' => [SharedExpenseService::SPLIT_ERR_SETTLED, 'A split that has been settled cannot be changed'],
			'amount' => [SharedExpenseService::SPLIT_ERR_AMOUNT, 'Every split needs an amount'],
			'over total' => [SharedExpenseService::SPLIT_ERR_OVER_TOTAL, 'The splits add up to more than the transaction'],
		];
	}

	public function testSetTransactionSharesReportsAMissingTransactionOrContact(): void {
		$this->service->method('setTransactionShares')
			->willThrowException(new DoesNotExistException('gone'));

		$response = $this->controller->setTransactionShares(10, [['contactId' => 99, 'amount' => 30]]);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('Transaction or contact not found', $response->getData()['error']);
	}

	public function testSetTransactionSharesHandlesError(): void {
		$this->service->method('setTransactionShares')
			->willThrowException(new \RuntimeException('db down'));

		$response = $this->controller->setTransactionShares(10, [['contactId' => 1, 'amount' => 30]]);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to save the split', $response->getData()['error']);
	}

	// ── transactionShares ───────────────────────────────────────────

	public function testTransactionSharesReturnsShares(): void {
		$this->service->method('getSharesByTransaction')->willReturn([$this->makeShare()]);

		$response = $this->controller->transactionShares(10);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testTransactionSharesPassesCorrectArguments(): void {
		$this->service->expects($this->once())
			->method('getSharesByTransaction')
			->with(10, 'user1')
			->willReturn([]);

		$this->controller->transactionShares(10);
	}

	public function testTransactionSharesHandlesError(): void {
		$this->service->method('getSharesByTransaction')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->transactionShares(10);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to get shares', $response->getData()['error']);
	}

	// ── updateShare ─────────────────────────────────────────────────

	public function testUpdateShareReturnsUpdated(): void {
		$this->service->method('updateExpenseShare')->willReturn($this->makeShare());

		$response = $this->controller->updateShare(1, 75.00);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateSharePassesCorrectArguments(): void {
		$this->service->expects($this->once())
			->method('updateExpenseShare')
			->with(1, 'user1', 75.00, null)
			->willReturn($this->makeShare(1, 75.00));

		$this->controller->updateShare(1, 75.00);
	}

	public function testUpdateShareWithNotes(): void {
		$this->service->expects($this->once())
			->method('updateExpenseShare')
			->with(1, 'user1', 80.00, 'updated amount')
			->willReturn($this->makeShare(1, 80.00));

		$response = $this->controller->updateShare(1, 80.00, 'updated amount');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testUpdateShareHandlesError(): void {
		$this->service->method('updateExpenseShare')
			->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->updateShare(1, 75.00);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to update share', $response->getData()['error']);
	}

	// ── markSettled ─────────────────────────────────────────────────

	public function testMarkSettledReturnsUpdated(): void {
		$this->service->method('markShareSettled')->willReturn($this->makeShare());

		$response = $this->controller->markSettled(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
	}

	public function testMarkSettledPassesCorrectArguments(): void {
		$this->service->expects($this->once())
			->method('markShareSettled')
			->with(1, 'user1')
			->willReturn($this->makeShare());

		$this->controller->markSettled(1);
	}

	public function testMarkSettledHandlesError(): void {
		$this->service->method('markShareSettled')
			->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->markSettled(1);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to mark settled', $response->getData()['error']);
	}

	// ── destroyShare ────────────────────────────────────────────────

	public function testDestroyShareReturnsDeleted(): void {
		$this->service->expects($this->once())->method('deleteExpenseShare')->with(1, 'user1');

		$response = $this->controller->destroyShare(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('deleted', $response->getData()['status']);
	}

	public function testDestroyShareHandlesError(): void {
		$this->service->method('deleteExpenseShare')
			->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->destroyShare(1);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to delete share', $response->getData()['error']);
	}

	// ── recordSettlement ────────────────────────────────────────────

	public function testRecordSettlementReturnsCreated(): void {
		$this->service->method('recordSettlement')->willReturn($this->makeSettlement());

		$response = $this->controller->recordSettlement(1, 50.00, '2026-03-01');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testRecordSettlementPassesCorrectArguments(): void {
		$this->service->expects($this->once())
			->method('recordSettlement')
			->with('user1', 1, 50.00, '2026-03-01', null)
			->willReturn($this->makeSettlement());

		$this->controller->recordSettlement(1, 50.00, '2026-03-01');
	}

	public function testRecordSettlementWithNotes(): void {
		$this->service->expects($this->once())
			->method('recordSettlement')
			->with('user1', 1, 50.00, '2026-03-01', 'cash payment')
			->willReturn($this->makeSettlement());

		$response = $this->controller->recordSettlement(1, 50.00, '2026-03-01', 'cash payment');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testRecordSettlementHandlesError(): void {
		$this->service->method('recordSettlement')
			->willThrowException(new \RuntimeException('contact not found'));

		$response = $this->controller->recordSettlement(1, 50.00, '2026-03-01');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to record settlement', $response->getData()['error']);
	}

	// ── settleWithContact ───────────────────────────────────────────

	public function testSettleWithContactReturnsCreated(): void {
		$this->service->method('settleWithContact')->willReturn([$this->makeSettlement()]);

		$response = $this->controller->settleWithContact(1, '2026-03-01');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testSettleWithContactPassesCorrectArguments(): void {
		$this->service->expects($this->once())
			->method('settleWithContact')
			->with('user1', 1, '2026-03-01', null)
			->willReturn([$this->makeSettlement()]);

		$this->controller->settleWithContact(1, '2026-03-01');
	}

	public function testSettleWithContactWithNotes(): void {
		$this->service->expects($this->once())
			->method('settleWithContact')
			->with('user1', 1, '2026-03-01', 'full settlement')
			->willReturn([$this->makeSettlement()]);

		$response = $this->controller->settleWithContact(1, '2026-03-01', 'full settlement');

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
	}

	public function testSettleWithContactHandlesError(): void {
		$this->service->method('settleWithContact')
			->willThrowException(new \RuntimeException('error'));

		$response = $this->controller->settleWithContact(1, '2026-03-01');

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to settle', $response->getData()['error']);
	}

	// ── settlements ─────────────────────────────────────────────────

	public function testSettlementsReturnsData(): void {
		$this->service->method('getSettlements')->willReturn([$this->makeSettlement()]);

		$response = $this->controller->settlements();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertCount(1, $response->getData());
	}

	public function testSettlementsPassesUserId(): void {
		$this->service->expects($this->once())
			->method('getSettlements')
			->with('user1')
			->willReturn([]);

		$this->controller->settlements();
	}

	public function testSettlementsHandlesError(): void {
		$this->service->method('getSettlements')
			->willThrowException(new \RuntimeException('db error'));

		$response = $this->controller->settlements();

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to get settlements', $response->getData()['error']);
	}

	// ── destroySettlement ───────────────────────────────────────────

	public function testDestroySettlementReturnsDeleted(): void {
		$this->service->expects($this->once())->method('deleteSettlement')->with(1, 'user1');

		$response = $this->controller->destroySettlement(1);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('deleted', $response->getData()['status']);
	}

	public function testDestroySettlementHandlesError(): void {
		$this->service->method('deleteSettlement')
			->willThrowException(new \RuntimeException('not found'));

		$response = $this->controller->destroySettlement(1);

		$this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $response->getStatus());
		$this->assertSame('Failed to delete settlement', $response->getData()['error']);
	}
}
