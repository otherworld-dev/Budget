<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getPattern()
 * @method void setPattern(string $pattern)
 * @method string getField()
 * @method void setField(string $field)
 * @method string getMatchType()
 * @method void setMatchType(string $matchType)
 * @method int|null getCategoryId()
 * @method void setCategoryId(?int $categoryId)
 * @method string|null getVendorName()
 * @method void setVendorName(?string $vendorName)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class ImportRule extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $pattern;
    protected $field;
    protected $matchType;
    protected $categoryId;
    protected $vendorName;
    protected $priority;
    protected $active;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('categoryId', 'integer');
        $this->addType('priority', 'integer');
        $this->addType('active', 'boolean');
    }

    /**
     * Serialize the import rule to JSON format
     * Returns all fields in camelCase format for frontend consumption
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'pattern' => $this->getPattern(),
            'field' => $this->getField(),
            'matchType' => $this->getMatchType(),
            'categoryId' => $this->getCategoryId(),
            'vendorName' => $this->getVendorName(),
            'priority' => $this->getPriority(),
            'active' => $this->getActive(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}