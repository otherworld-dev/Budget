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
 * @method int|null getDueDay()
 * @method void setDueDay(?int $dueDay)
 * @method int|null getDueMonth()
 * @method void setDueMonth(?int $dueMonth)
 * @method int|null getCategoryId()
 * @method void setCategoryId(?int $categoryId)
 * @method int|null getAccountId()
 * @method void setAccountId(?int $accountId)
 * @method string|null getAutoDetectPattern()
 * @method void setAutoDetectPattern(?string $autoDetectPattern)
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method string|null getLastPaidDate()
 * @method void setLastPaidDate(?string $lastPaidDate)
 * @method string|null getNextDueDate()
 * @method void setNextDueDate(?string $nextDueDate)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method int|null getReminderDays()
 * @method void setReminderDays(?int $reminderDays)
 * @method string|null getLastReminderSent()
 * @method void setLastReminderSent(?string $lastReminderSent)
 */
class Bill extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $amount;
    protected $frequency;       // monthly, weekly, yearly, quarterly
    protected $dueDay;          // Day of month (1-31) or day of week (1-7) for weekly
    protected $dueMonth;        // Month (1-12) for yearly bills
    protected $categoryId;
    protected $accountId;
    protected $autoDetectPattern;  // Pattern to match transactions
    protected $isActive;
    protected $lastPaidDate;
    protected $nextDueDate;
    protected $notes;
    protected $createdAt;
    protected $reminderDays;      // Days before due date to send reminder
    protected $lastReminderSent;  // When last reminder was sent

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('amount', 'float');
        $this->addType('dueDay', 'integer');
        $this->addType('dueMonth', 'integer');
        $this->addType('categoryId', 'integer');
        $this->addType('accountId', 'integer');
        $this->addType('isActive', 'boolean');
        $this->addType('reminderDays', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'amount' => $this->getAmount(),
            'frequency' => $this->getFrequency(),
            'dueDay' => $this->getDueDay(),
            'dueMonth' => $this->getDueMonth(),
            'categoryId' => $this->getCategoryId(),
            'accountId' => $this->getAccountId(),
            'autoDetectPattern' => $this->getAutoDetectPattern(),
            'isActive' => $this->getIsActive(),
            'lastPaidDate' => $this->getLastPaidDate(),
            'nextDueDate' => $this->getNextDueDate(),
            'notes' => $this->getNotes(),
            'createdAt' => $this->getCreatedAt(),
            'reminderDays' => $this->getReminderDays(),
            'lastReminderSent' => $this->getLastReminderSent(),
        ];
    }
}
