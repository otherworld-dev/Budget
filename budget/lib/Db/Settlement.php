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
 * @method int getContactId()
 * @method void setContactId(int $contactId)
 * @method float getAmount()
 * @method void setAmount(float $amount)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class Settlement extends Entity implements JsonSerializable {
    protected $userId;
    protected $contactId;
    protected $amount;
    protected $date;
    protected $notes;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('contactId', 'integer');
        $this->addType('amount', 'float');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'contactId' => $this->getContactId(),
            'amount' => $this->getAmount(),
            'date' => $this->getDate(),
            'notes' => $this->getNotes(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
