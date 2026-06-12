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
 * @method string getType()
 * @method void setType(string $type)
 * @method int|null getParentId()
 * @method void setParentId(?int $parentId)
 * @method string|null getIcon()
 * @method void setIcon(?string $icon)
 * @method string|null getColor()
 * @method void setColor(?string $color)
 * @method float|null getBudgetAmount()
 * @method void setBudgetAmount(?float $budgetAmount)
 * @method string|null getBudgetPeriod()
 * @method void setBudgetPeriod(?string $budgetPeriod)
 * @method int getSortOrder()
 * @method void setSortOrder(int $sortOrder)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 * @method bool|null getExcludedFromReports()
 * @method void setExcludedFromReports(?bool $excludedFromReports)
 * @method bool|null getBudgetRollover()
 * @method void setBudgetRollover(?bool $budgetRollover)
 * @method string|null getRolloverStart()
 * @method void setRolloverStart(?string $rolloverStart)
 */
class Category extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $type;
    protected $parentId;
    protected $icon;
    protected $color;
    protected $budgetAmount;
    protected $budgetPeriod;  // monthly, weekly, yearly, quarterly
    protected $sortOrder;
    protected $excludedFromReports;
    protected $budgetRollover;   // envelope budgeting: unspent budget carries to next month
    protected $rolloverStart;    // YYYY-MM the carryover chain starts from
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('parentId', 'integer');
        $this->addType('budgetAmount', 'float');
        $this->addType('sortOrder', 'integer');
        $this->addType('excludedFromReports', 'boolean');
        $this->addType('budgetRollover', 'boolean');
    }

    /**
     * Serialize the category to JSON format
     * Returns all fields in camelCase format for frontend consumption
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'type' => $this->getType(),
            'parentId' => $this->getParentId(),
            'icon' => $this->getIcon(),
            'color' => $this->getColor(),
            'budgetAmount' => $this->getBudgetAmount(),
            'budgetPeriod' => $this->getBudgetPeriod() ?? 'monthly',
            'sortOrder' => $this->getSortOrder(),
            'excludedFromReports' => $this->getExcludedFromReports() ?? false,
            'budgetRollover' => $this->getBudgetRollover() ?? false,
            'rolloverStart' => $this->getRolloverStart(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}