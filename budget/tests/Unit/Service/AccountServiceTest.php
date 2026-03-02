<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Db\TransactionMapper;
use OCA\Budget\Service\AccountService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

class AccountServiceTest extends TestCase {
    private AccountService $service;
    private AccountMapper $accountMapper;
    private TransactionMapper $transactionMapper;

    protected function setUp(): void {
        $this->accountMapper = $this->createMock(AccountMapper::class);
        $this->transactionMapper = $this->createMock(TransactionMapper::class);

        $this->service = new AccountService(
            $this->accountMapper,
            $this->transactionMapper
        );
    }

    private function makeAccount(array $overrides = []): Account {
        $account = new Account();
        $defaults = [
            'id' => 1,
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

    // ===== create() =====

    public function testCreateSetsRequiredFields(): void {
        $this->accountMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Account $account) {
                $this->assertEquals('user1', $account->getUserId());
                $this->assertEquals('Savings', $account->getName());
                $this->assertEquals('savings', $account->getType());
                $this->assertEquals(500.00, $account->getBalance());
                $this->assertEquals('EUR', $account->getCurrency());
                $account->setId(1);
                return $account;
            });

        $result = $this->service->create('user1', 'Savings', 'savings', 500.00, 'EUR');

        $this->assertEquals('Savings', $result->getName());
    }

