<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTagSetId()
 * @method void setTagSetId(int $tagSetId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class Tag extends Entity implements JsonSerializable {
    protected $tagSetId;
    protected $name;
    protected $color;
    protected $sortOrder;
    protected $createdAt;

    public function __construct() {
        $this->addType('tagSetId', 'integer');
        $this->addType('name', 'string');
        $this->addType('color', 'string');
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'string');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'tagSetId' => $this->getTagSetId(),
            'name' => $this->getName(),
            'color' => $this->getColor(),
            'sortOrder' => $this->getSortOrder(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
