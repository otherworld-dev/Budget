<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

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
use PHPUnit\Framework\TestCase;

class SharedExpenseServiceTest extends TestCase {
    private SharedExpenseService $service;
    private ContactMapper $contactMapper;
    private ExpenseShareMapper $expenseShareMapper;
    private SettlementMapper $settlementMapper;
    private TransactionMapper $transactionMapper;

    protected function setUp(): void {
        $this->contactMapper = $this->createMock(ContactMapper::class);
        $this->expenseShareMapper = $this->createMock(ExpenseShareMapper::class);
        $this->settlementMapper = $this->createMock(SettlementMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);

        $this->service = new SharedExpenseService(
            $this->contactMapper,
            $this->expenseShareMapper,
            $this->settlementMapper,
            $this->transactionMapper
        );
    }

    private function makeContact(int $id = 1, string $name = 'Alice'): Contact {
        $contact = new Contact();
        $contact->setId($id);
        $contact->setUserId('user1');
        $contact->setName($name);
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
        $tx->setDate('2026-03-01');
        $tx->setDescription('Test transaction');
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
            ->willReturn([1 => 50.0, 2 => -30.0]);

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
}
