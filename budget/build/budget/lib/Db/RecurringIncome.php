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
 * @method float getAmount()
 * @method void setAmount(float $amount)
 * @method string getFrequency()
 * @method void setFrequency(string $frequency)
 * @method int|null getExpectedDay()
 * @method void setExpectedDay(?int $expectedDay)
 * @method int|null getExpectedMonth()
 * @method void setExpectedMonth(?int $expectedMonth)
 * @method int|null getCategoryId()
 * @method void setCategoryId(?int $categoryId)
 * @method int|null getAccountId()
 * @method void setAccountId(?int $accountId)
 * @method string|null getSource()
 * @method void setSource(?string $source)
 * @method string|null getAutoDetectPattern()
 * @method void setAutoDetectPattern(?string $autoDetectPattern)
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method string|null getLastReceivedDate()
 * @method void setLastReceivedDate(?string $lastReceivedDate)
 * @method string|null getNextExpectedDate()
 * @method void setNextExpectedDate(?string $nextExpectedDate)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class RecurringIncome extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $amount;
    protected $frequency;        // monthly, weekly, yearly, quarterly, biweekly
    protected $expectedDay;      // Day of month (1-31) or day of week (1-7) for weekly
    protected $expectedMonth;    // Month (1-12) for yearly income
    protected $categoryId;
    protected $accountId;
    protected $source;           // Income source (employer name, dividend source, etc.)
    protected $autoDetectPattern;
    protected $isActive;
    protected $lastReceivedDate;
    protected $nextExpectedDate;
    protected $notes;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('amount', 'float');
        $this->addType('expectedDay', 'integer');
        $this->addType('expectedMonth', 'integer');
        $this->addType('categoryId', 'integer');
        $this->addType('accountId', 'integer');
        $this->addType('isActive', 'boolean');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'amount' => $this->getAmount(),
            'frequency' => $this->getFrequency(),
            'expectedDay' => $this->getExpectedDay(),
            'expectedMonth' => $this->getExpectedMonth(),
            'categoryId' => $this->getCategoryId(),
            'accountId' => $this->getAccountId(),
            'source' => $this->getSource(),
            'autoDetectPattern' => $this->getAutoDetectPattern(),
            'isActive' => $this->getIsActive(),
            'lastReceivedDate' => $this->getLastReceivedDate(),
            'nextExpectedDate' => $this->getNextExpectedDate(),
            'notes' => $this->getNotes(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
