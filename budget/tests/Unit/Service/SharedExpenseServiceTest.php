<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\Contact;
use OCA\Budget\Db\ContactMapper;
use OCA\Budget\Db\ExpenseShare;
use OCA\Budget\Db\ExpenseShareMapper;
use OCA\Budget\Db\Settlement;
use OCA\Budget\Db\SettlementMapper;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\SharedExpenseService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

class SharedExpenseServiceTest extends TestCase {
    private SharedExpenseService $service;
    private ContactMapper $contactMapper;
    private ExpenseShareMapper $expenseShareMapper;
    private SettlementMapper $settlementMapper;
    private TransactionMapper $transactionMapper;
    private IUserManager $userManager;

    protected function setUp(): void {
        $this->contactMapper = $this->createMock(ContactMapper::class);
        $this->expenseShareMapper = $this->createMock(ExpenseShareMapper::class);
        $this->settlementMapper = $this->createMock(SettlementMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);
        $accountMapper = $this->createMock(AccountMapper::class);
        $this->userManager = $this->createMock(IUserManager::class);

        // Default: accountMapper->find returns an account with USD currency
        $account = new \OCA\Budget\Db\Account();
        $account->setId(1);
        $account->setCurrency('USD');
        $accountMapper->method('find')->willReturn($account);

        // Default: no existing shares on a transaction
        $this->expenseShareMapper->method('findByTransaction')->willReturn([]);

        $this->service = new SharedExpenseService(
            $this->contactMapper,
            $this->expenseShareMapper,
            $this->settlementMapper,
            $this->transactionMapper,
            $accountMapper,
            $this->userManager
        );
    }

    public function testGetExpensesSharedWithMeEnrichesOwnerAndTransaction(): void {
        $this->expenseShareMapper->method('findSharedWithNextcloudUser')
            ->with('bob')
            ->willReturn([
                [
                    'id' => 7,
                    'owner_user_id' => 'alice',
                    'transaction_id' => 10,
                    'amount' => 25.0,
                    'is_settled' => false,
                    'notes' => null,
                    'currency' => 'USD',
                    'created_at' => '2026-06-01 00:00:00',
                    'contact_name' => 'Bob',
                    'transaction_description' => 'Dinner',
                    'transaction_date' => '2026-05-30',
                    'transaction_amount' => 50.0,
                    'transaction_type' => 'debit',
                ],
            ]);

        $aliceUser = $this->createMock(IUser::class);
        $aliceUser->method('getDisplayName')->willReturn('Alice Smith');
        $this->userManager->method('get')->with('alice')->willReturn($aliceUser);

        $result = $this->service->getExpensesSharedWithMe('bob');

        $this->assertCount(1, $result);
        $this->assertSame('alice', $result[0]['ownerUserId']);
        $this->assertSame('Alice Smith', $result[0]['ownerName']);
        $this->assertSame('Dinner', $result[0]['transactionDescription']);
        $this->assertEqualsWithDelta(25.0, $result[0]['amount'], 0.001);
        $this->assertFalse($result[0]['isSettled']);
    }

    public function testGetExpensesSharedWithMeFallsBackToUidWhenUserMissing(): void {
        $this->expenseShareMapper->method('findSharedWithNextcloudUser')->willReturn([
            ['id' => 1, 'owner_user_id' => 'ghost', 'transaction_id' => 0, 'amount' => 5.0,
             'is_settled' => true, 'notes' => null, 'currency' => null, 'created_at' => '2026-06-01',
             'contact_name' => null, 'transaction_description' => null, 'transaction_date' => null,
             'transaction_amount' => null, 'transaction_type' => null],
        ]);
        $this->userManager->method('get')->willReturn(null);

        $result = $this->service->getExpensesSharedWithMe('bob');

        $this->assertSame('ghost', $result[0]['ownerName']);
        $this->assertTrue($result[0]['isSettled']);
    }

