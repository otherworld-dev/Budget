<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * Represents a split portion of a transaction assigned to a category.
 *
 * @method int getTransactionId()
 * @method void setTransactionId(int $transactionId)
 * @method int|null getCategoryId()
 * @method void setCategoryId(?int $categoryId)
 * @method string getAmount()
 * @method void setAmount(string $amount)
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class TransactionSplit extends Entity implements JsonSerializable {
    protected int $transactionId = 0;
    protected ?int $categoryId = null;
    protected string $amount = '0';
    protected ?string $description = null;
    protected string $createdAt = '';

    // Non-persisted fields for convenience
    protected ?string $categoryName = null;

    public function __construct() {
        $this->addType('transactionId', 'integer');
        $this->addType('categoryId', 'integer');
        $this->addType('amount', 'string');
        $this->addType('description', 'string');
        $this->addType('createdAt', 'string');
    }

    public function getCategoryName(): ?string {
        return $this->categoryName;
    }

    public function setCategoryName(?string $categoryName): void {
        $this->categoryName = $categoryName;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'transactionId' => $this->getTransactionId(),
            'categoryId' => $this->getCategoryId(),
            'categoryName' => $this->getCategoryName(),
            'amount' => (float) $this->getAmount(),
            'description' => $this->getDescription(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
