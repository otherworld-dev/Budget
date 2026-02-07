<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\Entity;
use OCP\AppFramework\Db\QBMapper;

/**
 * Abstract base class for CRUD services.
 * Provides common find, findAll, update, and delete operations.
 *
 * @template T of Entity
 */
abstract class AbstractCrudService {
    /**
     * @var QBMapper<T>
     */
    protected QBMapper $mapper;

    /**
     * Get the primary mapper for this service.
     *
     * @return QBMapper<T>
     */
    protected function getMapper(): QBMapper {
        return $this->mapper;
    }

    /**
     * Find a single entity by ID with user ownership check.
     *
     * @param int $id
     * @param string $userId
     * @return T
     * @throws DoesNotExistException
     */
    public function find(int $id, string $userId): Entity {
        return $this->mapper->find($id, $userId);
    }

    /**
     * Find all entities for a user.
     *
     * @param string $userId Current user ID
     * @param array|null $accessibleUserIds Optional list of user IDs to fetch entities for (for shared budgets)
     * @return T[]
     */
    public function findAll(string $userId, ?array $accessibleUserIds = null): array {
        return $this->mapper->findAll($userId, $accessibleUserIds);
    }

    /**
     * Update an entity with the provided updates.
     *
     * @param int $id
     * @param string $userId
     * @param array<string, mixed> $updates
     * @return T
     * @throws DoesNotExistException
     */
    public function update(int $id, string $userId, array $updates): Entity {
        $entity = $this->find($id, $userId);

        // Allow subclasses to validate updates before applying
        $this->beforeUpdate($entity, $updates, $userId);

        $this->applyUpdates($entity, $updates);
        $this->setTimestamps($entity, false);

        return $this->mapper->update($entity);
    }

    /**
     * Delete an entity by ID with user ownership check.
     *
     * @param int $id
     * @param string $userId
     * @throws DoesNotExistException
     * @throws \Exception If deletion is not allowed
     */
    public function delete(int $id, string $userId): void {
        $entity = $this->find($id, $userId);

        // Allow subclasses to validate/prevent deletion
        $this->beforeDelete($entity, $userId);

        $this->mapper->delete($entity);
    }

    /**
     * Apply updates to an entity using dynamic setters.
     *
     * @param T $entity
     * @param array<string, mixed> $updates
     */
    protected function applyUpdates(Entity $entity, array $updates): void {
        foreach ($updates as $key => $value) {
            $setter = 'set' . ucfirst($key);
            // Use is_callable to support magic methods from Entity parent class
            if (is_callable([$entity, $setter])) {
                $entity->$setter($value);
            }
        }
    }

    /**
     * Set timestamp fields on an entity.
     *
     * @param T $entity
     * @param bool $isNew Whether this is a new entity (sets createdAt)
     */
    protected function setTimestamps(Entity $entity, bool $isNew = false): void {
        $now = date('Y-m-d H:i:s');

        if ($isNew && is_callable([$entity, 'setCreatedAt'])) {
            $entity->setCreatedAt($now);
        }

        if (is_callable([$entity, 'setUpdatedAt'])) {
            $entity->setUpdatedAt($now);
        }
    }

    /**
     * Hook called before update is applied.
     * Override to add validation logic.
     *
     * @param T $entity
     * @param array<string, mixed> $updates
     * @param string $userId
     * @throws \Exception If validation fails
     */
    protected function beforeUpdate(Entity $entity, array $updates, string $userId): void {
        // Default implementation does nothing
    }

    /**
     * Hook called before deletion.
     * Override to add constraint checking.
     *
     * @param T $entity
     * @param string $userId
     * @throws \Exception If deletion is not allowed
     */
    protected function beforeDelete(Entity $entity, string $userId): void {
        // Default implementation does nothing
    }
}
