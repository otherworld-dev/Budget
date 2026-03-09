<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\PensionAccount;
use OCA\Budget\Db\PensionAccountMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class PensionAccountMapperTest extends TestCase {
    private PensionAccountMapper $mapper;
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

        foreach (['select', 'from', 'where', 'andWhere', 'orderBy',
                   'delete'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new PensionAccountMapper($this->db);
    }

    private function makePensionRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'name' => 'Work Pension',
            'provider' => 'Aviva',
            'type' => 'workplace',
            'currency' => 'GBP',
            'current_balance' => 25000.00,
            'monthly_contribution' => 500.00,
            'expected_return_rate' => 5.0,
            'retirement_age' => 67,
            'annual_income' => null,
            'transfer_value' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_pensions', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsPension(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makePensionRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $pension = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(PensionAccount::class, $pension);
        $this->assertEquals('Work Pension', $pension->getName());
        $this->assertEquals('workplace', $pension->getType());
        $this->assertEquals(25000.00, $pension->getCurrentBalance());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findAll =====

    public function testFindAllReturnsPensions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makePensionRow(['id' => 1, 'name' => 'Work Pension']),
                $this->makePensionRow(['id' => 2, 'name' => 'Personal SIPP']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $pensions = $this->mapper->findAll('user1');

        $this->assertCount(2, $pensions);
    }

    // ===== findByType =====

    public function testFindByTypeReturnsPensions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makePensionRow(['type' => 'workplace']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $pensions = $this->mapper->findByType('user1', 'workplace');

        $this->assertCount(1, $pensions);
        $this->assertEquals('workplace', $pensions[0]->getType());
    }

    // ===== findDefinedContribution =====

    public function testFindDefinedContributionReturnsDCPensions(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makePensionRow(['id' => 1, 'type' => 'workplace']),
                $this->makePensionRow(['id' => 2, 'type' => 'personal']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $pensions = $this->mapper->findDefinedContribution('user1');

        $this->assertCount(2, $pensions);
    }

    // ===== getTotalBalance =====

    public function testGetTotalBalanceReturnsSum(): void {
        $this->result->method('fetchOne')->willReturn('75000.50');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $total = $this->mapper->getTotalBalance('user1');

        $this->assertEquals(75000.50, $total);
    }

    public function testGetTotalBalanceReturnsZeroWhenNull(): void {
        $this->result->method('fetchOne')->willReturn(null);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $total = $this->mapper->getTotalBalance('user1');

        $this->assertEquals(0.0, $total);
    }

    // ===== getTotalTransferValue =====

    public function testGetTotalTransferValueReturnsSum(): void {
        $this->result->method('fetchOne')->willReturn('120000.00');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $total = $this->mapper->getTotalTransferValue('user1');

        $this->assertEquals(120000.00, $total);
    }

    public function testGetTotalTransferValueReturnsZeroWhenNull(): void {
        $this->result->method('fetchOne')->willReturn(null);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $total = $this->mapper->getTotalTransferValue('user1');

        $this->assertEquals(0.0, $total);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(3);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(3, $count);
    }
}
