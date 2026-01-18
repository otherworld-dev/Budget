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
 * @method string|null getProvider()
 * @method void setProvider(?string $provider)
 * @method string getType()
 * @method void setType(string $type)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method float|null getCurrentBalance()
 * @method void setCurrentBalance(?float $currentBalance)
 * @method float|null getMonthlyContribution()
 * @method void setMonthlyContribution(?float $monthlyContribution)
 * @method float|null getExpectedReturnRate()
 * @method void setExpectedReturnRate(?float $expectedReturnRate)
 * @method int|null getRetirementAge()
 * @method void setRetirementAge(?int $retirementAge)
 * @method float|null getAnnualIncome()
 * @method void setAnnualIncome(?float $annualIncome)
 * @method float|null getTransferValue()
 * @method void setTransferValue(?float $transferValue)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class PensionAccount extends Entity implements JsonSerializable {
    public const TYPE_WORKPLACE = 'workplace';
    public const TYPE_PERSONAL = 'personal';
    public const TYPE_SIPP = 'sipp';
    public const TYPE_DEFINED_BENEFIT = 'defined_benefit';
    public const TYPE_STATE = 'state';

    public const VALID_TYPES = [
        self::TYPE_WORKPLACE,
        self::TYPE_PERSONAL,
        self::TYPE_SIPP,
        self::TYPE_DEFINED_BENEFIT,
        self::TYPE_STATE,
    ];

    public const DC_TYPES = [
        self::TYPE_WORKPLACE,
        self::TYPE_PERSONAL,
        self::TYPE_SIPP,
    ];

    protected $userId;
    protected $name;
    protected $provider;
    protected $type;
    protected $currency;
    protected $currentBalance;
    protected $monthlyContribution;
    protected $expectedReturnRate;
    protected $retirementAge;
    protected $annualIncome;
    protected $transferValue;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('currentBalance', 'float');
        $this->addType('monthlyContribution', 'float');
        $this->addType('expectedReturnRate', 'float');
        $this->addType('retirementAge', 'integer');
        $this->addType('annualIncome', 'float');
        $this->addType('transferValue', 'float');
    }

    /**
     * Check if this is a defined contribution pension type.
     */
    public function isDefinedContribution(): bool {
        return in_array($this->getType(), self::DC_TYPES, true);
    }

    /**
     * Check if this is a defined benefit pension type.
     */
    public function isDefinedBenefit(): bool {
        return $this->getType() === self::TYPE_DEFINED_BENEFIT;
    }

    /**
     * Check if this is the state pension.
     */
    public function isStatePension(): bool {
        return $this->getType() === self::TYPE_STATE;
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'provider' => $this->getProvider(),
            'type' => $this->getType(),
            'currency' => $this->getCurrency(),
            'currentBalance' => $this->getCurrentBalance(),
            'monthlyContribution' => $this->getMonthlyContribution(),
            'expectedReturnRate' => $this->getExpectedReturnRate(),
            'retirementAge' => $this->getRetirementAge(),
            'annualIncome' => $this->getAnnualIncome(),
            'transferValue' => $this->getTransferValue(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
            'isDefinedContribution' => $this->isDefinedContribution(),
            'isDefinedBenefit' => $this->isDefinedBenefit(),
            'isStatePension' => $this->isStatePension(),
        ];
    }
}
