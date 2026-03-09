<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Account;
use OCA\Budget\Db\AccountMapper;
use OCA\Budget\Service\EncryptionService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class AccountMapperTest extends TestCase {
    private AccountMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;
    private IFunctionBuilder $func;
    private IResult $result;
    private EncryptionService $encryptionService;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);
        $this->func = $this->createMock(IFunctionBuilder::class);
        $this->result = $this->createMock(IResult::class);
        $this->encryptionService = $this->createMock(EncryptionService::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('func')->willReturn($this->func);
        $this->qb->method('getSQL')->willReturn('');
        $this->qb->method('createNamedParameter')->willReturn(':param');

        foreach (['select', 'selectAlias', 'from', 'where', 'andWhere',
                   'orderBy', 'insert', 'delete', 'update', 'set',
                   'setValue'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        // Encryption: pass through by default
        $this->encryptionService->method('encrypt')->willReturnCallback(fn($v) => "enc:{$v}");
        $this->encryptionService->method('decrypt')->willReturnCallback(function ($v) {
            return str_starts_with($v, 'enc:') ? substr($v, 4) : $v;
        });
        $this->encryptionService->method('isEncrypted')->willReturnCallback(
            fn($v) => str_starts_with($v, 'enc:')
        );

        $this->mapper = new AccountMapper($this->db, $this->encryptionService);
    }

    private function makeAccountRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'name' => 'Checking',
            'type' => 'checking',
            'balance' => 1000.00,
            'opening_balance' => 1000.00,
            'currency' => 'USD',
            'institution' => 'Chase',
            'account_number' => null,
            'routing_number' => null,
            'sort_code' => null,
            'iban' => null,
            'swift_bic' => null,
            'wallet_address' => null,
            'account_holder_name' => 'John Doe',
            'opening_date' => '2025-01-01',
            'interest_rate' => null,
            'credit_limit' => null,
            'overdraft_limit' => null,
            'minimum_payment' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_accounts', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsDecryptedAccount(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeAccountRow(['account_number' => 'enc:12345678']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $account = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(Account::class, $account);
        $this->assertEquals('Checking', $account->getName());
        // Account number should be decrypted
        $this->assertEquals('12345678', $account->getAccountNumber());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findAll =====

    public function testFindAllReturnsDecryptedAccounts(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeAccountRow(['id' => 1, 'name' => 'Checking', 'iban' => 'enc:DE89370400440532013000']),
                $this->makeAccountRow(['id' => 2, 'name' => 'Savings', 'iban' => null]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $accounts = $this->mapper->findAll('user1');

        $this->assertCount(2, $accounts);
        $this->assertEquals('Checking', $accounts[0]->getName());
        $this->assertEquals('DE89370400440532013000', $accounts[0]->getIban());
        $this->assertNull($accounts[1]->getIban());
    }

    public function testFindAllReturnsEmptyForNoAccounts(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $accounts = $this->mapper->findAll('user1');

        $this->assertEmpty($accounts);
    }

    // ===== getTotalBalance =====

    public function testGetTotalBalanceReturnsSumForUser(): void {
        $sumFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('sum')->willReturn($sumFunc);
        $this->result->method('fetchOne')->willReturn('2500.50');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $total = $this->mapper->getTotalBalance('user1');

        $this->assertEquals(2500.50, $total);
    }

    public function testGetTotalBalanceReturnsZeroForNoAccounts(): void {
        $sumFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('sum')->willReturn($sumFunc);
        $this->result->method('fetchOne')->willReturn(null);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $total = $this->mapper->getTotalBalance('user1');

        $this->assertEquals(0.0, $total);
    }

    public function testGetTotalBalanceWithCurrencyFilter(): void {
        $sumFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('sum')->willReturn($sumFunc);
        $this->result->method('fetchOne')->willReturn('1000.00');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $total = $this->mapper->getTotalBalance('user1', 'EUR');

        $this->assertEquals(1000.00, $total);
    }

    public function testGetTotalBalanceReturnsZeroForNullWithCurrency(): void {
        $sumFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('sum')->willReturn($sumFunc);
        $this->result->method('fetchOne')->willReturn(null);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $total = $this->mapper->getTotalBalance('user1', 'EUR');

        $this->assertEquals(0.0, $total);
    }

    // ===== updateBalance =====

    public function testUpdateBalanceExecutesAndReturnsAccount(): void {
        // updateBalance calls executeStatement then find()
        $this->qb->method('executeStatement')->willReturn(1);

        // find() called after update
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeAccountRow(['balance' => 1500.00]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $account = $this->mapper->updateBalance(1, 1500.00, 'user1');

        $this->assertInstanceOf(Account::class, $account);
    }

    public function testUpdateBalanceAcceptsStringBalance(): void {
        $this->qb->method('executeStatement')->willReturn(1);
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeAccountRow(['balance' => 1234.56]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $account = $this->mapper->updateBalance(1, '1234.56', 'user1');

        $this->assertInstanceOf(Account::class, $account);
    }

    // ===== insert (encryption) =====

    public function testInsertEncryptsSensitiveFields(): void {
        $account = new Account();
        $account->setUserId('user1');
        $account->setName('Test');
        $account->setType('checking');
        $account->setBalance(0.0);
        $account->setCurrency('USD');
        $account->setAccountNumber('12345678');

        // Insert calls encrypt, then parent::insert, then decrypt
        $this->encryptionService->expects($this->atLeastOnce())->method('encrypt');
        $this->encryptionService->expects($this->atLeastOnce())->method('decrypt');

        $this->qb->method('executeStatement')->willReturn(1);
        $this->qb->method('getLastInsertId')->willReturn(1);

        $result = $this->mapper->insert($account);

        $this->assertInstanceOf(Account::class, $result);
    }

    public function testInsertWithNullSensitiveFieldsSkipsEncryption(): void {
        $account = new Account();
        $account->setUserId('user1');
        $account->setName('Basic');
        $account->setType('checking');
        $account->setBalance(0.0);
        $account->setCurrency('USD');
        // No sensitive fields set

        $this->qb->method('executeStatement')->willReturn(1);
        $this->qb->method('getLastInsertId')->willReturn(1);

        $result = $this->mapper->insert($account);

        $this->assertInstanceOf(Account::class, $result);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(3);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(3, $count);
    }

    public function testDeleteAllReturnsZeroForNoAccounts(): void {
        $this->qb->method('executeStatement')->willReturn(0);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(0, $count);
    }
}
