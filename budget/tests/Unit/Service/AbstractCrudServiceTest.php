<?php

declare(strict_types=1);

namespace OCA\Budget\Tests\Unit\Service;

use OCA\Budget\Service\AbstractCrudService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * Concrete test entity with timestamp fields.
 */
class TestCrudEntity extends Entity {
    protected $name;
    protected $createdAt;
    protected $updatedAt;

    public function getName(): ?string { return $this->name; }
    public function setName(?string $name): void { $this->name = $name; }
    public function getCreatedAt(): ?string { return $this->createdAt; }
    public function setCreatedAt(?string $v): void { $this->createdAt = $v; }
    public function getUpdatedAt(): ?string { return $this->updatedAt; }
    public function setUpdatedAt(?string $v): void { $this->updatedAt = $v; }
}

/**
 * Concrete implementation of AbstractCrudService for testing.
 */
class ConcreteCrudService extends AbstractCrudService {
    public bool $beforeUpdateCalled = false;
    public bool $beforeDeleteCalled = false;
    public bool $blockDelete = false;

    public function setMapper(QBMapper $mapper): void {
        $this->mapper = $mapper;
    }

    protected function beforeUpdate(Entity $entity, array $updates, string $userId): void {
        $this->beforeUpdateCalled = true;
    }

    protected function beforeDelete(Entity $entity, string $userId): void {
        $this->beforeDeleteCalled = true;
        if ($this->blockDelete) {
            throw new \Exception('Deletion not allowed');
        }
    }
}

class AbstractCrudServiceTest extends TestCase {
    private ConcreteCrudService $service;
    private $mapper;

    protected function setUp(): void {
        // QBMapper doesn't have find(int,string) or findAll(string) - those are on subclasses.
        // Use addMethods for non-existent methods, onlyMethods for existing ones.
        $this->mapper = $this->getMockBuilder(QBMapper::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['update', 'delete'])
            ->addMethods(['find', 'findAll'])
            ->getMock();

        $this->service = new ConcreteCrudService();
        $this->service->setMapper($this->mapper);
    }

    private function makeEntity(int $id = 1, string $name = 'Test'): TestCrudEntity {
        $entity = new TestCrudEntity();
        $entity->setId($id);
        $entity->setName($name);
        return $entity;
    }

    // ===== find =====

    public function testFindDelegatesToMapper(): void {
        $entity = $this->makeEntity();
        $this->mapper->method('find')->with(1, 'user1')->willReturn($entity);

        $result = $this->service->find(1, 'user1');

        $this->assertEquals('Test', $result->getName());
    }

    public function testFindThrowsWhenNotFound(): void {
        $this->mapper->method('find')
            ->willThrowException(new DoesNotExistException(''));

        $this->expectException(DoesNotExistException::class);

        $this->service->find(999, 'user1');
    }

    // ===== findAll =====

    public function testFindAllDelegatesToMapper(): void {
        $this->mapper->method('findAll')->willReturn([
            $this->makeEntity(1, 'A'),
            $this->makeEntity(2, 'B'),
        ]);

        $result = $this->service->findAll('user1');

        $this->assertCount(2, $result);
    }

    // ===== update =====

    public function testUpdateAppliesUpdatesAndTimestamp(): void {
        $entity = $this->makeEntity(1, 'Old');
        $this->mapper->method('find')->willReturn($entity);
        $this->mapper->method('update')->willReturnArgument(0);

        $result = $this->service->update(1, 'user1', ['name' => 'New']);

        $this->assertEquals('New', $result->getName());
        $this->assertNotNull($result->getUpdatedAt());
        $this->assertTrue($this->service->beforeUpdateCalled);
    }

    public function testUpdateThrowsForUnknownFields(): void {
        $entity = $this->makeEntity();
        $this->mapper->method('find')->willReturn($entity);

        // Entity magic __call throws for unknown properties
        $this->expectException(\BadFunctionCallException::class);

        $this->service->update(1, 'user1', ['nonexistent' => 'value']);
    }

    // ===== delete =====

    public function testDeleteRemovesEntity(): void {
        $entity = $this->makeEntity();
        $this->mapper->method('find')->willReturn($entity);
        $this->mapper->expects($this->once())->method('delete')->with($entity);

        $this->service->delete(1, 'user1');

        $this->assertTrue($this->service->beforeDeleteCalled);
    }

    public function testDeleteThrowsWhenBlocked(): void {
        $entity = $this->makeEntity();
        $this->mapper->method('find')->willReturn($entity);
        $this->service->blockDelete = true;

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Deletion not allowed');

        $this->service->delete(1, 'user1');
    }

    // ===== setTimestamps =====

    public function testUpdateSetsUpdatedAtOnly(): void {
        $entity = $this->makeEntity();
        $this->mapper->method('find')->willReturn($entity);
        $this->mapper->method('update')->willReturnArgument(0);

        $result = $this->service->update(1, 'user1', []);

        $this->assertNotNull($result->getUpdatedAt());
        // createdAt should NOT be set by update (isNew=false)
        $this->assertNull($result->getCreatedAt());
    }
}
