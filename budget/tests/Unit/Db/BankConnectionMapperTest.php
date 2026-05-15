<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\BankConnection;
use OCA\Budget\Db\BankConnectionMapper;
use OCA\Budget\Service\EncryptionService;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class BankConnectionMapperTest extends TestCase {
    private BankConnectionMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;
    private IResult $result;
    private EncryptionService $encryptionService;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);
        $this->result = $this->createMock(IResult::class);
        $this->encryptionService = $this->createMock(EncryptionService::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('getSQL')->willReturn('');
        $this->qb->method('createNamedParameter')->willReturn(':param');

        foreach (['select', 'selectDistinct', 'selectAlias', 'from', 'where', 'andWhere', 'orderBy', 'delete', 'setMaxResults'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new BankConnectionMapper($this->db, $this->encryptionService);
    }

    private function makeConnectionRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'provider' => 'gocardless',
            'name' => 'My Bank',
            'credentials' => '{"key":"val"}',
            'status' => 'active',
            'last_sync_at' => '2026-01-15 10:00:00',
            'last_error' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_bc', $this->mapper->getTableName());
    }

    // ===== findAll =====

    public function testFindAllReturnsConnections(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeConnectionRow(['id' => 1, 'name' => 'Bank A']),
                $this->makeConnectionRow(['id' => 2, 'name' => 'Bank B']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->encryptionService->method('decrypt')
            ->willReturnArgument(0);

        $connections = $this->mapper->findAll('user1');

        $this->assertCount(2, $connections);
        $this->assertInstanceOf(BankConnection::class, $connections[0]);
        $this->assertEquals('Bank A', $connections[0]->getName());
        $this->assertEquals('Bank B', $connections[1]->getName());
    }

    // ===== findActiveForSync =====

    public function testFindActiveForSyncReturnsActiveConnections(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeConnectionRow(['id' => 1, 'status' => 'active', 'user_id' => 'user1']),
                $this->makeConnectionRow(['id' => 2, 'status' => 'active', 'user_id' => 'user2']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->encryptionService->method('decrypt')
            ->willReturnArgument(0);

        $connections = $this->mapper->findActiveForSync();

        $this->assertCount(2, $connections);
        $this->assertEquals('active', $connections[0]->getStatus());
        $this->assertEquals('user2', $connections[1]->getUserId());
    }

    public function testFindActiveForSyncReturnsEmptyWhenNoneActive(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $connections = $this->mapper->findActiveForSync();

        $this->assertEmpty($connections);
    }
}
