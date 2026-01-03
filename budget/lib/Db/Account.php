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
 * @method string getType()
 * @method void setType(string $type)
 * @method float getBalance()
 * @method void setBalance(float $balance)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method string|null getInstitution()
 * @method void setInstitution(?string $institution)
 * @method string|null getAccountNumber()
 * @method void setAccountNumber(?string $accountNumber)
 * @method string|null getRoutingNumber()
 * @method void setRoutingNumber(?string $routingNumber)
 * @method string|null getSortCode()
 * @method void setSortCode(?string $sortCode)
 * @method string|null getIban()
 * @method void setIban(?string $iban)
 * @method string|null getSwiftBic()
 * @method void setSwiftBic(?string $swiftBic)
 * @method string|null getAccountHolderName()
 * @method void setAccountHolderName(?string $accountHolderName)
 * @method string|null getOpeningDate()
 * @method void setOpeningDate(?string $openingDate)
 * @method float|null getInterestRate()
 * @method void setInterestRate(?float $interestRate)
 * @method float|null getCreditLimit()
 * @method void setCreditLimit(?float $creditLimit)
 * @method float|null getOverdraftLimit()
 * @method void setOverdraftLimit(?float $overdraftLimit)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class Account extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $type;
    protected $balance;
    protected $currency;
    protected $institution;
    protected $accountNumber;
    protected $routingNumber;
    protected $sortCode;
    protected $iban;
    protected $swiftBic;
    protected $accountHolderName;
    protected $openingDate;
    protected $interestRate;
    protected $creditLimit;
    protected $overdraftLimit;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('balance', 'float');
        $this->addType('interestRate', 'float');
        $this->addType('creditLimit', 'float');
        $this->addType('overdraftLimit', 'float');
    }

    /**
     * Explicit setter for currency
     * Note: This overrides the magic setter to ensure proper field tracking
     */
    public function setCurrency(string $currency): void {
        // Only update if value changed (same logic as parent setter)
        if ($currency === $this->currency) {
            return;
        }
        $this->markFieldUpdated('currency');
        $this->currency = $currency;
    }

    /**
     * Explicit getter for currency
     */
    public function getCurrency(): string {
        return $this->currency ?? '';
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'type' => $this->getType(),
            'balance' => $this->getBalance(),
            'currency' => $this->getCurrency(),
            'institution' => $this->getInstitution(),
            'accountNumber' => $this->getAccountNumber(),
            'routingNumber' => $this->getRoutingNumber(),
            'sortCode' => $this->getSortCode(),
            'iban' => $this->getIban(),
            'swiftBic' => $this->getSwiftBic(),
            'accountHolderName' => $this->getAccountHolderName(),
            'openingDate' => $this->getOpeningDate(),
            'interestRate' => $this->getInterestRate(),
            'creditLimit' => $this->getCreditLimit(),
            'overdraftLimit' => $this->getOverdraftLimit(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}