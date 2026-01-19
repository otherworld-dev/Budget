<?php

declare(strict_types=1);

namespace OCA\Budget\Db\Trait;

use OCA\Budget\Attribute\Encrypted;
use OCA\Budget\Service\EncryptionService;
use OCP\AppFramework\Db\Entity;
use ReflectionClass;
use ReflectionProperty;

/**
 * Trait for handling encrypted fields in entity mappers.
 * Uses reflection to find properties marked with #[Encrypted] attribute.
 *
 * Usage in a Mapper:
 * ```php
 * class AccountMapper extends QBMapper {
 *     use EncryptedFieldsTrait;
 *
 *     public function __construct(IDBConnection $db, EncryptionService $encryptionService) {
 *         parent::__construct($db, 'budget_accounts', Account::class);
 *         $this->initializeEncryption($encryptionService, Account::class);
 *     }
 * }
 * ```
 */
trait EncryptedFieldsTrait {
    private EncryptionService $encryptionService;

    /** @var array<string, ReflectionProperty> Cached encrypted properties */
    private array $encryptedProperties = [];

    /** @var bool Whether encryption has been initialized */
    private bool $encryptionInitialized = false;

    /**
     * Initialize encryption for this mapper.
     *
     * @param EncryptionService $encryptionService The encryption service
     * @param string $entityClass The entity class to scan for encrypted properties
     */
    protected function initializeEncryption(EncryptionService $encryptionService, string $entityClass): void {
        $this->encryptionService = $encryptionService;
        $this->encryptedProperties = $this->discoverEncryptedProperties($entityClass);
        $this->encryptionInitialized = true;
    }

    /**
     * Discover properties marked with #[Encrypted] attribute.
     *
     * @param string $entityClass The entity class to scan
     * @return array<string, ReflectionProperty>
     */
    private function discoverEncryptedProperties(string $entityClass): array {
        $properties = [];
        $reflection = new ReflectionClass($entityClass);

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Encrypted::class);
            if (!empty($attributes)) {
                $property->setAccessible(true);
                $properties[$property->getName()] = $property;
            }
        }

        return $properties;
    }

    /**
     * Get the list of encrypted property names.
     *
     * @return array<string>
     */
    protected function getEncryptedPropertyNames(): array {
        return array_keys($this->encryptedProperties);
    }

    /**
     * Encrypt all marked fields on an entity.
     *
     * @param Entity $entity The entity to encrypt
     * @return Entity The same entity with encrypted fields
     */
    protected function encryptEntity(Entity $entity): Entity {
        if (!$this->encryptionInitialized || empty($this->encryptedProperties)) {
            return $entity;
        }

        foreach ($this->encryptedProperties as $propertyName => $property) {
            $value = $property->getValue($entity);
            if ($value !== null && is_string($value)) {
                $encrypted = $this->encryptionService->encrypt($value);
                $setter = 'set' . ucfirst($propertyName);
                if (method_exists($entity, $setter)) {
                    $entity->$setter($encrypted);
                }
            }
        }

        return $entity;
    }

    /**
     * Decrypt all marked fields on an entity.
     *
     * @param Entity $entity The entity to decrypt
     * @return Entity The same entity with decrypted fields
     */
    protected function decryptEntity(Entity $entity): Entity {
        if (!$this->encryptionInitialized || empty($this->encryptedProperties)) {
            return $entity;
        }

        foreach ($this->encryptedProperties as $propertyName => $property) {
            $value = $property->getValue($entity);
            if ($value !== null && is_string($value)) {
                $decrypted = $this->encryptionService->decrypt($value);

                // Update via setter
                $setter = 'set' . ucfirst($propertyName);
                if (method_exists($entity, $setter)) {
                    $entity->$setter($decrypted);
                }

                // Also update the raw property directly to ensure consistency
                // This is critical when decryption fails and returns null
                $property->setValue($entity, $decrypted);
            }
        }

        return $entity;
    }

    /**
     * Decrypt an array of entities.
     *
     * @param array<Entity> $entities The entities to decrypt
     * @return array<Entity> The decrypted entities
     */
    protected function decryptEntities(array $entities): array {
        return array_map(fn($entity) => $this->decryptEntity($entity), $entities);
    }

    /**
     * Get encrypted value for a specific property.
     * Useful when building update queries manually.
     *
     * @param Entity $entity The entity
     * @param string $propertyName The property name
     * @return string|null The encrypted value
     */
    protected function getEncryptedValue(Entity $entity, string $propertyName): ?string {
        if (!isset($this->encryptedProperties[$propertyName])) {
            // Not an encrypted property, return raw value
            $getter = 'get' . ucfirst($propertyName);
            if (method_exists($entity, $getter)) {
                return $entity->$getter();
            }
            return null;
        }

        $property = $this->encryptedProperties[$propertyName];
        $value = $property->getValue($entity);

        if ($value === null || $value === '') {
            return $value;
        }

        // If value still has encryption prefix, decryption must have failed
        // Return null to avoid persisting broken encrypted data
        if ($this->encryptionService->isEncrypted($value)) {
            return null;
        }

        return $this->encryptionService->encrypt($value);
    }

    /**
     * Check if a property is marked as encrypted.
     *
     * @param string $propertyName The property name
     * @return bool
     */
    protected function isEncryptedProperty(string $propertyName): bool {
        return isset($this->encryptedProperties[$propertyName]);
    }
}
