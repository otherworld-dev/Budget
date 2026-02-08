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
 * @method float getTargetAmount()
 * @method void setTargetAmount(float $targetAmount)
 * @method float getCurrentAmount()
 * @method void setCurrentAmount(float $currentAmount)
 * @method int|null getTargetMonths()
 * @method void setTargetMonths(?int $targetMonths)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string|null getTargetDate()
 * @method void setTargetDate(?string $targetDate)
 * @method int|null getTagId()
 * @method void setTagId(?int $tagId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class SavingsGoal extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $targetAmount;
    protected $currentAmount;
    protected $targetMonths;
    protected $description;
    protected $targetDate;
    protected $tagId;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('targetAmount', 'float');
        $this->addType('currentAmount', 'float');
        $this->addType('targetMonths', 'integer');
        $this->addType('tagId', 'integer');
    }

    public function jsonSerialize(): array {
        $current = $this->getCurrentAmount();
        $target = $this->getTargetAmount();
        $completed = $current >= $target;

        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'targetAmount' => $target,
            'currentAmount' => $current,
            'targetMonths' => $this->getTargetMonths(),
            'description' => $this->getDescription(),
            'targetDate' => $this->getTargetDate(),
            'tagId' => $this->getTagId(),
            'createdAt' => $this->getCreatedAt(),
            'completed' => $completed,
        ];
    }
}