    private function makeContact(int $id = 1, string $name = 'Alice', ?string $nextcloudUserId = null): Contact {
        $contact = new Contact();
        $contact->setId($id);
        $contact->setUserId('user1');
        $contact->setName($name);
        $contact->setNextcloudUserId($nextcloudUserId);
        return $contact;
    }

    private function makeShare(int $id, float $amount, bool $settled = false, int $contactId = 1, int $txId = 10): ExpenseShare {
        $share = new ExpenseShare();
        $share->setId($id);
        $share->setUserId('user1');
        $share->setTransactionId($txId);
        $share->setContactId($contactId);
        $share->setAmount($amount);
        $share->setIsSettled($settled);
        return $share;
    }

    private function makeTransaction(int $id, float $amount): Transaction {
        $tx = new Transaction();
        $tx->setId($id);
        $tx->setAmount($amount);
        $tx->setAccountId(1);
        $tx->setDate('2026-03-01');
        $tx->setDescription('Test transaction');
        $tx->setType($amount < 0 ? 'debit' : 'credit');
        return $tx;
    }

    // ===== Contact CRUD =====

    public function testCreateContact(): void {
        $this->contactMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (Contact $c) {
                $this->assertEquals('user1', $c->getUserId());
                $this->assertEquals('Bob', $c->getName());
                $this->assertEquals('bob@test.com', $c->getEmail());
                $c->setId(1);
                return $c;
            });

