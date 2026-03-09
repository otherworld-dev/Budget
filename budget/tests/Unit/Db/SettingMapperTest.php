<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Setting;
use OCA\Budget\Db\SettingMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class SettingMapperTest extends TestCase {
    private SettingMapper $mapper;
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

        // Fluent query builder methods
        foreach (['select', 'from', 'where', 'andWhere', 'orderBy',
                   'delete', 'update', 'set'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new SettingMapper($this->db);
    }

    private function makeSettingRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'key' => 'currency',
            'value' => 'USD',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_settings', $this->mapper->getTableName());
    }

    // ===== findAll =====

    public function testFindAllReturnsSettings(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeSettingRow(['id' => 1, 'key' => 'currency', 'value' => 'USD']),
                $this->makeSettingRow(['id' => 2, 'key' => 'theme', 'value' => 'dark']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $settings = $this->mapper->findAll('user1');

        $this->assertCount(2, $settings);
        $this->assertInstanceOf(Setting::class, $settings[0]);
        $this->assertEquals('currency', $settings[0]->getKey());
        $this->assertEquals('USD', $settings[0]->getValue());
        $this->assertEquals('theme', $settings[1]->getKey());
    }

    public function testFindAllReturnsEmptyForNoSettings(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $settings = $this->mapper->findAll('user1');

        $this->assertEmpty($settings);
    }

    // ===== findByKey =====

    public function testFindByKeyReturnsSetting(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeSettingRow(['key' => 'currency', 'value' => 'EUR']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $setting = $this->mapper->findByKey('user1', 'currency');

        $this->assertInstanceOf(Setting::class, $setting);
        $this->assertEquals('currency', $setting->getKey());
        $this->assertEquals('EUR', $setting->getValue());
    }

    public function testFindByKeyThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->findByKey('user1', 'nonexistent');
    }

    // ===== deleteByKey =====

    public function testDeleteByKeyReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(1);

        $count = $this->mapper->deleteByKey('user1', 'currency');

        $this->assertEquals(1, $count);
    }

    public function testDeleteByKeyReturnsZeroWhenNotFound(): void {
        $this->qb->method('executeStatement')->willReturn(0);

        $count = $this->mapper->deleteByKey('user1', 'nonexistent');

        $this->assertEquals(0, $count);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(5);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(5, $count);
    }

    public function testDeleteAllReturnsZeroForNoSettings(): void {
        $this->qb->method('executeStatement')->willReturn(0);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(0, $count);
    }
}
