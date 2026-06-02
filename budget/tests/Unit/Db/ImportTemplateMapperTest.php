<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\ImportTemplate;
use OCA\Budget\Db\ImportTemplateMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ImportTemplateMapperTest extends TestCase {
    private ImportTemplateMapper $mapper;
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

        foreach (['select', 'from', 'where', 'andWhere', 'orderBy', 'addOrderBy'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new ImportTemplateMapper($this->db);
    }

    private function makeRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'name' => 'My Bank',
            'mapping' => json_encode(['date' => 'Date', 'amount' => 'Amount', 'description' => 'Memo']),
            'delimiter' => ',',
            'skip_first_row' => 0,
            'account_id' => null,
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => null,
        ], $overrides);
    }

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_import_templates', $this->mapper->getTableName());
    }

    public function testFindReturnsTemplate(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $template = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(ImportTemplate::class, $template);
        $this->assertEquals('My Bank', $template->getName());
        $this->assertEquals(['date' => 'Date', 'amount' => 'Amount', 'description' => 'Memo'], $template->getParsedMapping());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    public function testFindAllReturnsTemplates(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeRow(['id' => 1, 'name' => 'Alpha']),
                $this->makeRow(['id' => 2, 'name' => 'Beta']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $templates = $this->mapper->findAll('user1');

        $this->assertCount(2, $templates);
        $this->assertEquals('Alpha', $templates[0]->getName());
    }

    public function testFindByNameReturnsTemplate(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeRow(['name' => 'My Bank']), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $template = $this->mapper->findByName('My Bank', 'user1');

        $this->assertEquals('My Bank', $template->getName());
    }

    public function testNameExistsReturnsTrueWhenRowFound(): void {
        $this->result->method('fetch')->willReturn(['id' => 1]);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->assertTrue($this->mapper->nameExists('My Bank', 'user1'));
    }

    public function testNameExistsReturnsFalseWhenNoRow(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->assertFalse($this->mapper->nameExists('Missing', 'user1'));
    }

    public function testNameExistsWithExcludeIdReturnsFalse(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->assertFalse($this->mapper->nameExists('My Bank', 'user1', 5));
    }
}
