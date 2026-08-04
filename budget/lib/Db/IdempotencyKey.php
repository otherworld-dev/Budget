<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One idempotency key, remembered so a retried POST answers with the
 * transaction the first attempt recorded. See Version001000093.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getIdemKey()
 * @method void setIdemKey(string $idemKey)
 * @method int getTransactionId()
 * @method void setTransactionId(int $transactionId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class IdempotencyKey extends Entity implements JsonSerializable {
    protected $userId;
    protected $idemKey;
    protected $transactionId;
    protected $createdAt;

    public function __construct() {
        $this->addType('transactionId', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'idemKey' => $this->getIdemKey(),
            'transactionId' => $this->getTransactionId(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
