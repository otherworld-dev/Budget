<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int|null getTagSetId()
 * @method void setTagSetId(?int $tagSetId)
 * @method string|null getUserId()
 * @method void setUserId(?string $userId)
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
    protected $userId;
    protected $name;
    protected $color;
    protected $sortOrder;
    protected $createdAt;

    public function __construct() {
        // Note: tagSetId intentionally not typed as 'integer' because
        // addType('integer') casts NULL to 0, breaking global tag detection.
        $this->addType('userId', 'string');
        $this->addType('name', 'string');
        $this->addType('color', 'string');
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'string');
    }

    public function getTagSetId(): ?int {
        if ($this->tagSetId === null || $this->tagSetId === '') {
            return null;
        }
        return (int)$this->tagSetId;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'tagSetId' => $this->getTagSetId(),
            'userId' => $this->getUserId(),
            'isGlobal' => ($this->getTagSetId() === null),
            'name' => $this->getName(),
            'color' => $this->getColor(),
            'sortOrder' => $this->getSortOrder(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
