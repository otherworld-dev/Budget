<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\ImportTemplate;
use OCA\Budget\Db\ImportTemplateMapper;
use OCP\AppFramework\Db\Entity;

/**
 * CRUD service for user-saved CSV import templates (reusable column mappings).
 *
 * @extends AbstractCrudService<ImportTemplate>
 */
class ImportTemplateService extends AbstractCrudService {
    /**
     * Mapping keys that hold a CSV column reference.
     */
    private const COLUMN_FIELDS = [
        'date', 'amount', 'incomeColumn', 'expenseColumn', 'description',
        'type', 'vendor', 'reference', 'category', 'account', 'currency',
    ];

    /**
     * Mapping keys that hold a boolean option.
     */
    private const BOOLEAN_FIELDS = ['skipFirstRow', 'applyRules'];

    public function __construct(ImportTemplateMapper $mapper) {
        $this->mapper = $mapper;
    }

    public function create(
        string $userId,
        string $name,
        array $mapping,
        string $delimiter = ',',
        bool $skipFirstRow = false,
        ?int $accountId = null
    ): ImportTemplate {
        $name = $this->normalizeName($name);
        $this->assertNameAvailable($name, $userId);

        $mapping = $this->sanitizeMapping($mapping);
        $this->assertMappingValid($mapping);

        $template = new ImportTemplate();
        $template->setUserId($userId);
        $template->setName($name);
        $template->setMappingFromArray($mapping);
        $template->setDelimiter($delimiter !== '' ? $delimiter : ',');
        $template->setSkipFirstRow($skipFirstRow);
        $template->setAccountId($accountId);
        $template->setCreatedAt(date('Y-m-d H:i:s'));

        /** @var ImportTemplateMapper $mapper */
        $mapper = $this->mapper;
        return $mapper->insert($template);
    }

    /**
     * Update a template. Converts a `mapping` array to its stored JSON form and
     * validates name uniqueness / mapping shape before delegating to the base.
     *
     * @param array<string, mixed> $updates
     */
    public function update(int $id, string $userId, array $updates): Entity {
        if (isset($updates['name'])) {
            $updates['name'] = $this->normalizeName($updates['name']);
            $this->assertNameAvailable($updates['name'], $userId, $id);
        }

        if (array_key_exists('mapping', $updates)) {
            $mapping = $this->sanitizeMapping((array) $updates['mapping']);
            $this->assertMappingValid($mapping);
            // Stored as a JSON string; drop the array form so applyUpdates skips it.
            unset($updates['mapping']);
            $entity = $this->find($id, $userId);
            $entity->setMappingFromArray($mapping);
            $this->applyUpdates($entity, $updates);
            $this->setTimestamps($entity, false);
            /** @var ImportTemplateMapper $mapper */
            $mapper = $this->mapper;
            return $mapper->update($entity);
        }

        if (isset($updates['delimiter']) && $updates['delimiter'] === '') {
            $updates['delimiter'] = ',';
        }

        return parent::update($id, $userId, $updates);
    }

    private function normalizeName(string $name): string {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Template name is required');
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }
        return $name;
    }

    private function assertNameAvailable(string $name, string $userId, ?int $excludeId = null): void {
        /** @var ImportTemplateMapper $mapper */
        $mapper = $this->mapper;
        if ($mapper->nameExists($name, $userId, $excludeId)) {
            throw new \InvalidArgumentException('A template with this name already exists');
        }
    }

    /**
     * Keep only recognised mapping keys and coerce their value types.
     *
     * @param array<string, mixed> $mapping
     * @return array<string, mixed>
     */
    private function sanitizeMapping(array $mapping): array {
        $clean = [];
        foreach (self::COLUMN_FIELDS as $field) {
            if (isset($mapping[$field]) && $mapping[$field] !== '' && $mapping[$field] !== null) {
                $clean[$field] = (string) $mapping[$field];
            }
        }
        foreach (self::BOOLEAN_FIELDS as $field) {
            if (isset($mapping[$field])) {
                $clean[$field] = (bool) $mapping[$field];
            }
        }
        return $clean;
    }

    /**
     * Enforce the same minimum requirements as the import mapping step:
     * a date column, a description column, and exactly one amount strategy
     * (single amount column XOR separate income/expense columns).
     *
     * @param array<string, mixed> $mapping
     */
    private function assertMappingValid(array $mapping): void {
        $hasDate = !empty($mapping['date']);
        $hasDescription = !empty($mapping['description']);
        $hasAmount = !empty($mapping['amount']);
        $hasDualColumns = !empty($mapping['incomeColumn']) || !empty($mapping['expenseColumn']);

        if (!$hasDate) {
            throw new \InvalidArgumentException('A date column mapping is required');
        }
        if (!$hasDescription) {
            throw new \InvalidArgumentException('A description column mapping is required');
        }
        if ($hasAmount === $hasDualColumns) {
            throw new \InvalidArgumentException(
                'Map either an amount column or separate income/expense columns, but not both'
            );
        }
    }
}