    public function testCreateSetsOptionalFields(): void {
        $this->accountMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Account $account) {
                $this->assertEquals('Chase', $account->getInstitution());
                $this->assertEquals('12345678', $account->getAccountNumber());
                $this->assertEquals('021000021', $account->getRoutingNumber());
                $this->assertEquals('12-34-56', $account->getSortCode());
                $this->assertEquals('DE89370400440532013000', $account->getIban());
                $this->assertEquals('DEUTDEFF', $account->getSwiftBic());
                $this->assertEquals('John Doe', $account->getAccountHolderName());
                $this->assertEquals('2020-01-01', $account->getOpeningDate());
                $this->assertEquals(1.5, $account->getInterestRate());
                $this->assertEquals(5000.00, $account->getCreditLimit());
                $this->assertEquals(200.00, $account->getOverdraftLimit());
                $this->assertEquals(25.00, $account->getMinimumPayment());
                $this->assertEquals('0xabc123', $account->getWalletAddress());
                $account->setId(1);
                return $account;
            });

        $this->service->create(
            'user1', 'Full Account', 'checking', 0.0, 'USD',
            'Chase', '12345678', '021000021', '12-34-56',
            'DE89370400440532013000', 'DEUTDEFF', 'John Doe',
            '2020-01-01', 1.5, 5000.00, 200.00, 25.00, '0xabc123'
        );
    }

    public function testCreateUsesDefaults(): void {
        $this->accountMapper->expects($this->once())
            ->method('insert')
            ->willReturnCallback(function (Account $account) {
                $this->assertEquals(0.0, $account->getBalance());
                $this->assertEquals('USD', $account->getCurrency());
                $account->setId(1);
                return $account;
            });

        $this->service->create('user1', 'Basic', 'checking');
    }

    // ===== find() (inherited from AbstractCrudService) =====

    public function testFindDelegatesToMapper(): void {
        $account = $this->makeAccount();
        $this->accountMapper->expects($this->once())
            ->method('find')
            ->with(1, 'user1')
            ->willReturn($account);

        $result = $this->service->find(1, 'user1');
        $this->assertSame($account, $result);
    }

    // ===== delete() (with beforeDelete hook) =====

    public function testDeletePreventsWhenTransactionsExist(): void {
        $account = $this->makeAccount();
        $this->accountMapper->method('find')->willReturn($account);

        $this->transactionMapper->method('findByAccount')
            ->with(1, 1)
            ->willReturn([['id' => 1]]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('existing transactions');

        $this->service->delete(1, 'user1');
    }

    public function testDeleteSucceedsWhenNoTransactions(): void {
        $account = $this->makeAccount();
        $this->accountMapper->method('find')->willReturn($account);

        $this->transactionMapper->method('findByAccount')
            ->with(1, 1)
            ->willReturn([]);

        $this->accountMapper->expects($this->once())
            ->method('delete')
            ->with($account);

        $this->service->delete(1, 'user1');
    }

    // ===== findWithCurrentBalance() =====

    public function testFindWithCurrentBalanceAdjustsForFutureTransactions(): void {
        $account = $this->makeAccount(['balance' => 1000.00]);
        $this->accountMapper->method('find')->willReturn($account);

        // Future transactions add 200 net (e.g., $300 credit - $100 debit in the future)
        $this->transactionMapper->method('getNetChangeAfterDate')
            ->willReturn(200.0);

        $result = $this->service->findWithCurrentBalance(1, 'user1');

        // Current balance = stored (1000) - future change (200) = 800
        $this->assertEquals(800.0, $result['balance']);
        $this->assertEquals('Checking', $result['name']);
    }

    public function testFindWithCurrentBalanceNoFutureTransactions(): void {
        $account = $this->makeAccount(['balance' => 500.00]);
        $this->accountMapper->method('find')->willReturn($account);

        $this->transactionMapper->method('getNetChangeAfterDate')->willReturn(0.0);

        $result = $this->service->findWithCurrentBalance(1, 'user1');

        $this->assertEquals(500.0, $result['balance']);
    }

    // ===== findAllWithCurrentBalances() =====

    public function testFindAllWithCurrentBalancesAdjustsBatch(): void {
        $accounts = [
            $this->makeAccount(['id' => 1, 'balance' => 1000.00]),
            $this->makeAccount(['id' => 2, 'balance' => 500.00]),
        ];
        $this->accountMapper->method('findAll')->willReturn($accounts);

        $this->transactionMapper->method('getNetChangeAfterDateBatch')
            ->willReturn([
                1 => 100.0,  // Account 1 has 100 in future
                // Account 2 has no future transactions
            ]);

        $result = $this->service->findAllWithCurrentBalances('user1');

        $this->assertCount(2, $result);
        $this->assertEquals(900.0, $result[0]['balance']);  // 1000 - 100
        $this->assertEquals(500.0, $result[1]['balance']);   // 500 - 0
    }

    // ===== getSummary() =====

    public function testGetSummarySingleCurrency(): void {
        $accounts = [
            $this->makeAccount(['id' => 1, 'balance' => 1000.00, 'currency' => 'USD']),
            $this->makeAccount(['id' => 2, 'balance' => 500.00, 'currency' => 'USD']),
        ];
        $this->accountMapper->method('findAll')->willReturn($accounts);
        $this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

        $result = $this->service->getSummary('user1');

        $this->assertEquals(1500.0, $result['totalBalance']);
        $this->assertEquals(2, $result['accountCount']);
        $this->assertArrayHasKey('USD', $result['currencyBreakdown']);
        $this->assertEquals(1500.0, $result['currencyBreakdown']['USD']);
    }

    public function testGetSummaryMultiCurrency(): void {
        $accounts = [
            $this->makeAccount(['id' => 1, 'balance' => 1000.00, 'currency' => 'USD']),
            $this->makeAccount(['id' => 2, 'balance' => 800.00, 'currency' => 'EUR']),
        ];
        $this->accountMapper->method('findAll')->willReturn($accounts);
        $this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

        $result = $this->service->getSummary('user1');

        $this->assertEquals(1800.0, $result['totalBalance']);
        $this->assertEquals(1000.0, $result['currencyBreakdown']['USD']);
        $this->assertEquals(800.0, $result['currencyBreakdown']['EUR']);
    }

    public function testGetSummaryWithFutureAdjustment(): void {
        $accounts = [
            $this->makeAccount(['id' => 1, 'balance' => 1000.00]),
        ];
        $this->accountMapper->method('findAll')->willReturn($accounts);
        $this->transactionMapper->method('getNetChangeAfterDateBatch')
            ->willReturn([1 => 200.0]);

        $result = $this->service->getSummary('user1');

        // Stored balance 1000, future change 200, so current = 800
        $this->assertEquals(800.0, $result['totalBalance']);
        $this->assertEquals(800.0, $result['accounts'][0]['balance']);
    }

    public function testGetSummaryEmptyAccounts(): void {
        $this->accountMapper->method('findAll')->willReturn([]);
        $this->transactionMapper->method('getNetChangeAfterDateBatch')->willReturn([]);

        $result = $this->service->getSummary('user1');

        $this->assertEquals(0.0, $result['totalBalance']);
        $this->assertEquals(0, $result['accountCount']);
        $this->assertEmpty($result['currencyBreakdown']);
    }

    // ===== getBalanceHistory() =====

    public function testGetBalanceHistoryWorksBackwardsFromCurrentBalance(): void {
        $account = $this->makeAccount(['balance' => 1000.00]);
        $this->accountMapper->method('find')->willReturn($account);

        $today = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));

        $this->transactionMapper->method('getDailyBalanceChanges')
            ->willReturn([
                $today => 100.0,       // Today +100 was applied
                $yesterday => -50.0,   // Yesterday -50 was applied
            ]);

        $result = $this->service->getBalanceHistory(1, 'user1', 3);

        // Working backwards from 1000 (current stored balance):
        // i=0 (today):    1000 - 100 = 900 (opening balance of today)
        // i=1 (yesterday): 900 - (-50) = 950 (opening balance of yesterday)
        // i=2 (2 days ago): 950 (no changes, opening balance of 2 days ago)
        $this->assertCount(3, $result);

        // Result is reversed so earliest date first
        $this->assertEquals(950.0, $result[0]['balance']);  // 2 days ago
        $this->assertEquals(950.0, $result[1]['balance']);  // yesterday
        $this->assertEquals(900.0, $result[2]['balance']);  // today
    }

    public function testGetBalanceHistoryNoDailyChanges(): void {
        $account = $this->makeAccount(['balance' => 500.00]);
        $this->accountMapper->method('find')->willReturn($account);
        $this->transactionMapper->method('getDailyBalanceChanges')->willReturn([]);

        $result = $this->service->getBalanceHistory(1, 'user1', 5);

        $this->assertCount(5, $result);
        // All days should have the same balance since no changes
        foreach ($result as $entry) {
            $this->assertEquals(500.0, $entry['balance']);
        }
    }

    // ===== reconcile() =====

    public function testReconcileBalanced(): void {
        $account = $this->makeAccount(['balance' => 1000.00]);
        $this->accountMapper->method('find')->willReturn($account);

        $result = $this->service->reconcile(1, 'user1', 1000.00);

        $this->assertEquals(1000.0, $result['currentBalance']);
        $this->assertEquals(1000.0, $result['statementBalance']);
        $this->assertEquals(0.0, $result['difference']);
        $this->assertTrue($result['isBalanced']);
    }

    public function testReconcileWithinTolerance(): void {
        $account = $this->makeAccount(['balance' => 1000.00]);
        $this->accountMapper->method('find')->willReturn($account);

        $result = $this->service->reconcile(1, 'user1', 1000.005);

        $this->assertTrue($result['isBalanced']);
    }

    public function testReconcileOutOfBalance(): void {
        $account = $this->makeAccount(['balance' => 1000.00]);
        $this->accountMapper->method('find')->willReturn($account);

        $result = $this->service->reconcile(1, 'user1', 990.00);

        $this->assertEquals(-10.0, $result['difference']);
        $this->assertFalse($result['isBalanced']);
    }
}
