<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTransactionId()
 * @method void setTransactionId(int $transactionId)
 * @method int getTagId()
 * @method void setTagId(int $tagId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class TransactionTag extends Entity implements JsonSerializable {
    protected $transactionId;
    protected $tagId;
    protected $createdAt;

    public function __construct() {
        $this->addType('transactionId', 'integer');
        $this->addType('tagId', 'integer');
        $this->addType('createdAt', 'string');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'transactionId' => $this->getTransactionId(),
            'tagId' => $this->getTagId(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
