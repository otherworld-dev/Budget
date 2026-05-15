<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\ShareItem;
use OCA\Budget\Db\ShareItemMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ShareItemMapperTest extends TestCase {
    private ShareItemMapper $mapper;
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

        $this->mapper = new ShareItemMapper($this->db);
    }

    private function makeShareItemRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'share_id' => 10,
            'entity_type' => ShareItem::TYPE_ACCOUNT,
            'entity_id' => 5,
            'permission' => ShareItem::PERMISSION_READ,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_share_items', $this->mapper->getTableName());
    }

    // ===== findByShareId =====

    public function testFindByShareIdReturnsItems(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeShareItemRow(['id' => 1, 'entity_type' => ShareItem::TYPE_ACCOUNT, 'entity_id' => 5]),
                $this->makeShareItemRow(['id' => 2, 'entity_type' => ShareItem::TYPE_CATEGORY, 'entity_id' => 12]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $items = $this->mapper->findByShareId(10);

        $this->assertCount(2, $items);
        $this->assertInstanceOf(ShareItem::class, $items[0]);
        $this->assertEquals(ShareItem::TYPE_ACCOUNT, $items[0]->getEntityType());
        $this->assertEquals(ShareItem::TYPE_CATEGORY, $items[1]->getEntityType());
    }

    // ===== findSharedEntityIds =====

    public function testFindSharedEntityIdsReturnsIntArray(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                ['entity_id' => '5'],
                ['entity_id' => '12'],
                ['entity_id' => '30'],
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $ids = $this->mapper->findSharedEntityIds(10, ShareItem::TYPE_ACCOUNT);

        $this->assertEquals([5, 12, 30], $ids);
    }

    public function testFindSharedEntityIdsReturnsEmptyWhenNone(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $ids = $this->mapper->findSharedEntityIds(10, ShareItem::TYPE_ACCOUNT);

        $this->assertEmpty($ids);
    }

    // ===== getEntityPermission =====

    public function testGetEntityPermissionReturnsPermissionString(): void {
        $this->result->method('fetchOne')->willReturn('read');
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $permission = $this->mapper->getEntityPermission(10, ShareItem::TYPE_ACCOUNT, 5);

        $this->assertEquals('read', $permission);
    }

    public function testGetEntityPermissionReturnsNullWhenNotFound(): void {
        $this->result->method('fetchOne')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $permission = $this->mapper->getEntityPermission(10, ShareItem::TYPE_ACCOUNT, 999);

        $this->assertNull($permission);
    }

    // ===== deleteByShareId =====

    public function testDeleteByShareIdCallsExecuteStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $this->mapper->deleteByShareId(10);
    }
}
