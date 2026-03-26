<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\Bill;
use OCA\Budget\Db\RecurringIncome;
use OCA\Budget\Db\Transaction;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Db\TransactionTagMapper;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\TransactionService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class TransactionServiceTest extends TestCase {
    private TransactionService $service;
    private TransactionMapper $mapper;
    private AccountMapper $accountMapper;
    private TransactionTagMapper $transactionTagMapper;

    protected function setUp(): void {
        $this->mapper = $this->createMock(TransactionMapper::class);
        $this->accountMapper = $this->createMock(AccountMapper::class);
        $this->transactionTagMapper = $this->createMock(TransactionTagMapper::class);

        $this->service = new TransactionService(
            $this->mapper,
            $this->accountMapper,
            $this->transactionTagMapper
        );
    }

    private function makeTransaction(array $overrides = []): Transaction {
        $tx = new Transaction();
        $defaults = [
            'id' => 1,
            'accountId' => 10,
            'date' => '2026-01-15',
            'description' => 'Test transaction',
            'amount' => 50.00,
            'type' => 'debit',
            'categoryId' => null,
            'vendor' => null,
            'reference' => null,
            'notes' => null,
            'importId' => null,
            'reconciled' => false,
            'linkedTransactionId' => null,
            'billId' => null,
        ];
        $data = array_merge($defaults, $overrides);

        $tx->setId($data['id']);
        $tx->setAccountId($data['accountId']);
        $tx->setDate($data['date']);
        $tx->setDescription($data['description']);
        $tx->setAmount($data['amount']);
        $tx->setType($data['type']);
        $tx->setCategoryId($data['categoryId']);
        $tx->setVendor($data['vendor']);
        $tx->setReference($data['reference']);
        $tx->setNotes($data['notes']);
        $tx->setImportId($data['importId']);
        $tx->setReconciled($data['reconciled']);
        $tx->setLinkedTransactionId($data['linkedTransactionId']);
        $tx->setBillId($data['billId']);
        $tx->setCreatedAt('2026-01-15 10:00:00');
        $tx->setUpdatedAt('2026-01-15 10:00:00');
        return $tx;
    }

    private function makeAccount(array $overrides = []): Account {
        $account = new Account();
        $defaults = [
            'id' => 10,
            'userId' => 'user1',
            'name' => 'Checking',
            'type' => 'checking',
            'balance' => 1000.00,
            'currency' => 'USD',
        ];
        $data = array_merge($defaults, $overrides);

        $account->setId($data['id']);
        $account->setUserId($data['userId']);
        $account->setName($data['name']);
        $account->setType($data['type']);
        $account->setBalance($data['balance']);
        $account->setCurrency($data['currency']);
        return $account;
    }

    private function makeBill(array $overrides = []): Bill {
        $bill = new Bill();
        $defaults = [
            'id' => 1,
            'userId' => 'user1',
            'name' => 'Rent',
            'amount' => 500.00,
            'accountId' => 10,
            'categoryId' => 5,
            'nextDueDate' => '2026-02-01',
            'isTransfer' => false,
            'destinationAccountId' => null,
            'tagIds' => null,
        ];
        $data = array_merge($defaults, $overrides);

        $bill->setId($data['id']);
        $bill->setUserId($data['userId']);
        $bill->setName($data['name']);
        $bill->setAmount($data['amount']);
        $bill->setAccountId($data['accountId']);
        $bill->setCategoryId($data['categoryId']);
        $bill->setNextDueDate($data['nextDueDate']);
        $bill->setIsTransfer($data['isTransfer']);
        $bill->setDestinationAccountId($data['destinationAccountId']);
        $bill->setTagIds($data['tagIds']);
        return $bill;
    }

    // ===== find() =====

    public function testFindDelegatesToMapper(): void {
        $tx = $this->makeTransaction();
        $this->mapper->expects($this->once())
            ->method('find')
            ->with(1, 'user1')
            ->willReturn($tx);

        $result = $this->service->find(1, 'user1');
        $this->assertSame($tx, $result);
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->mapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);
        $this->service->find(999, 'user1');
    }

    // ===== findByAccount() =====

    public function testFindByAccountVerifiesOwnership(): void {
        $account = $this->makeAccount();
        $this->accountMapper->expects($this->once())
            ->method('find')
            ->with(10, 'user1')
            ->willReturn($account);

        $this->mapper->expects($this->once())
            ->method('findByAccount')
            ->with(10, 100, 0)
            ->willReturn([]);

        $this->service->findByAccount('user1', 10);
    }

    public function testFindByAccountThrowsIfNotOwner(): void {
        $this->accountMapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);
        $this->service->findByAccount('user1', 10);
    }

    // ===== create() =====

    public function testCreateDebitSubtractsFromBalance(): void {
        $account = $this->makeAccount(['balance' => 1000.00]);
        $this->accountMapper->method('find')->willReturn($account);
        $this->mapper->method('existsByImportId')->willReturn(false);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Transaction $tx) {
                $this->assertEquals(10, $tx->getAccountId());
                $this->assertEquals('2026-01-15', $tx->getDate());
                $this->assertEquals('Groceries', $tx->getDescription());
                $this->assertEquals(50.00, $tx->getAmount());
                $this->assertEquals('debit', $tx->getType());
                $this->assertFalse($tx->getReconciled());
                $tx->setId(1);
                return $tx;
            });

        $this->accountMapper->expects($this->once())
            ->method('updateBalance')
            ->with(10, '950.00', 'user1');

        $result = $this->service->create(
            'user1', 10, '2026-01-15', 'Groceries', 50.00, 'debit'
        );

        $this->assertEquals(1, $result->getId());
    }

    public function testCreateCreditAddsToBalance(): void {
        $account = $this->makeAccount(['balance' => 1000.00]);
        $this->accountMapper->method('find')->willReturn($account);
        $this->mapper->method('existsByImportId')->willReturn(false);

        $this->mapper->method('insert')->willReturnCallback(function (Transaction $tx) {
            $tx->setId(2);
            return $tx;
        });

        $this->accountMapper->expects($this->once())
            ->method('updateBalance')
            ->with(10, '1050.00', 'user1');

        $this->service->create(
            'user1', 10, '2026-01-15', 'Salary', 50.00, 'credit'
        );
    }

    public function testCreateRejectsDuplicateImportId(): void {
        $account = $this->makeAccount();
        $this->accountMapper->method('find')->willReturn($account);
        $this->mapper->method('existsByImportId')
            ->with(10, 'import-123')
            ->willReturn(true);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('import ID already exists');

        $this->service->create(
            'user1', 10, '2026-01-15', 'Dup', 10.00, 'debit',
            null, null, null, null, 'import-123'
        );
    }

    public function testCreateSetsAllOptionalFields(): void {
        $account = $this->makeAccount();
        $this->accountMapper->method('find')->willReturn($account);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Transaction $tx) {
                $this->assertEquals(5, $tx->getCategoryId());
                $this->assertEquals('Amazon', $tx->getVendor());
                $this->assertEquals('REF-001', $tx->getReference());
                $this->assertEquals('Online purchase', $tx->getNotes());
                $this->assertEquals('imp-1', $tx->getImportId());
                $this->assertEquals(3, $tx->getBillId());
                $tx->setId(1);
                return $tx;
            });

        $this->accountMapper->method('updateBalance')->willReturn($account);

        $this->service->create(
            'user1', 10, '2026-01-15', 'Amazon Order', 99.99, 'debit',
            5, 'Amazon', 'REF-001', 'Online purchase', 'imp-1', 3
        );
    }

    // ===== update() =====

    public function testUpdateAppliesFieldChanges(): void {
        $tx = $this->makeTransaction(['amount' => 50.00, 'type' => 'debit']);
        $this->mapper->method('find')->willReturn($tx);

        $this->mapper->expects($this->once())
            ->method('update')
            ->willReturnArgument(0);

        $result = $this->service->update(1, 'user1', ['description' => 'Updated']);

        $this->assertEquals('Updated', $result->getDescription());
    }

    public function testUpdateRecalculatesBalanceOnAmountChange(): void {
        $tx = $this->makeTransaction(['amount' => 50.00, 'type' => 'debit']);
        $this->mapper->method('find')->willReturn($tx);
        $this->mapper->method('update')->willReturnArgument(0);

        $account = $this->makeAccount(['balance' => 950.00]);
        $this->accountMapper->method('find')->willReturn($account);

        // Old effect: -50, new effect: -75, net change: -25
        // New balance: 950 + (-25) = 925
        $this->accountMapper->expects($this->once())
            ->method('updateBalance')
            ->with(10, '925.00', 'user1');

        $this->service->update(1, 'user1', ['amount' => 75.00]);
    }

    public function testUpdateRecalculatesBalanceOnTypeChange(): void {
        $tx = $this->makeTransaction(['amount' => 50.00, 'type' => 'debit']);
        $this->mapper->method('find')->willReturn($tx);
        $this->mapper->method('update')->willReturnArgument(0);

        $account = $this->makeAccount(['balance' => 950.00]);
        $this->accountMapper->method('find')->willReturn($account);

        // Old effect: -50, new effect: +50, net change: +100
        // New balance: 950 + 100 = 1050
        $this->accountMapper->expects($this->once())
            ->method('updateBalance')
            ->with(10, '1050.00', 'user1');

        $this->service->update(1, 'user1', ['type' => 'credit']);
    }

    public function testUpdateSkipsBalanceRecalcWhenAmountUnchanged(): void {
        $tx = $this->makeTransaction(['amount' => 50.00, 'type' => 'debit']);
        $this->mapper->method('find')->willReturn($tx);
        $this->mapper->method('update')->willReturnArgument(0);

        $this->accountMapper->expects($this->never())->method('updateBalance');

        $this->service->update(1, 'user1', ['description' => 'Just a note change']);
    }

    // ===== delete() =====

    public function testDeleteReversesDebitBalance(): void {
        $tx = $this->makeTransaction(['amount' => 100.00, 'type' => 'debit']);
        $this->mapper->method('find')->willReturn($tx);

        $account = $this->makeAccount(['balance' => 900.00]);
        $this->accountMapper->method('find')->willReturn($account);

        // Reversing a debit means crediting: 900 + 100 = 1000
        $this->accountMapper->expects($this->once())
            ->method('updateBalance')
            ->with(10, '1000.00', 'user1');

        $this->transactionTagMapper->expects($this->once())
            ->method('deleteByTransaction')
            ->with(1);

        $this->mapper->expects($this->once())
            ->method('delete')
            ->with($tx);

        $this->service->delete(1, 'user1');
    }

    public function testDeleteReversesCreditBalance(): void {
        $tx = $this->makeTransaction(['amount' => 200.00, 'type' => 'credit']);
        $this->mapper->method('find')->willReturn($tx);

        $account = $this->makeAccount(['balance' => 1200.00]);
        $this->accountMapper->method('find')->willReturn($account);

        // Reversing a credit means debiting: 1200 - 200 = 1000
        $this->accountMapper->expects($this->once())
            ->method('updateBalance')
            ->with(10, '1000.00', 'user1');

        $this->service->delete(1, 'user1');
    }

    // ===== createFromBill() =====

    public function testCreateFromBillCreatesDebitForRegularBill(): void {
        $bill = $this->makeBill();
        $account = $this->makeAccount();
        $this->accountMapper->method('find')->willReturn($account);

        $insertCount = 0;
        $this->mapper->method('insert')->willReturnCallback(function (Transaction $tx) use (&$insertCount) {
            $insertCount++;
            $tx->setId($insertCount);
            return $tx;
        });
        $this->accountMapper->method('updateBalance')->willReturn($account);

        $result = $this->service->createFromBill('user1', $bill);

        $this->assertEquals(1, $insertCount);
        $this->assertEquals('Rent', $result->getDescription());
        $this->assertEquals('debit', $result->getType());
        $this->assertEquals(500.00, $result->getAmount());
    }

    public function testCreateFromBillCreatesTransferPair(): void {
        $bill = $this->makeBill([
            'isTransfer' => true,
            'destinationAccountId' => 20,
        ]);

        $sourceAccount = $this->makeAccount(['id' => 10, 'balance' => 2000.00]);
        $destAccount = $this->makeAccount(['id' => 20, 'balance' => 500.00]);

        $this->accountMapper->method('find')->willReturnCallback(
            function (int $id) use ($sourceAccount, $destAccount) {
                return $id === 10 ? $sourceAccount : $destAccount;
            }
        );

        $insertedTransactions = [];
        $this->mapper->method('insert')->willReturnCallback(function (Transaction $tx) use (&$insertedTransactions) {
            $tx->setId(count($insertedTransactions) + 1);
            $insertedTransactions[] = clone $tx;
            return $tx;
        });

        // linkTransactions() internally calls find() on the mapper, so we need to
        // return Transaction objects with different accountIds for validation to pass
        $this->mapper->method('find')->willReturnCallback(function (int $id) use (&$insertedTransactions) {
            foreach ($insertedTransactions as $tx) {
                if ($tx->getId() === $id) {
                    return $tx;
                }
            }
            return $insertedTransactions[0];
        });

        $this->mapper->expects($this->once())
            ->method('linkTransactions')
            ->with(1, 2);

        $this->accountMapper->method('updateBalance')->willReturn($sourceAccount);

        $result = $this->service->createFromBill('user1', $bill);

        $this->assertCount(2, $insertedTransactions);
        $this->assertEquals('debit', $insertedTransactions[0]->getType());
        $this->assertEquals(10, $insertedTransactions[0]->getAccountId());
        $this->assertEquals('credit', $insertedTransactions[1]->getType());
        $this->assertEquals(20, $insertedTransactions[1]->getAccountId());
    }

    public function testCreateFromBillAppliesTagsToRegularBill(): void {
        $bill = $this->makeBill(['tagIds' => '[1,2,3]']);
        $account = $this->makeAccount();
        $this->accountMapper->method('find')->willReturn($account);

        $this->mapper->method('insert')->willReturnCallback(function (Transaction $tx) {
            $tx->setId(1);
            return $tx;
        });
        $this->accountMapper->method('updateBalance')->willReturn($account);

        $this->transactionTagMapper->expects($this->exactly(3))->method('insert');

        $this->service->createFromBill('user1', $bill);
    }

    public function testCreateFromBillThrowsWithoutAccount(): void {
        $bill = $this->makeBill(['accountId' => null]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('must have an account');

        $this->service->createFromBill('user1', $bill);
    }

    public function testCreateFromBillThrowsForTransferWithoutDestination(): void {
        $bill = $this->makeBill([
            'isTransfer' => true,
            'destinationAccountId' => null,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('destination account');

        $this->service->createFromBill('user1', $bill);
    }

    public function testCreateFromBillUsesOverrideDate(): void {
        $bill = $this->makeBill(['nextDueDate' => '2026-02-01']);
        $account = $this->makeAccount();
        $this->accountMapper->method('find')->willReturn($account);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Transaction $tx) {
                $this->assertEquals('2026-03-15', $tx->getDate());
                $tx->setId(1);
                return $tx;
            });
        $this->accountMapper->method('updateBalance')->willReturn($account);

        $this->service->createFromBill('user1', $bill, '2026-03-15');
    }

    // ===== createFromIncome() =====

    private function makeIncome(array $overrides = []): RecurringIncome {
        $income = new RecurringIncome();
        $defaults = [
            'id' => 1,
            'userId' => 'user1',
            'name' => 'Salary',
            'amount' => 3000.00,
            'accountId' => 10,
            'categoryId' => 5,
            'nextExpectedDate' => '2026-03-25',
        ];
        $data = array_merge($defaults, $overrides);

        $income->setId($data['id']);
        $income->setUserId($data['userId']);
        $income->setName($data['name']);
        $income->setAmount($data['amount']);
        $income->setAccountId($data['accountId']);
        $income->setCategoryId($data['categoryId']);
        $income->setNextExpectedDate($data['nextExpectedDate']);
        return $income;
    }

    public function testCreateFromIncomeCreatesCreditTransaction(): void {
        $income = $this->makeIncome();
        $account = $this->makeAccount();
        $this->accountMapper->method('find')->willReturn($account);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Transaction $tx) {
                $this->assertEquals('Salary', $tx->getDescription());
                $this->assertEquals('credit', $tx->getType());
                $this->assertEquals(3000.00, $tx->getAmount());
                $this->assertEquals(10, $tx->getAccountId());
                $this->assertEquals(5, $tx->getCategoryId());
                $this->assertEquals('2026-03-25', $tx->getDate());
                $this->assertStringContainsString('Auto-generated from income', $tx->getNotes());
                $tx->setId(1);
                return $tx;
            });
        $this->accountMapper->method('updateBalance')->willReturn($account);

        $result = $this->service->createFromIncome('user1', $income);

        $this->assertEquals('credit', $result->getType());
        $this->assertEquals(3000.00, $result->getAmount());
    }

    public function testCreateFromIncomeThrowsWithoutAccount(): void {
        $income = $this->makeIncome(['accountId' => null]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('must have an account');

        $this->service->createFromIncome('user1', $income);
    }

    public function testCreateFromIncomeUsesOverrideDate(): void {
        $income = $this->makeIncome(['nextExpectedDate' => '2026-03-25']);
        $account = $this->makeAccount();
        $this->accountMapper->method('find')->willReturn($account);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Transaction $tx) {
                $this->assertEquals('2026-04-01', $tx->getDate());
                $tx->setId(1);
                return $tx;
            });
        $this->accountMapper->method('updateBalance')->willReturn($account);

        $this->service->createFromIncome('user1', $income, '2026-04-01');
    }

    public function testCreateFromIncomeUsesScheduledStatusForFutureDate(): void {
        $income = $this->makeIncome(['nextExpectedDate' => '2099-12-31']);
        $account = $this->makeAccount();
        $this->accountMapper->method('find')->willReturn($account);

        $this->mapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Transaction $tx) {
                $this->assertEquals('scheduled', $tx->getStatus());
                $tx->setId(1);
                return $tx;
            });
        $this->accountMapper->method('updateBalance')->willReturn($account);

        $this->service->createFromIncome('user1', $income);
    }

    // ===== linkTransactions() =====

    public function testLinkTransactionsSuccess(): void {
        $tx1 = $this->makeTransaction(['id' => 1, 'accountId' => 10, 'amount' => 100.00, 'type' => 'debit']);
        $tx2 = $this->makeTransaction(['id' => 2, 'accountId' => 20, 'amount' => 100.00, 'type' => 'credit']);

        $this->mapper->method('find')->willReturnCallback(
            function (int $id) use ($tx1, $tx2) {
                return $id === 1 ? $tx1 : $tx2;
            }
        );

        $this->mapper->expects($this->once())
            ->method('linkTransactions')
            ->with(1, 2);

        $result = $this->service->linkTransactions(1, 2, 'user1');

        $this->assertArrayHasKey('transaction', $result);
        $this->assertArrayHasKey('linkedTransaction', $result);
    }

    public function testLinkTransactionsRejectsSameAccount(): void {
        $tx1 = $this->makeTransaction(['id' => 1, 'accountId' => 10, 'amount' => 100.00, 'type' => 'debit']);
        $tx2 = $this->makeTransaction(['id' => 2, 'accountId' => 10, 'amount' => 100.00, 'type' => 'credit']);

        $this->mapper->method('find')->willReturnCallback(
            function (int $id) use ($tx1, $tx2) {
                return $id === 1 ? $tx1 : $tx2;
            }
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('same account');

        $this->service->linkTransactions(1, 2, 'user1');
    }

    public function testLinkTransactionsRejectsDifferentAmounts(): void {
        $tx1 = $this->makeTransaction(['id' => 1, 'accountId' => 10, 'amount' => 100.00, 'type' => 'debit']);
        $tx2 = $this->makeTransaction(['id' => 2, 'accountId' => 20, 'amount' => 200.00, 'type' => 'credit']);

        $this->mapper->method('find')->willReturnCallback(
            function (int $id) use ($tx1, $tx2) {
                return $id === 1 ? $tx1 : $tx2;
            }
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('different amounts');

        $this->service->linkTransactions(1, 2, 'user1');
    }

    public function testLinkTransactionsRejectsSameType(): void {
        $tx1 = $this->makeTransaction(['id' => 1, 'accountId' => 10, 'amount' => 100.00, 'type' => 'debit']);
        $tx2 = $this->makeTransaction(['id' => 2, 'accountId' => 20, 'amount' => 100.00, 'type' => 'debit']);

        $this->mapper->method('find')->willReturnCallback(
            function (int $id) use ($tx1, $tx2) {
                return $id === 1 ? $tx1 : $tx2;
            }
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('same type');

        $this->service->linkTransactions(1, 2, 'user1');
    }

    public function testLinkTransactionsRejectsAlreadyLinked(): void {
        $tx1 = $this->makeTransaction(['id' => 1, 'accountId' => 10, 'amount' => 100.00, 'type' => 'debit', 'linkedTransactionId' => 99]);
        $tx2 = $this->makeTransaction(['id' => 2, 'accountId' => 20, 'amount' => 100.00, 'type' => 'credit']);

        $this->mapper->method('find')->willReturnCallback(
            function (int $id) use ($tx1, $tx2) {
                return $id === 1 ? $tx1 : $tx2;
            }
        );

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('already linked');

        $this->service->linkTransactions(1, 2, 'user1');
    }

    // ===== unlinkTransaction() =====

    public function testUnlinkTransactionSuccess(): void {
        $tx = $this->makeTransaction(['id' => 1, 'linkedTransactionId' => 2]);
        $unlinkedTx = $this->makeTransaction(['id' => 1, 'linkedTransactionId' => null]);

        $this->mapper->method('find')->willReturnOnConsecutiveCalls($tx, $unlinkedTx);
        $this->mapper->method('unlinkTransaction')
            ->with(1)
            ->willReturn(2);

        $result = $this->service->unlinkTransaction(1, 'user1');

        $this->assertEquals(2, $result['unlinkedTransactionId']);
    }

    public function testUnlinkTransactionThrowsWhenNotLinked(): void {
        $tx = $this->makeTransaction(['linkedTransactionId' => null]);
        $this->mapper->method('find')->willReturn($tx);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('not linked');

        $this->service->unlinkTransaction(1, 'user1');
    }

    // ===== findPotentialMatches() =====

    public function testFindPotentialMatchesReturnsEmptyWhenAlreadyLinked(): void {
        $tx = $this->makeTransaction(['linkedTransactionId' => 99]);
        $this->mapper->method('find')->willReturn($tx);

        $this->mapper->expects($this->never())->method('findPotentialMatches');

        $result = $this->service->findPotentialMatches(1, 'user1');
        $this->assertEmpty($result);
    }

    public function testFindPotentialMatchesDelegatesToMapper(): void {
        $tx = $this->makeTransaction(['linkedTransactionId' => null]);
        $account = $this->makeAccount(['id' => 10, 'currency' => 'USD']);
        $this->mapper->method('find')->willReturn($tx);
        $this->accountMapper->method('find')->willReturn($account);

        $matches = [$this->makeTransaction(['id' => 2])];
        $this->mapper->expects($this->once())
            ->method('findPotentialMatches')
            ->with('user1', 1, 10, 50.00, 'debit', '2026-01-15', 'USD', 3)
            ->willReturn($matches);

        $result = $this->service->findPotentialMatches(1, 'user1');
        $this->assertCount(1, $result);
    }

    // ===== bulkCategorize() =====

    public function testBulkCategorizeCountsSuccessesAndFailures(): void {
        $tx = $this->makeTransaction();
        $callCount = 0;

        $this->mapper->method('find')->willReturnCallback(function () use ($tx, &$callCount) {
            $callCount++;
            if ($callCount === 2) {
                throw new DoesNotExistException('');
            }
            return $tx;
        });

        $this->mapper->method('update')->willReturnArgument(0);

        $result = $this->service->bulkCategorize('user1', [
            ['id' => 1, 'categoryId' => 5],
            ['id' => 999, 'categoryId' => 5],  // This will fail
            ['id' => 3, 'categoryId' => 5],
        ]);

        $this->assertEquals(2, $result['success']);
        $this->assertEquals(1, $result['failed']);
    }

    // ===== bulkDelete() =====

    public function testBulkDeleteTracksErrorDetails(): void {
        $tx = $this->makeTransaction();
        $account = $this->makeAccount();

        $callCount = 0;
        $this->mapper->method('find')->willReturnCallback(function () use ($tx, &$callCount) {
            $callCount++;
            if ($callCount === 2) {
                throw new DoesNotExistException('Not found');
            }
            return $tx;
        });

        $this->accountMapper->method('find')->willReturn($account);
        $this->accountMapper->method('updateBalance')->willReturn($account);
        $this->mapper->method('delete')->willReturn($tx);
        $this->transactionTagMapper->method('deleteByTransaction')->willReturn(0);

        $result = $this->service->bulkDelete('user1', [1, 999]);

        $this->assertEquals(1, $result['success']);
        $this->assertEquals(1, $result['failed']);
        $this->assertCount(1, $result['errors']);
        $this->assertEquals(999, $result['errors'][0]['id']);
    }

    // ===== bulkReconcile() =====

    public function testBulkReconcileUpdatesStatus(): void {
        $tx = $this->makeTransaction();
        $this->mapper->method('find')->willReturn($tx);
        $this->mapper->method('update')->willReturnArgument(0);

        $result = $this->service->bulkReconcile('user1', [1, 2, 3], true);

        $this->assertEquals(3, $result['success']);
        $this->assertEquals(0, $result['failed']);
    }

    // ===== bulkFindAndMatch() =====

    public function testBulkFindAndMatchAutoLinksUniqueMatches(): void {
        $this->mapper->method('findUnlinkedWithMatches')
            ->willReturn([
                'transactions' => [
                    [
                        'transaction' => ['id' => 1],
                        'matches' => [['id' => 2]],
                    ],
                ],
                'total' => 1,
            ]);

        $this->mapper->expects($this->once())
            ->method('linkTransactions')
            ->with(1, 2);

        $result = $this->service->bulkFindAndMatch('user1');

        $this->assertCount(1, $result['autoMatched']);
        $this->assertCount(0, $result['needsReview']);
        $this->assertEquals(1, $result['stats']['autoMatchedCount']);
    }

    public function testBulkFindAndMatchFlagsMultipleMatchesForReview(): void {
        $this->mapper->method('findUnlinkedWithMatches')
            ->willReturn([
                'transactions' => [
                    [
                        'transaction' => ['id' => 1],
                        'matches' => [['id' => 2], ['id' => 3]],
                    ],
                ],
                'total' => 1,
            ]);

        $this->mapper->expects($this->never())->method('linkTransactions');

        $result = $this->service->bulkFindAndMatch('user1');

        $this->assertCount(0, $result['autoMatched']);
        $this->assertCount(1, $result['needsReview']);
        $this->assertEquals(2, $result['needsReview'][0]['matchCount']);
    }

    public function testBulkFindAndMatchSkipsAlreadyProcessedIds(): void {
        $this->mapper->method('findUnlinkedWithMatches')
            ->willReturn([
                'transactions' => [
                    [
                        'transaction' => ['id' => 1],
                        'matches' => [['id' => 2]],
                    ],
                    [
                        'transaction' => ['id' => 2], // Already matched above
                        'matches' => [['id' => 1]],
                    ],
                ],
                'total' => 2,
            ]);

        // Should only link once (1 ↔ 2), not try again for 2 ↔ 1
        $this->mapper->expects($this->once())
            ->method('linkTransactions')
            ->with(1, 2);

        $result = $this->service->bulkFindAndMatch('user1');

        $this->assertCount(1, $result['autoMatched']);
    }

    // ===== scanForMatches() =====

    public function testScanForMatchesReturnsWithoutLinking(): void {
        $this->mapper->method('findUnlinkedWithMatches')
            ->willReturn([
                'transactions' => [
                    [
                        'transaction' => ['id' => 1],
                        'matches' => [['id' => 2]],
                    ],
                ],
                'total' => 1,
            ]);

        $this->mapper->expects($this->never())->method('linkTransactions');

        $result = $this->service->scanForMatches('user1');

        $this->assertCount(1, $result['candidates']);
        $this->assertEquals(1, $result['stats']['singleMatchCount']);
        $this->assertEquals(0, $result['stats']['multiMatchCount']);
        $this->assertEquals(1, $result['stats']['totalCandidates']);
    }

    public function testScanForMatchesCategorizesSingleAndMulti(): void {
        $this->mapper->method('findUnlinkedWithMatches')
            ->willReturn([
                'transactions' => [
                    [
                        'transaction' => ['id' => 1],
                        'matches' => [['id' => 2]],
                    ],
                    [
                        'transaction' => ['id' => 3],
                        'matches' => [['id' => 4], ['id' => 5]],
                    ],
                ],
                'total' => 2,
            ]);

        $result = $this->service->scanForMatches('user1');

        $this->assertCount(2, $result['candidates']);
        $this->assertEquals(1, $result['stats']['singleMatchCount']);
        $this->assertEquals(1, $result['stats']['multiMatchCount']);
        $this->assertEquals(2, $result['stats']['totalCandidates']);
    }

    public function testScanForMatchesDeduplicatesMirrorPairs(): void {
        $this->mapper->method('findUnlinkedWithMatches')
            ->willReturn([
                'transactions' => [
                    [
                        'transaction' => ['id' => 1],
                        'matches' => [['id' => 2]],
                    ],
                    [
                        'transaction' => ['id' => 2],
                        'matches' => [['id' => 1]],
                    ],
                ],
                'total' => 2,
            ]);

        $result = $this->service->scanForMatches('user1');

        // Should only return one candidate, not both mirror pairs
        $this->assertCount(1, $result['candidates']);
        $this->assertEquals(1, $result['stats']['totalCandidates']);
    }

    // ===== bulkLinkTransactions() =====

    public function testBulkLinkTransactionsSuccess(): void {
        $tx1 = $this->makeTransaction(['id' => 1, 'accountId' => 10, 'amount' => 100.0, 'type' => 'debit', 'linkedTransactionId' => null]);
        $tx2 = $this->makeTransaction(['id' => 2, 'accountId' => 20, 'amount' => 100.0, 'type' => 'credit', 'linkedTransactionId' => null]);

        $findCallCount = 0;
        $this->mapper->method('find')->willReturnCallback(function ($id) use ($tx1, $tx2, &$findCallCount) {
            $findCallCount++;
            // First two calls: validation finds (pair 1)
            // Next two calls: post-link re-fetches (pair 1)
            if ($id === 1) return $tx1;
            if ($id === 2) return $tx2;
            return $tx1;
        });

        $this->mapper->expects($this->once())->method('linkTransactions')->with(1, 2);

        $result = $this->service->bulkLinkTransactions('user1', [
            ['sourceId' => 1, 'targetId' => 2],
        ]);

        $this->assertCount(1, $result['linked']);
        $this->assertCount(0, $result['failed']);
        $this->assertEquals(1, $result['stats']['linkedCount']);
        $this->assertEquals(0, $result['stats']['failedCount']);
    }

    public function testBulkLinkTransactionsPartialFailure(): void {
        $tx1 = $this->makeTransaction(['id' => 1, 'accountId' => 10, 'amount' => 100.0, 'type' => 'debit', 'linkedTransactionId' => null]);
        $tx2 = $this->makeTransaction(['id' => 2, 'accountId' => 20, 'amount' => 100.0, 'type' => 'credit', 'linkedTransactionId' => null]);
        // Same account as tx3 - will cause validation failure
        $tx3 = $this->makeTransaction(['id' => 3, 'accountId' => 10, 'amount' => 50.0, 'type' => 'debit', 'linkedTransactionId' => null]);
        $tx4 = $this->makeTransaction(['id' => 4, 'accountId' => 10, 'amount' => 50.0, 'type' => 'credit', 'linkedTransactionId' => null]);

        $this->mapper->method('find')->willReturnCallback(function ($id) use ($tx1, $tx2, $tx3, $tx4) {
            return match ($id) {
                1 => $tx1,
                2 => $tx2,
                3 => $tx3,
                4 => $tx4,
                default => throw new DoesNotExistException('')
            };
        });

        $result = $this->service->bulkLinkTransactions('user1', [
            ['sourceId' => 1, 'targetId' => 2],  // Valid
            ['sourceId' => 3, 'targetId' => 4],  // Same account - will fail
        ]);

        $this->assertEquals(1, $result['stats']['linkedCount']);
        $this->assertEquals(1, $result['stats']['failedCount']);
        $this->assertCount(1, $result['failed']);
        $this->assertStringContainsString('same account', $result['failed'][0]['error']);
    }

    public function testBulkLinkTransactionsEmptyArray(): void {
        $result = $this->service->bulkLinkTransactions('user1', []);

        $this->assertCount(0, $result['linked']);
        $this->assertCount(0, $result['failed']);
        $this->assertEquals(0, $result['stats']['linkedCount']);
        $this->assertEquals(0, $result['stats']['failedCount']);
    }

    public function testBulkLinkTransactionsInvalidIds(): void {
        $result = $this->service->bulkLinkTransactions('user1', [
            ['sourceId' => 0, 'targetId' => 2],
        ]);

        $this->assertCount(0, $result['linked']);
        $this->assertCount(1, $result['failed']);
        $this->assertStringContainsString('Invalid', $result['failed'][0]['error']);
    }

    // ===== existsByImportId() =====

    public function testExistsByImportIdDelegatesToMapper(): void {
        $this->mapper->expects($this->once())
            ->method('existsByImportId')
            ->with(10, 'import-1')
            ->willReturn(true);

        $this->assertTrue($this->service->existsByImportId(10, 'import-1'));
    }
}
