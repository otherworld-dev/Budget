<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getCategoryId()
 * @method void setCategoryId(int $categoryId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class TagSet extends Entity implements JsonSerializable {
    protected $categoryId;
    protected $name;
    protected $description;
    protected $sortOrder;
    protected $createdAt;
    protected $updatedAt;

    // Non-persisted field for convenience
    protected $tags = [];

    public function __construct() {
        $this->addType('categoryId', 'integer');
        $this->addType('name', 'string');
        $this->addType('description', 'string');
        $this->addType('sortOrder', 'integer');
        $this->addType('createdAt', 'string');
        $this->addType('updatedAt', 'string');
    }

    /**
     * Set tags for this tag set (non-persisted, for convenience)
     */
    public function setTags(array $tags): void {
        $this->tags = $tags;
    }

    /**
     * Get tags for this tag set (non-persisted)
     */
    public function getTags(): array {
        return $this->tags;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'categoryId' => $this->getCategoryId(),
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'sortOrder' => $this->getSortOrder(),
            'tags' => $this->tags,
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}
