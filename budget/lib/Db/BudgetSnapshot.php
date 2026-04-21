<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCategoryId()
 * @method void setCategoryId(int $categoryId)
 * @method string getEffectiveFrom()
 * @method void setEffectiveFrom(string $effectiveFrom)
 * @method float|null getAmount()
 * @method void setAmount(?float $amount)
 * @method string|null getPeriod()
 * @method void setPeriod(?string $period)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class BudgetSnapshot extends Entity implements JsonSerializable {
    protected $userId;
    protected $categoryId;
    protected $effectiveFrom;
    protected $amount;
    protected $period;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('categoryId', 'integer');
        $this->addType('amount', 'float');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'categoryId' => $this->getCategoryId(),
            'effectiveFrom' => $this->getEffectiveFrom(),
            'amount' => $this->getAmount(),
            'period' => $this->getPeriod() ?? 'monthly',
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
