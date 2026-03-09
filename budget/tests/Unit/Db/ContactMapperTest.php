<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Db;

use OCA\Budget\Db\Contact;
use OCA\Budget\Db\ContactMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

class ContactMapperTest extends TestCase {
    private ContactMapper $mapper;
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
                   'delete'] as $method) {
            $this->qb->method($method)->willReturnSelf();
        }

        $this->mapper = new ContactMapper($this->db);
    }

    private function makeContactRow(array $overrides = []): array {
        return array_merge([
            'id' => 1,
            'user_id' => 'user1',
            'name' => 'Alice Smith',
            'email' => 'alice@example.com',
            'created_at' => '2026-01-01 00:00:00',
        ], $overrides);
    }

    // ===== getTableName =====

    public function testTableNameIsCorrect(): void {
        $this->assertEquals('budget_contacts', $this->mapper->getTableName());
    }

    // ===== find =====

    public function testFindReturnsContact(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeContactRow(), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $contact = $this->mapper->find(1, 'user1');

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertEquals('Alice Smith', $contact->getName());
        $this->assertEquals('alice@example.com', $contact->getEmail());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->find(999, 'user1');
    }

    // ===== findAll =====

    public function testFindAllReturnsContacts(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls(
                $this->makeContactRow(['id' => 1, 'name' => 'Alice']),
                $this->makeContactRow(['id' => 2, 'name' => 'Bob']),
                false
            );
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $contacts = $this->mapper->findAll('user1');

        $this->assertCount(2, $contacts);
    }

    // ===== findByName =====

    public function testFindByNameReturnsContact(): void {
        $this->result->method('fetch')
            ->willReturnOnConsecutiveCalls($this->makeContactRow(['name' => 'Bob Jones']), false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $contact = $this->mapper->findByName('Bob Jones', 'user1');

        $this->assertInstanceOf(Contact::class, $contact);
        $this->assertEquals('Bob Jones', $contact->getName());
    }

    public function testFindByNameThrowsWhenNotFound(): void {
        $this->result->method('fetch')->willReturn(false);
        $this->result->method('closeCursor');
        $this->qb->method('executeQuery')->willReturn($this->result);

        $this->expectException(DoesNotExistException::class);

        $this->mapper->findByName('Nonexistent', 'user1');
    }

    // ===== deleteAll =====

    public function testDeleteAllReturnsAffectedRows(): void {
        $this->qb->method('executeStatement')->willReturn(2);

        $count = $this->mapper->deleteAll('user1');

        $this->assertEquals(2, $count);
    }
}
