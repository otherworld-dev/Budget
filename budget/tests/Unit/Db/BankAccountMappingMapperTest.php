<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\BankAccountMapping;
use OCA\Budget\Db\BankAccountMappingMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class BankAccountMappingMapperTest extends TestCase {
    private BankAccountMappingMapper $mapper;
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
        $this->expr->method('isNotNull')->willReturn('budget_account_id IS NOT NULL');

        $this->mapper = new BankAccountMappingMapper($this->db);
    }

    private function makeMappingRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'connection_id' => 10,
            'external_account_id' => 'ext-123',
            'external_account_name' => 'Checking Account',
            'budget_account_id' => 5,
            'enabled' => 1,
            'requisition_id' => 'req-abc',
            'consent_expires' => '2026-06-01',
            'last_balance' => '1500.00',
            'last_currency' => 'EUR',
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_bam', $this->mapper->getTableName());
    }

    // ===== findByConnection =====

    public function testFindByConnectionReturnsMappings(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeMappingRow(['id' => 1, 'external_account_name' => 'Checking']),
                $this->makeMappingRow(['id' => 2, 'external_account_name' => 'Savings']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $mappings = $this->mapper->findByConnection(10);

        $this->assertCount(2, $mappings);
        $this->assertInstanceOf(BankAccountMapping::class, $mappings[0]);
        $this->assertEquals('Checking', $mappings[0]->getExternalAccountName());
        $this->assertEquals('Savings', $mappings[1]->getExternalAccountName());
    }

    // ===== findEnabledByConnection =====

    public function testFindEnabledByConnectionReturnsOnlyEnabled(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeMappingRow(['id' => 1, 'enabled' => 1, 'budget_account_id' => 5]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $mappings = $this->mapper->findEnabledByConnection(10);

        $this->assertCount(1, $mappings);
        $this->assertEquals(5, $mappings[0]->getBudgetAccountId());
    }

    // ===== findByExternalId =====

    public function testFindByExternalIdReturnsNullWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $mapping = $this->mapper->findByExternalId(10, 'nonexistent-ext-id');

        $this->assertNull($mapping);
    }

    public function testFindByExternalIdReturnsMapping(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeMappingRow(['external_account_id' => 'ext-999']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $mapping = $this->mapper->findByExternalId(10, 'ext-999');

        $this->assertInstanceOf(BankAccountMapping::class, $mapping);
        $this->assertEquals('ext-999', $mapping->getExternalAccountId());
    }

    // ===== deleteByConnection =====

    public function testDeleteByConnectionCallsExecuteStatement(): void {
        $this->qb->expects($this->once())->method('executeStatement');

        $this->mapper->deleteByConnection(10);
    }
}