        $result = $this->service->createContact('user1', 'Bob', 'bob@test.com');
        $this->assertEquals('Bob', $result->getName());
    }

    public function testUpdateContact(): void {
        $contact = $this->makeContact();
        $this->contactMapper->method('find')->willReturn($contact);
        $this->contactMapper->expects($this->once())->method('update')
            ->willReturnCallback(function (Contact $c) {
                $this->assertEquals('Updated', $c->getName());
                return $c;
            });

        $this->service->updateContact(1, 'user1', 'Updated');
    }

    public function testDeleteContact(): void {
        $contact = $this->makeContact();
        $this->contactMapper->method('find')->willReturn($contact);
        $this->contactMapper->expects($this->once())->method('delete')
            ->with($contact)->willReturn($contact);

        $this->service->deleteContact(1, 'user1');
    }

    // ===== shareExpense =====

    public function testShareExpenseCreatesShare(): void {
        $tx = $this->makeTransaction(10, -100.0);
        $contact = $this->makeContact();
        $this->transactionMapper->method('find')->willReturn($tx);
        $this->contactMapper->method('find')->willReturn($contact);

        $this->expenseShareMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (ExpenseShare $s) {
                $this->assertEquals(10, $s->getTransactionId());
                $this->assertEquals(1, $s->getContactId());
                $this->assertEquals(50.0, $s->getAmount());
                $this->assertFalse($s->getIsSettled());
                $s->setId(1);
                return $s;
            });

        $this->service->shareExpense('user1', 10, 1, 50.0);
    }

    // ===== splitFiftyFifty =====

    public function testSplitFiftyFiftyExpensePositiveShare(): void {
        $tx = $this->makeTransaction(10, -100.0);
        $contact = $this->makeContact();
        $this->transactionMapper->method('find')->willReturn($tx);
        $this->contactMapper->method('find')->willReturn($contact);

        $this->expenseShareMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (ExpenseShare $s) {
                // Expense: they owe you half = positive 50
                $this->assertEquals(50.0, $s->getAmount());
                $s->setId(1);
                return $s;
            });

        $this->service->splitFiftyFifty('user1', 10, 1);
    }

    public function testSplitFiftyFiftyIncomeNegativeShare(): void {
        $tx = $this->makeTransaction(10, 200.0);
        $contact = $this->makeContact();
        $this->transactionMapper->method('find')->willReturn($tx);
        $this->contactMapper->method('find')->willReturn($contact);

        $this->expenseShareMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (ExpenseShare $s) {
                // Income: you owe them half = negative -100
                $this->assertEquals(-100.0, $s->getAmount());
                $s->setId(1);
                return $s;
            });

        $this->service->splitFiftyFifty('user1', 10, 1);
    }

    // ===== settlement =====

    public function testRecordSettlement(): void {
        $contact = $this->makeContact();
        $this->contactMapper->method('find')->willReturn($contact);

        $this->settlementMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (Settlement $s) {
                $this->assertEquals(1, $s->getContactId());
                $this->assertEquals(150.0, $s->getAmount());
                $this->assertEquals('2026-03-08', $s->getDate());
                $s->setId(1);
                return $s;
            });

        $this->service->recordSettlement('user1', 1, 150.0, '2026-03-08');
    }

    public function testSettleWithContactMarksAllAndRecords(): void {
        $share1 = $this->makeShare(1, 50.0, false);
        $share2 = $this->makeShare(2, 30.0, false);

        $this->expenseShareMapper->method('findUnsettledByContact')
            ->willReturn([$share1, $share2]);

        // Each share gets settled
        $this->expenseShareMapper->expects($this->exactly(2))->method('update')
            ->willReturnCallback(function (ExpenseShare $s) {
                $this->assertTrue($s->getIsSettled());
                return $s;
            });

        $contact = $this->makeContact();
        $this->contactMapper->method('find')->willReturn($contact);

        // Settlement recorded with total = 50 + 30 = 80
        $this->settlementMapper->expects($this->once())->method('insert')
            ->willReturnCallback(function (Settlement $s) {
                $this->assertEquals(80.0, $s->getAmount());
                $s->setId(1);
                return $s;
            });

        $this->service->settleWithContact('user1', 1, '2026-03-08');
    }

    // ===== getBalanceSummary =====

    public function testGetBalanceSummaryCalculatesDirections(): void {
        $alice = $this->makeContact(1, 'Alice');
        $bob = $this->makeContact(2, 'Bob');

        $this->contactMapper->method('findAll')->willReturn([$alice, $bob]);
        $this->expenseShareMapper->method('getBalancesByContact')
            ->willReturn([1 => ['USD' => 50.0], 2 => ['USD' => -30.0]]);

        $result = $this->service->getBalanceSummary('user1');

        $this->assertEquals(50.0, $result['totalOwed']);
        $this->assertEquals(30.0, $result['totalOwing']);
        $this->assertEquals(20.0, $result['netBalance']);
        $this->assertEquals('owed', $result['contacts'][0]['direction']);
        $this->assertEquals('owing', $result['contacts'][1]['direction']);
    }

    public function testGetBalanceSummarySettledDirection(): void {
        $alice = $this->makeContact(1, 'Alice');
        $this->contactMapper->method('findAll')->willReturn([$alice]);
        $this->expenseShareMapper->method('getBalancesByContact')->willReturn([]);

        $result = $this->service->getBalanceSummary('user1');

        $this->assertEquals('settled', $result['contacts'][0]['direction']);
    }

    public function testGetBalanceSummaryCountsWhatALinkedUserSplitWithMe(): void {
        // alice split £60 with user1, so user1's card for alice must say
        // "you owe £60" — her +60 ("owes you") is user1's -60 (#390)
        $alice = $this->makeContact(1, 'Alice', 'alice');
        $this->contactMapper->method('findAll')->willReturn([$alice]);
        $this->expenseShareMapper->method('getBalancesByContact')->willReturn([]);
        $this->expenseShareMapper->method('getIncomingBalancesByOwner')
            ->with('user1')
            ->willReturn(['alice' => ['GBP' => 60.0]]);

        $result = $this->service->getBalanceSummary('user1');

        $card = $result['contacts'][0];
        $this->assertSame('owing', $card['direction']);
        $this->assertEquals([['currency' => 'GBP', 'amount' => -60.0, 'direction' => 'owing']], $card['balances']);
        $this->assertEqualsWithDelta(-60.0, $card['balance'], 0.001);
        $this->assertEqualsWithDelta(60.0, $result['totalsByCurrency']['GBP']['owing'], 0.001);
        $this->assertEqualsWithDelta(60.0, $result['totalOwing'], 0.001);
    }

    public function testGetBalanceSummaryNetsBothDirectionsForALinkedUser(): void {
        // user1 split £25 with alice, alice split £60 with user1 → user1 owes £35
        $alice = $this->makeContact(1, 'Alice', 'alice');
        $this->contactMapper->method('findAll')->willReturn([$alice]);
        $this->expenseShareMapper->method('getBalancesByContact')->willReturn([1 => ['GBP' => 25.0]]);
        $this->expenseShareMapper->method('getIncomingBalancesByOwner')
            ->willReturn(['alice' => ['GBP' => 60.0]]);

        $result = $this->service->getBalanceSummary('user1');

        $this->assertSame('owing', $result['contacts'][0]['direction']);
        $this->assertEqualsWithDelta(-35.0, $result['contacts'][0]['balances'][0]['amount'], 0.001);
        $this->assertEqualsWithDelta(0.0, $result['totalOwed'], 0.001);
        $this->assertEqualsWithDelta(35.0, $result['totalOwing'], 0.001);
    }

    public function testGetBalanceSummaryIgnoresIncomingForUnlinkedContacts(): void {
        // A manual contact named like the sharer, and a contact linked to
        // user1 itself, must not pick up alice's splits
        $manual = $this->makeContact(1, 'Alice');
        $self = $this->makeContact(2, 'Me', 'user1');
        $this->contactMapper->method('findAll')->willReturn([$manual, $self]);
        $this->expenseShareMapper->method('getBalancesByContact')->willReturn([]);
        $this->expenseShareMapper->method('getIncomingBalancesByOwner')
            ->willReturn(['alice' => ['GBP' => 60.0], 'user1' => ['GBP' => 5.0]]);

        $result = $this->service->getBalanceSummary('user1');

        $this->assertSame('settled', $result['contacts'][0]['direction']);
        $this->assertSame('settled', $result['contacts'][1]['direction']);
        $this->assertSame([], $result['totalsByCurrency']);
    }

    // ===== getContactDetails =====

    public function testGetContactDetailsEnrichesShares(): void {
        $contact = $this->makeContact();
        $share = $this->makeShare(1, 50.0, false, 1, 10);
        $tx = $this->makeTransaction(10, -100.0);

        $this->contactMapper->method('find')->willReturn($contact);
        $this->expenseShareMapper->method('findByContact')->willReturn([$share]);
        $this->settlementMapper->method('findByContact')->willReturn([]);
        $this->transactionMapper->method('find')->willReturn($tx);

        $result = $this->service->getContactDetails(1, 'user1');

        $this->assertCount(1, $result['shares']);
        $this->assertEquals(10, $result['shares'][0]['transaction']['id']);
        $this->assertEquals(50.0, $result['balance']);
        $this->assertEquals('owed', $result['direction']);
    }

    public function testGetContactDetailsSkipsDeletedTransactions(): void {
        $contact = $this->makeContact();
        $share = $this->makeShare(1, 50.0, false, 1, 999);

        $this->contactMapper->method('find')->willReturn($contact);
        $this->expenseShareMapper->method('findByContact')->willReturn([$share]);
        $this->settlementMapper->method('findByContact')->willReturn([]);
        $this->transactionMapper->method('find')
            ->willThrowException(new DoesNotExistException('Not found'));

        $result = $this->service->getContactDetails(1, 'user1');

        $this->assertEmpty($result['shares']);
    }

    private function makeIncomingRow(int $id, float $amount, bool $settled, int $txId, string $createdAt): array {
        return [
            'id' => $id,
            'owner_user_id' => 'alice',
            'transaction_id' => $txId,
            'amount' => $amount,
            'is_settled' => $settled ? 1 : 0,
            'notes' => null,
            'currency' => 'GBP',
            'created_at' => $createdAt,
            'contact_name' => 'User One',
            'transaction_description' => 'Groceries',
            'transaction_date' => '2026-09-10',
            'transaction_amount' => 80.0,
            'transaction_type' => 'debit',
        ];
    }

    public function testGetContactDetailsIncludesWhatALinkedUserSplitWithMe(): void {
        $contact = $this->makeContact(1, 'Alice', 'alice');
        $this->contactMapper->method('find')->willReturn($contact);
        $this->expenseShareMapper->method('findByContact')->willReturn([]);
        $this->expenseShareMapper->method('findSharedWithNextcloudUser')
            ->with('user1', 'alice')
            ->willReturn([
                $this->makeIncomingRow(7, 40.0, false, 1224, '2026-09-10 10:00:00'),
                $this->makeIncomingRow(8, 20.0, true, 1225, '2026-09-09 10:00:00'),
            ]);

        $aliceSettlement = new Settlement();
        $aliceSettlement->setId(3);
        $aliceSettlement->setUserId('alice');
        $aliceSettlement->setContactId(99);
        $aliceSettlement->setAmount(20.0);
        $aliceSettlement->setDate('2026-09-11');
        $aliceSettlement->setCurrency('GBP');
        $this->settlementMapper->method('findByContact')->willReturn([]);
        $this->settlementMapper->method('findSharedWithNextcloudUser')
            ->with('user1', 'alice')
            ->willReturn([$aliceSettlement]);

        $result = $this->service->getContactDetails(1, 'user1');

        // Both of alice's splits are listed, from user1's side of the ledger
        $this->assertCount(2, $result['shares']);
        $first = $result['shares'][0];
        $this->assertTrue($first['incoming']);
        $this->assertSame(7, $first['share']['id']);
        $this->assertEqualsWithDelta(-40.0, $first['share']['amount'], 0.001);
        $this->assertFalse($first['share']['isSettled']);
        $this->assertSame('Groceries', $first['transaction']['description']);
        $this->assertTrue($result['shares'][1]['share']['isSettled']);

        // Only the open one counts: user1 owes alice £40
        $this->assertEquals(['GBP' => -40.0], $result['balances']);
        $this->assertSame('owing', $result['direction']);

        // alice's "received £20" is user1's "paid £20"
        $this->assertCount(1, $result['settlements']);
        $this->assertTrue($result['settlements'][0]['incoming']);
        $this->assertEqualsWithDelta(-20.0, $result['settlements'][0]['amount'], 0.001);
    }

    public function testGetContactDetailsMarksOwnSharesAsNotIncoming(): void {
        $contact = $this->makeContact(1, 'Alice', 'alice');
        $share = $this->makeShare(1, 50.0, false, 1, 10);
        $this->contactMapper->method('find')->willReturn($contact);
        $this->expenseShareMapper->method('findByContact')->willReturn([$share]);
        $this->expenseShareMapper->method('findSharedWithNextcloudUser')->willReturn([]);
        $this->settlementMapper->method('findByContact')->willReturn([]);
        $this->settlementMapper->method('findSharedWithNextcloudUser')->willReturn([]);
        $this->transactionMapper->method('find')->willReturn($this->makeTransaction(10, -100.0));

        $result = $this->service->getContactDetails(1, 'user1');

        $this->assertFalse($result['shares'][0]['incoming']);
        $this->assertEquals(['USD' => 50.0], $result['balances']);
    }

    public function testGetContactDetailsSkipsIncomingForUnlinkedContact(): void {
        $contact = $this->makeContact(1, 'Alice');
        $this->contactMapper->method('find')->willReturn($contact);
        $this->expenseShareMapper->method('findByContact')->willReturn([]);
        $this->settlementMapper->method('findByContact')->willReturn([]);
        $this->expenseShareMapper->expects($this->never())->method('findSharedWithNextcloudUser');
        $this->settlementMapper->expects($this->never())->method('findSharedWithNextcloudUser');

        $result = $this->service->getContactDetails(1, 'user1');

        $this->assertSame('settled', $result['direction']);
    }
}
