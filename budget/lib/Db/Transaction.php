<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getAccountId()
 * @method void setAccountId(int $accountId)
 * @method int|null getCategoryId()
 * @method void setCategoryId(?int $categoryId)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method string|null getVendor()
 * @method void setVendor(?string $vendor)
 * @method float getAmount()
 * @method void setAmount(float $amount)
 * @method string getType()
 * @method void setType(string $type)
 * @method string|null getReference()
 * @method void setReference(?string $reference)
 * @method string|null getNotes()
 * @method void setNotes(?string $notes)
 * @method string|null getImportId()
 * @method void setImportId(?string $importId)
 * @method bool getReconciled()
 * @method void setReconciled(bool $reconciled)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 * @method int|null getLinkedTransactionId()
 * @method void setLinkedTransactionId(?int $linkedTransactionId)
 */
class Transaction extends Entity implements JsonSerializable {
    protected $accountId;
    protected $categoryId;
    protected $date;
    protected $description;
    protected $vendor;
    protected $amount;
    protected $type;
    protected $reference;
    protected $notes;
    protected $importId;
    protected $reconciled;
    protected $createdAt;
    protected $updatedAt;
    protected $linkedTransactionId;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('accountId', 'integer');
        $this->addType('categoryId', 'integer');
        $this->addType('amount', 'float');
        $this->addType('reconciled', 'boolean');
        $this->addType('linkedTransactionId', 'integer');
    }

    /**
     * Serialize the transaction to JSON format
     * Returns all fields in camelCase format for frontend consumption
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'accountId' => $this->getAccountId(),
            'categoryId' => $this->getCategoryId(),
            'date' => $this->getDate(),
            'description' => $this->getDescription(),
            'vendor' => $this->getVendor(),
            'amount' => $this->getAmount(),
            'type' => $this->getType(),
            'reference' => $this->getReference(),
            'notes' => $this->getNotes(),
            'importId' => $this->getImportId(),
            'reconciled' => $this->getReconciled(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
            'linkedTransactionId' => $this->getLinkedTransactionId(),
        ];
    }
}