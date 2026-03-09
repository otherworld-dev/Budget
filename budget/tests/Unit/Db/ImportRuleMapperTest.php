<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\ImportRule;
use OCA\Budget\Db\ImportRuleMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ImportRuleMapperTest extends TestCase {
    private ImportRuleMapper $mapper;
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
                   'addOrderBy', 'delete'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new ImportRuleMapper($this->db);
    }

    private function makeRuleRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'name' => 'Coffee Rule',
            'pattern' => 'starbucks',
            'field' => 'description',
            'match_type' => 'contains',
            'category_id' => 5,
            'vendor_name' => 'Starbucks',
            'priority' => 10,
            'active' => 1,
            'actions' => null,
            'apply_on_import' => 1,
            'criteria' => null,
            'schema_version' => 1,
            'stop_processing' => 0,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_import_rules', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsRule(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeRuleRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $rule = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(ImportRule::class, $rule);
        $this->assertEquals('Coffee Rule', $rule->getName());
        $this->assertEquals(5, $rule->getCategoryId());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findAll =====

    public function testFindAllReturnsRules(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeRuleRow(['id' => 1, 'name' => 'Rule A', 'priority' => 20]),
                $this->makeRuleRow(['id' => 2, 'name' => 'Rule B', 'priority' => 10]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $rules = $this->mapper->findAll('user1');

        $this->assertCount(2, $rules);
        $this->assertEquals('Rule A', $rules[0]->getName());
    }

    // ===== findActive =====

    public function testFindActiveReturnsActiveRules(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeRuleRow(['active' => 1]),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $rules = $this->mapper->findActive('user1');

        $this->assertCount(1, $rules);
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(4);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(4, $count);
    }
}
