<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A user-saved CSV import template: a named, reusable column mapping.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getMapping()
 * @method void setMapping(string $mapping)
 * @method string getDelimiter()
 * @method void setDelimiter(string $delimiter)
 * @method bool getSkipFirstRow()
 * @method void setSkipFirstRow(bool $skipFirstRow)
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
    protected $mapping;
    protected $delimiter;
    protected $skipFirstRow;
    protected $accountId;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('skipFirstRow', 'boolean');
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
            'mapping' => $this->getParsedMapping(),
            'delimiter' => $this->getDelimiter() ?? ',',
            'skipFirstRow' => $this->getSkipFirstRow() ?? false,
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
        $json = $this->getMapping();
        if ($json) {
            $mapping = json_decode($json, true);
            if (is_array($mapping)) {
                return $mapping;
            }
        }
        return [];
    }

    /**
     * Set the column mapping from an array (stored as a JSON string).
     *
     * @param array<string, mixed> $mapping
     */
    public function setMappingFromArray(array $mapping): void {
        $this->setMapping(json_encode($mapping));
    }
}
