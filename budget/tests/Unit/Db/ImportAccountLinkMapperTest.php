<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\ImportAccountLink;
use OCA\Budget\Db\ImportAccountLinkMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ImportAccountLinkMapperTest extends TestCase {
    private ImportAccountLinkMapper $mapper;
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

        foreach (['select', 'from', 'where', 'andWhere'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new ImportAccountLinkMapper($this->db);
    }

    private function makeRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'format' => 'qif',
            'source_key' => 'Checking',
            'budget_account_id' => 5,
            'updated_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_imp_links', $this->mapper->getTableName());
    }

    public function testFindBySourceReturnsLink(): void {
        $this->result->method('fetch')->willReturnOnConsecutiveCalls($this->makeRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $link = $this->mapper->findBySource('user1', 'qif', 'Checking');

        $this->assertInstanceOf(ImportAccountLink::class, $link);
        $this->assertEquals('Checking', $link->getSourceKey());
        $this->assertEquals(5, $link->getBudgetAccountId());
    }

    public function testFindBySourceThrowsWhenMissing(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->findBySource('user1', 'qif', 'Missing');
    }

    public function testFindAllByFormatReturnsLinks(): void {
        $this->result->method('fetch')->willReturnOnConsecutiveCalls(
            $this->makeRow(['id' => 1, 'source_key' => 'Checking', 'budget_account_id' => 5]),
            $this->makeRow(['id' => 2, 'source_key' => 'Savings', 'budget_account_id' => 9]),
            false
        );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $links = $this->mapper->findAllByFormat('user1', 'qif');

        $this->assertCount(2, $links);
        $this->assertEquals('Checking', $links[0]->getSourceKey());
        $this->assertEquals(9, $links[1]->getBudgetAccountId());
    }
}
