<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\NetWorthSnapshot;
use OCA\Budget\Db\NetWorthSnapshotMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class NetWorthSnapshotMapperTest extends TestCase {
    private NetWorthSnapshotMapper $mapper;
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

        foreach (['select', 'from', 'where', 'andWhere', 'orderBy',
                   'delete', 'setMaxResults'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new NetWorthSnapshotMapper($this->db);
    }

    private function makeSnapshotRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'total_assets' => 50000.00,
            'total_liabilities' => 10000.00,
            'net_worth' => 40000.00,
            'date' => '2026-01-31',
            'source' => 'auto',
            'created_at' => '2026-01-31 23:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_nw_snaps', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsSnapshot(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeSnapshotRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $snapshot = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(NetWorthSnapshot::class, $snapshot);
        $this->assertEquals(40000.00, $snapshot->getNetWorth());
        $this->assertEquals(50000.00, $snapshot->getTotalAssets());
        $this->assertEquals(10000.00, $snapshot->getTotalLiabilities());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findAll =====

    public function testFindAllReturnsSnapshots(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeSnapshotRow(['id' => 1, 'date' => '2026-01-31']),
                $this->makeSnapshotRow(['id' => 2, 'date' => '2026-02-28']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $snapshots = $this->mapper->findAll('user1');

        $this->assertCount(2, $snapshots);
    }

    // ===== findByDateRange =====

    public function testFindByDateRangeReturnsSnapshots(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeSnapshotRow(['date' => '2026-01-31']),
                $this->makeSnapshotRow(['date' => '2026-02-28']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $snapshots = $this->mapper->findByDateRange('user1', '2026-01-01', '2026-03-01');

        $this->assertCount(2, $snapshots);
    }

    public function testFindByDateRangeReturnsEmpty(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $snapshots = $this->mapper->findByDateRange('user1', '2099-01-01', '2099-12-31');

        $this->assertEmpty($snapshots);
    }

    // ===== findByDate =====

    public function testFindByDateReturnsSnapshot(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeSnapshotRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $snapshot = $this->mapper->findByDate('user1', '2026-01-31');

        $this->assertInstanceOf(NetWorthSnapshot::class, $snapshot);
        $this->assertEquals('2026-01-31', $snapshot->getDate());
    }

    public function testFindByDateReturnsNullWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $snapshot = $this->mapper->findByDate('user1', '2099-01-01');

        $this->assertNull($snapshot);
    }

    // ===== findLatest =====

    public function testFindLatestReturnsSnapshot(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeSnapshotRow(['date' => '2026-03-01']), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $snapshot = $this->mapper->findLatest('user1');

        $this->assertInstanceOf(NetWorthSnapshot::class, $snapshot);
        $this->assertEquals('2026-03-01', $snapshot->getDate());
    }

    public function testFindLatestReturnsNullWhenNoSnapshots(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $snapshot = $this->mapper->findLatest('user1');

        $this->assertNull($snapshot);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(5);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(5, $count);
    }
}
