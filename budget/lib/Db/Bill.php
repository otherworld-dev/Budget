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
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
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
 * @method string|null getCustomRecurrencePattern()
 * @method void setCustomRecurrencePattern(?string $customRecurrencePattern)
 * @method bool getAutoPayEnabled()
 * @method void setAutoPayEnabled(bool $autoPayEnabled)
 * @method bool getAutoPayFailed()
 * @method void setAutoPayFailed(bool $autoPayFailed)
 * @method bool getIsTransfer()
 * @method void setIsTransfer(bool $isTransfer)
 * @method int|null getDestinationAccountId()
 * @method void setDestinationAccountId(?int $destinationAccountId)
 * @method string|null getTransferDescriptionPattern()
 * @method void setTransferDescriptionPattern(?string $transferDescriptionPattern)
 * @method string|null getTagIds()
 * @method void setTagIds(?string $tagIds)
 * @method string|null getStartDate()
 * @method void setStartDate(?string $startDate)
 * @method string|null getEndDate()
 * @method void setEndDate(?string $endDate)
 * @method int|null getRemainingPayments()
 * @method void setRemainingPayments(?int $remainingPayments)
 * @method string|null getSplitTemplate()
 * @method void setSplitTemplate(?string $splitTemplate)
 * @method bool getExcludedFromForecast()
 * @method void setExcludedFromForecast(bool $excludedFromForecast)
 * @method bool|null getCreateTransaction()
 * @method void setCreateTransaction(bool $createTransaction)
 */
class Bill extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $description;
    protected $amount;
    protected $frequency;       // monthly, weekly, yearly, quarterly, custom
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
    protected $customRecurrencePattern;  // JSON pattern for custom frequency
    protected $autoPayEnabled;    // Automatically mark bill as paid when due
    protected $autoPayFailed;     // Tracks if last auto-pay attempt failed
    protected $isTransfer;        // Flag to distinguish transfers from bills
    protected $destinationAccountId;  // Target account for transfers
    protected $transferDescriptionPattern;  // Optional description pattern for matching
    protected $tagIds;                     // JSON array of tag IDs to apply to created transactions
    protected $startDate;                  // Optional date the bill starts occurring
    protected $endDate;                    // Optional end date for auto-deactivation
    protected $remainingPayments;          // Optional countdown of payments before auto-deactivation
    protected $splitTemplate;              // JSON array of split definitions for auto-splitting transactions
    protected $excludedFromForecast;       // Extraordinary recurring item: keep its transactions out of the forecast
    protected $createTransaction;          // Pre-create a scheduled transaction for the next occurrence (null = legacy rows, treated as true)

    // Non-persisted: set by BillService when enriching API responses
    protected ?string $currency = null;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('amount', 'float');
        $this->addType('dueDay', 'integer');
        $this->addType('dueMonth', 'integer');
        $this->addType('categoryId', 'integer');
        $this->addType('accountId', 'integer');
        $this->addType('isActive', 'boolean');
        $this->addType('reminderDays', 'integer');
        $this->addType('autoPayEnabled', 'boolean');
        $this->addType('autoPayFailed', 'boolean');
        $this->addType('isTransfer', 'boolean');
        $this->addType('destinationAccountId', 'integer');
        $this->addType('remainingPayments', 'integer');
        $this->addType('excludedFromForecast', 'boolean');
        $this->addType('createTransaction', 'boolean');
    }

    public function getCurrency(): ?string {
        return $this->currency;
    }

    public function setCurrency(?string $currency): void {
        $this->currency = $currency;
    }

    /**
     * Get tag IDs as an array (decoded from JSON).
     * @return int[]
     */
    public function getTagIdsArray(): array {
        $raw = $this->getTagIds();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /**
     * Set tag IDs from an array (encodes to JSON).
     * @param int[] $tagIds
     */
    public function setTagIdsArray(array $tagIds): void {
        $this->setTagIds(empty($tagIds) ? null : json_encode(array_values(array_map('intval', $tagIds))));
    }

    /**
     * Get split template as an array (decoded from JSON).
     * @return array[] Array of {categoryId, amount, description}
     */
    public function getSplitTemplateArray(): array {
        $raw = $this->getSplitTemplate();
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Set split template from an array (encodes to JSON).
     * @param array[]|null $splits Array of {categoryId, amount, description}
     */
    public function setSplitTemplateArray(?array $splits): void {
        $this->setSplitTemplate(empty($splits) ? null : json_encode(array_values($splits)));
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'description' => $this->getDescription(),
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
            'customRecurrencePattern' => $this->getCustomRecurrencePattern(),
            'autoPayEnabled' => $this->getAutoPayEnabled(),
            'autoPayFailed' => $this->getAutoPayFailed(),
            'isTransfer' => $this->getIsTransfer() ?? false,
            'destinationAccountId' => $this->getDestinationAccountId(),
            'transferDescriptionPattern' => $this->getTransferDescriptionPattern(),
            'tagIds' => $this->getTagIdsArray(),
            'startDate' => $this->getStartDate(),
            'endDate' => $this->getEndDate(),
            'remainingPayments' => $this->getRemainingPayments(),
            'splitTemplate' => $this->getSplitTemplateArray(),
            'excludedFromForecast' => $this->getExcludedFromForecast() ?? false,
            'createTransaction' => $this->getCreateTransaction() ?? true,
            'currency' => $this->getCurrency(),
        ];
    }
}
