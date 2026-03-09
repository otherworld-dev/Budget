<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Auth;
use OCA\Budget\Db\AuthMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class AuthMapperTest extends TestCase {
    private AuthMapper $mapper;
    private IDBConnection $db;
    private IQueryBuilder $qb;
    private IExpressionBuilder $expr;
    private IResult $result;

    protected function setUp(): void {
        $this->db = $this->createMock(IDBConnection::class);
        $this->qb = $this->createMock(IQueryBuilder::class);
        $this->expr = $this->createMock(IExpressionBuilder::class);
        $this->result = $this->createMock(IResult::class);

        $this->db->method('getQueryBuilder')->willReturn($this->qb);
        $this->qb->method('expr')->willReturn($this->expr);
        $this->qb->method('getSQL')->willReturn('');
        $this->qb->method('createNamedParameter')->willReturn(':param');

        foreach (['select', 'from', 'where', 'andWhere', 'delete'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new AuthMapper($this->db);
    }

    private function makeAuthRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'password_hash' => '$2y$10$hashedpasswordhere',
            'session_token' => 'abc123token',
            'session_expires_at' => '2026-01-15 11:00:00',
            'failed_attempts' => 0,
            'locked_until' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_auth', $this->mapper->getTableName());
    }

    // ===== findByUserId =====

    public function testFindByUserIdReturnsAuth(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeAuthRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $auth = $this->mapper->findByUserId('user1');

        $this->assertInstanceOf(Auth::class, $auth);
        $this->assertEquals('user1', $auth->getUserId());
        $this->assertEquals(0, $auth->getFailedAttempts());
    }

    public function testFindByUserIdThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->findByUserId('nonexistent');
    }

    // ===== findBySessionToken =====

    public function testFindBySessionTokenReturnsAuth(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeAuthRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $auth = $this->mapper->findBySessionToken('abc123token');

        $this->assertInstanceOf(Auth::class, $auth);
        $this->assertEquals('abc123token', $auth->getSessionToken());
    }

    public function testFindBySessionTokenThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->findBySessionToken('invalidtoken');
    }

    // ===== deleteByUserId =====

    public function testDeleteByUserIdReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(1);

        $count = $this->mapper->deleteByUserId('user1');

        $this->assertEquals(1, $count);
    }

    public function testDeleteByUserIdReturnsZeroWhenNoMatch(): void {
        $this->qb->method('executeStatement')->willReturn(0);

        $count = $this->mapper->deleteByUserId('nonexistent');

        $this->assertEquals(0, $count);
    }
}
