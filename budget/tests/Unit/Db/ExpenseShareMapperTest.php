<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\ExpenseShare;
use OCA\Budget\Db\ExpenseShareMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ExpenseShareMapperTest extends TestCase {
    private ExpenseShareMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;
    private IResult $result;
    private IFunctionBuilder $func;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);
        $this->result = $this->createMock(IResult::class);
        $this->func = $this->createMock(IFunctionBuilder::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('func')->willReturn($this->func);
        $this->qb->method('getSQL')->willReturn('');
        $this->qb->method('createNamedParameter')->willReturn(':param');

        $sumFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('sum')->willReturn($sumFunc);

        foreach (['select', 'selectAlias', 'from', 'where', 'andWhere',
                   'orderBy', 'delete', 'groupBy'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new ExpenseShareMapper($this->db);
    }

    private function makeShareRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'transaction_id' => 100,
            'contact_id' => 5,
            'amount' => 25.50,
            'is_settled' => 0,
            'notes' => null,
            'created_at' => '2026-01-15 12:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_expense_shares', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsShare(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeShareRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $share = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(ExpenseShare::class, $share);
        $this->assertEquals(25.50, $share->getAmount());
        $this->assertEquals(100, $share->getTransactionId());
        $this->assertEquals(5, $share->getContactId());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findAll =====

    public function testFindAllReturnsShares(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeShareRow(['id' => 1]),
                $this->makeShareRow(['id' => 2]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $shares = $this->mapper->findAll('user1');

        $this->assertCount(2, $shares);
    }

    // ===== findByTransaction =====

    public function testFindByTransactionReturnsShares(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeShareRow(['contact_id' => 5]),
                $this->makeShareRow(['contact_id' => 6]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $shares = $this->mapper->findByTransaction(100, 'user1');

        $this->assertCount(2, $shares);
    }

    // ===== findByContact =====

    public function testFindByContactReturnsShares(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeShareRow(['transaction_id' => 100]),
                $this->makeShareRow(['transaction_id' => 101]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $shares = $this->mapper->findByContact(5, 'user1');

        $this->assertCount(2, $shares);
    }

    // ===== findUnsettled =====

    public function testFindUnsettledReturnsUnsettledShares(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeShareRow(['is_settled' => 0]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $shares = $this->mapper->findUnsettled('user1');

        $this->assertCount(1, $shares);
    }

    public function testFindUnsettledReturnsEmpty(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $shares = $this->mapper->findUnsettled('user1');

        $this->assertEmpty($shares);
    }

    // ===== findUnsettledByContact =====

    public function testFindUnsettledByContactReturnsShares(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeShareRow(['is_settled' => 0, 'contact_id' => 5]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $shares = $this->mapper->findUnsettledByContact(5, 'user1');

        $this->assertCount(1, $shares);
    }

    // ===== deleteByTransaction =====

    public function testDeleteByTransactionExecutesStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $this->mapper->deleteByTransaction(100, 'user1');
    }

    // ===== getBalancesByContact =====

    public function testGetBalancesByContactReturnsBalances(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['contact_id' => '5', 'balance' => '75.50'],
                ['contact_id' => '8', 'balance' => '-30.00'],
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $balances = $this->mapper->getBalancesByContact('user1');

        $this->assertArrayHasKey(5, $balances);
        $this->assertArrayHasKey(8, $balances);
        $this->assertEquals(75.50, $balances[5]);
        $this->assertEquals(-30.00, $balances[8]);
    }

    public function testGetBalancesByContactReturnsEmptyWhenNone(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $balances = $this->mapper->getBalancesByContact('user1');

        $this->assertEmpty($balances);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(7);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(7, $count);
    }
}
