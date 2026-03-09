<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\AuditLog;
use OCA\Budget\Db\AuditLogMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class AuditLogMapperTest extends TestCase {
    private AuditLogMapper $mapper;
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

        foreach (['select', 'from', 'where', 'andWhere', 'orderBy',
                   'delete', 'setMaxResults', 'setFirstResult'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new AuditLogMapper($this->db);
    }

    private function makeLogRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'action' => 'transaction_create',
            'entity_type' => 'transaction',
            'entity_id' => 42,
            'ip_address' => '192.168.1.1',
            'user_agent' => 'Mozilla/5.0',
            'details' => '{"amount": 100}',
            'created_at' => '2026-01-15 10:30:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_audit_log', $this->mapper->getTableName());
    }

    // ===== findByUser =====

    public function testFindByUserReturnsLogs(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeLogRow(['id' => 1]),
                $this->makeLogRow(['id' => 2]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $logs = $this->mapper->findByUser('user1');

        $this->assertCount(2, $logs);
        $this->assertInstanceOf(AuditLog::class, $logs[0]);
        $this->assertEquals('transaction_create', $logs[0]->getAction());
    }

    public function testFindByUserWithActionFilter(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeLogRow(['action' => 'transaction_delete']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $logs = $this->mapper->findByUser('user1', 'transaction_delete');

        $this->assertCount(1, $logs);
    }

    public function testFindByUserWithEntityTypeFilter(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeLogRow(['entity_type' => 'account']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $logs = $this->mapper->findByUser('user1', null, 'account');

        $this->assertCount(1, $logs);
    }

    public function testFindByUserWithEntityIdFilter(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeLogRow(['entity_id' => 99]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $logs = $this->mapper->findByUser('user1', null, null, 99);

        $this->assertCount(1, $logs);
    }

    public function testFindByUserWithAllFilters(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeLogRow(),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $logs = $this->mapper->findByUser('user1', 'transaction_create', 'transaction', 42, 50, 10);

        $this->assertCount(1, $logs);
    }

    public function testFindByUserReturnsEmptyArray(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $logs = $this->mapper->findByUser('user1');

        $this->assertEmpty($logs);
    }

    // ===== findByEntity =====

    public function testFindByEntityReturnsLogs(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeLogRow(['action' => 'create']),
                $this->makeLogRow(['action' => 'update']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $logs = $this->mapper->findByEntity('transaction', 42);

        $this->assertCount(2, $logs);
    }

    public function testFindByEntityReturnsEmptyArray(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $logs = $this->mapper->findByEntity('transaction', 999);

        $this->assertEmpty($logs);
    }

    // ===== countRecentActions =====

    public function testCountRecentActionsReturnsCount(): void {
        $countFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('count')->willReturn($countFunc);

        $this->result->method('fetchOne')->willReturn('5');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $count = $this->mapper->countRecentActions('user1', 'login_failed', new \DateTime('2026-01-01'));

        $this->assertEquals(5, $count);
    }

    public function testCountRecentActionsReturnsZero(): void {
        $countFunc = $this->createMock(IQueryFunction::class);
        $this->func->method('count')->willReturn($countFunc);

        $this->result->method('fetchOne')->willReturn('0');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $count = $this->mapper->countRecentActions('user1', 'login_failed', new \DateTime('2026-01-01'));

        $this->assertEquals(0, $count);
    }

    // ===== deleteOldLogs =====

    public function testDeleteOldLogsReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(10);

        $count = $this->mapper->deleteOldLogs(90);

        $this->assertEquals(10, $count);
    }

    public function testDeleteOldLogsReturnsZeroWhenNoneOld(): void {
        $this->qb->method('executeStatement')->willReturn(0);

        $count = $this->mapper->deleteOldLogs(365);

        $this->assertEquals(0, $count);
    }
}
