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
 * @method float getAmount()
 * @method void setAmount(float $amount)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class PensionContribution extends Entity implements JsonSerializable {
    protected $userId;
    protected $pensionId;
    protected $amount;
    protected $date;
    protected $note;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('pensionId', 'integer');
        $this->addType('amount', 'float');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'pensionId' => $this->getPensionId(),
            'amount' => $this->getAmount(),
            'date' => $this->getDate(),
            'note' => $this->getNote(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
