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
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method float getRate()
 * @method void setRate(float $rate)
 * @method string getCompoundingFrequency()
 * @method void setCompoundingFrequency(string $compoundingFrequency)
 * @method string getEffectiveDate()
 * @method void setEffectiveDate(string $effectiveDate)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class InterestRate extends Entity implements JsonSerializable {
    protected $accountId;
    protected $userId;
    protected $rate;
    protected $compoundingFrequency;
    protected $effectiveDate;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('accountId', 'integer');
        $this->addType('rate', 'float');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'accountId' => $this->getAccountId(),
            'userId' => $this->getUserId(),
            'rate' => $this->getRate(),
            'compoundingFrequency' => $this->getCompoundingFrequency(),
            'effectiveDate' => $this->getEffectiveDate(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
