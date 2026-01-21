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
 * @method int getTransactionId()
 * @method void setTransactionId(int $transactionId)
 * @method int getContactId()
 * @method void setContactId(int $contactId)
 * @method float getAmount()
 * @method void setAmount(float $amount)
 * @method bool getIsSettled()
 * @method void setIsSettled(bool $isSettled)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class ExpenseShare extends Entity implements JsonSerializable {
    protected $userId;
    protected $transactionId;
    protected $contactId;
    protected $amount;
    protected $isSettled;
    protected $notes;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('transactionId', 'integer');
        $this->addType('contactId', 'integer');
        $this->addType('amount', 'float');
        $this->addType('isSettled', 'boolean');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'transactionId' => $this->getTransactionId(),
            'contactId' => $this->getContactId(),
            'amount' => $this->getAmount(),
            'isSettled' => $this->getIsSettled(),
            'notes' => $this->getNotes(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
