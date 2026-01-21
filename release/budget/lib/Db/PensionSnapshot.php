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
 * @method int getPensionId()
 * @method void setPensionId(int $pensionId)
 * @method float getBalance()
 * @method void setBalance(float $balance)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class PensionSnapshot extends Entity implements JsonSerializable {
    protected $userId;
    protected $pensionId;
    protected $balance;
    protected $date;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('pensionId', 'integer');
        $this->addType('balance', 'float');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'pensionId' => $this->getPensionId(),
            'balance' => $this->getBalance(),
            'date' => $this->getDate(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
