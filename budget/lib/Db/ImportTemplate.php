<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A user-saved import template: a named, reusable import configuration.
 *
 * For CSV templates the reusable payload is the column mapping; for OFX/QIF
 * templates it is the account routing map (source account key -> budget
 * account id). Both can carry the cross-format import options.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getFormat()
 * @method void setFormat(string $format)
 * @method string getMapping()
 * @method void setMapping(string $mapping)
 * @method string|null getAccountMapping()
 * @method void setAccountMapping(?string $accountMapping)
 * @method string getDelimiter()
 * @method void setDelimiter(string $delimiter)
 * @method bool getSkipFirstRow()
 * @method void setSkipFirstRow(bool $skipFirstRow)
 * @method bool|null getSkipDuplicates()
 * @method void setSkipDuplicates(?bool $skipDuplicates)
 * @method bool|null getApplyRules()
 * @method void setApplyRules(?bool $applyRules)
 * @method int|null getAccountId()
 * @method void setAccountId(?int $accountId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class ImportTemplate extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $format;
    protected $mapping;
    protected $accountMapping;
    protected $delimiter;
    protected $skipFirstRow;
    protected $skipDuplicates;
    protected $applyRules;
    protected $accountId;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('skipFirstRow', 'boolean');
        $this->addType('skipDuplicates', 'boolean');
        $this->addType('applyRules', 'boolean');
        $this->addType('accountId', 'integer');
    }

    /**
     * Serialize the template to JSON format for frontend consumption.
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'format' => $this->getFormat() ?? 'csv',
            'mapping' => $this->getParsedMapping(),
            'accountMapping' => $this->getParsedAccountMapping(),
            'delimiter' => $this->getDelimiter() ?? ',',
            'skipFirstRow' => $this->getSkipFirstRow() ?? false,
            'skipDuplicates' => $this->getSkipDuplicates() ?? true,
            'applyRules' => $this->getApplyRules() ?? false,
            'accountId' => $this->getAccountId(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }

    /**
     * Get the column mapping decoded from its stored JSON string.
     *
     * @return array<string, mixed>
     */
    public function getParsedMapping(): array {
        return $this->decodeJsonColumn($this->getMapping());
    }

    /**
     * Set the column mapping from an array (stored as a JSON string).
     *
     * @param array<string, mixed> $mapping
     */
    public function setMappingFromArray(array $mapping): void {
        $this->setMapping(json_encode($mapping));
    }

    /**
     * Get the account routing map (source account key -> budget account id).
     *
     * @return array<string, int>
     */
    public function getParsedAccountMapping(): array {
        return $this->decodeJsonColumn($this->getAccountMapping());
    }

    /**
     * Set the account routing map from an array (stored as a JSON string).
     *
     * @param array<string, int> $accountMapping
     */
    public function setAccountMappingFromArray(array $accountMapping): void {
        $this->setAccountMapping(json_encode($accountMapping));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonColumn(?string $json): array {
        if ($json) {
            $decoded = json_decode($json, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}
