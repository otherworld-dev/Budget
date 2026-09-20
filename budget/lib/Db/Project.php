<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One budget total over a date range for a category and everything under it
 * (#391). Optional amounts for its subcategories live in ProjectAllocation.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getCategoryId()
 * @method void setCategoryId(int $categoryId)
 * @method float getTotalAmount()
 * @method void setTotalAmount(float $totalAmount)
 * @method string getStartDate()
 * @method void setStartDate(string $startDate)
 * @method string|null getEndDate()
 * @method void setEndDate(?string $endDate)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class Project extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $categoryId;
    protected $totalAmount;
    protected $startDate;
    protected $endDate;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('categoryId', 'integer');
        $this->addType('totalAmount', 'float');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'categoryId' => $this->getCategoryId(),
            'totalAmount' => $this->getTotalAmount(),
            'startDate' => self::day($this->getStartDate()),
            'endDate' => self::day($this->getEndDate()),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }

    /**
     * A DATE column as Y-m-d. Some databases return it with a time part.
     */
    public static function day(?string $value): ?string {
        return ($value === null || $value === '') ? null : substr($value, 0, 10);
    }
}
