<?php

declare(strict_types=1);

namespace OCA\Budget\Service;

use OCA\Budget\Db\ImportTemplate;
use OCA\Budget\Db\ImportTemplateMapper;
use OCP\AppFramework\Db\Entity;

/**
 * CRUD service for user-saved import templates.
 *
 * A template is format-aware: CSV templates carry a column mapping, OFX/QIF
 * templates carry an account routing map (source account key -> budget account
 * id). Both can carry the cross-format import options (skip duplicates, apply
 * rules) and an optional default destination account.
 *
 * @extends AbstractCrudService<ImportTemplate>
 */
class ImportTemplateService extends AbstractCrudService {
    public const FORMATS = ['csv', 'ofx', 'qif'];

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

    /**
     * @return ImportTemplate[]
     */
    public function findAllByFormat(string $userId, string $format): array {
        $this->assertValidFormat($format);
        /** @var ImportTemplateMapper $mapper */
        $mapper = $this->mapper;
        return $mapper->findAllByFormat($userId, $format);
    }

    /**
     * @param array<string, mixed> $mapping        CSV column mapping (csv only)
     * @param array<string, int> $accountMapping    Source key -> account id (ofx/qif only)
     */
    public function create(
        string $userId,
        string $name,
        string $format = 'csv',
        array $mapping = [],
        array $accountMapping = [],
        string $delimiter = ',',
        bool $skipFirstRow = false,
        bool $skipDuplicates = true,
        bool $applyRules = false,
        ?int $accountId = null
    ): ImportTemplate {
        $this->assertValidFormat($format);
        $name = $this->normalizeName($name);
        $this->assertNameAvailable($name, $userId);

        $template = new ImportTemplate();
        $template->setUserId($userId);
        $template->setName($name);
        $template->setFormat($format);

        if ($format === 'csv') {
            $mapping = $this->sanitizeMapping($mapping);
            $this->assertMappingValid($mapping);
            $template->setMappingFromArray($mapping);
            $template->setAccountMappingFromArray([]);
        } else {
            $accountMapping = $this->sanitizeAccountMapping($accountMapping);
            $this->assertAccountMappingValid($accountMapping);
            $template->setAccountMappingFromArray($accountMapping);
            $template->setMappingFromArray([]);
        }

        $template->setDelimiter($delimiter !== '' ? $delimiter : ',');
        $template->setSkipFirstRow($skipFirstRow);
        $template->setSkipDuplicates($skipDuplicates);
        $template->setApplyRules($applyRules);
        $template->setAccountId($accountId);
        $template->setCreatedAt(date('Y-m-d H:i:s'));

        /** @var ImportTemplateMapper $mapper */
        $mapper = $this->mapper;
        return $mapper->insert($template);
    }

    /**
     * Update a template. The format is immutable; the mapping/account-mapping
     * payloads are stored as JSON and validated against the template's format.
     *
     * @param array<string, mixed> $updates
     */
    public function update(int $id, string $userId, array $updates): Entity {
        // Format is fixed at creation time.
        unset($updates['format']);

        if (isset($updates['name'])) {
            $updates['name'] = $this->normalizeName($updates['name']);
            $this->assertNameAvailable($updates['name'], $userId, $id);
        }

        if (isset($updates['delimiter']) && $updates['delimiter'] === '') {
            $updates['delimiter'] = ',';
        }

        $hasMapping = array_key_exists('mapping', $updates);
        $hasAccountMapping = array_key_exists('accountMapping', $updates);

        if (!$hasMapping && !$hasAccountMapping) {
            return parent::update($id, $userId, $updates);
        }

        // The JSON payloads need bespoke handling (array -> JSON string), so
        // resolve the entity and apply everything ourselves.
        $entity = $this->find($id, $userId);

        if ($hasMapping) {
            $mapping = $this->sanitizeMapping((array) $updates['mapping']);
            $this->assertMappingValid($mapping);
            $entity->setMappingFromArray($mapping);
            unset($updates['mapping']);
        }
        if ($hasAccountMapping) {
            $accountMapping = $this->sanitizeAccountMapping((array) $updates['accountMapping']);
            $this->assertAccountMappingValid($accountMapping);
            $entity->setAccountMappingFromArray($accountMapping);
            unset($updates['accountMapping']);
        }

        $this->applyUpdates($entity, $updates);
        $this->setTimestamps($entity, false);

        /** @var ImportTemplateMapper $mapper */
        $mapper = $this->mapper;
        return $mapper->update($entity);
    }

    private function assertValidFormat(string $format): void {
        if (!in_array($format, self::FORMATS, true)) {
            throw new \InvalidArgumentException('Unsupported import format: ' . $format);
        }
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
     * Keep only valid source-key -> positive-account-id pairs.
     *
     * @param array<string, mixed> $accountMapping
     * @return array<string, int>
     */
    private function sanitizeAccountMapping(array $accountMapping): array {
        $clean = [];
        foreach ($accountMapping as $sourceKey => $destId) {
            $key = trim((string) $sourceKey);
            $id = (int) $destId;
            if ($key !== '' && $id > 0) {
                $clean[$key] = $id;
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

    /**
     * @param array<string, int> $accountMapping
     */
    private function assertAccountMappingValid(array $accountMapping): void {
        if (empty($accountMapping)) {
            throw new \InvalidArgumentException('Map at least one source account to one of your accounts');
        }
    }
}
