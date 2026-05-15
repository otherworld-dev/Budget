<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Share;
use OCA\Budget\Db\ShareMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ShareMapperTest extends TestCase {
    private ShareMapper $mapper;
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

        foreach (['select', 'selectDistinct', 'selectAlias', 'from', 'where', 'andWhere', 'orderBy', 'delete', 'setMaxResults'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new ShareMapper($this->db);
    }

    private function makeShareRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'owner_user_id' => 'alice',
            'shared_with_user_id' => 'bob',
            'status' => Share::STATUS_ACCEPTED,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_shares', $this->mapper->getTableName());
    }

    // ===== findByOwner =====

    public function testFindByOwnerReturnsShares(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeShareRow(['id' => 1, 'shared_with_user_id' => 'bob']),
                $this->makeShareRow(['id' => 2, 'shared_with_user_id' => 'carol']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $shares = $this->mapper->findByOwner('alice');

        $this->assertCount(2, $shares);
        $this->assertInstanceOf(Share::class, $shares[0]);
        $this->assertEquals('bob', $shares[0]->getSharedWithUserId());
        $this->assertEquals('carol', $shares[1]->getSharedWithUserId());
    }

    // ===== findByRecipient =====

    public function testFindByRecipientReturnsShares(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeShareRow(['id' => 1, 'owner_user_id' => 'alice']),
                $this->makeShareRow(['id' => 2, 'owner_user_id' => 'dave']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $shares = $this->mapper->findByRecipient('bob');

        $this->assertCount(2, $shares);
        $this->assertEquals('alice', $shares[0]->getOwnerUserId());
        $this->assertEquals('dave', $shares[1]->getOwnerUserId());
    }

    // ===== findAcceptedOwnerIds =====

    public function testFindAcceptedOwnerIdsReturnsOwnerUserIds(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['owner_user_id' => 'alice'],
                ['owner_user_id' => 'dave'],
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $ownerIds = $this->mapper->findAcceptedOwnerIds('bob');

        $this->assertEquals(['alice', 'dave'], $ownerIds);
    }

    public function testFindAcceptedOwnerIdsReturnsEmptyWhenNone(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $ownerIds = $this->mapper->findAcceptedOwnerIds('bob');

        $this->assertEmpty($ownerIds);
    }

    // ===== findPendingForRecipient =====

    public function testFindPendingForRecipientReturnsPendingShares(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeShareRow(['id' => 3, 'status' => Share::STATUS_PENDING, 'owner_user_id' => 'eve']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $shares = $this->mapper->findPendingForRecipient('bob');

        $this->assertCount(1, $shares);
        $this->assertEquals('eve', $shares[0]->getOwnerUserId());
        $this->assertEquals(Share::STATUS_PENDING, $shares[0]->getStatus());
    }
}
