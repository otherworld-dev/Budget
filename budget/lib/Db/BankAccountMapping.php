<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getConnectionId()
 * @method void setConnectionId(int $connectionId)
 * @method string getExternalAccountId()
 * @method void setExternalAccountId(string $externalAccountId)
 * @method string|null getExternalAccountName()
 * @method void setExternalAccountName(?string $externalAccountName)
 * @method int|null getBudgetAccountId()
 * @method void setBudgetAccountId(?int $budgetAccountId)
 * @method bool|null getEnabled()
 * @method void setEnabled(?bool $enabled)
 * @method string|null getRequisitionId()
 * @method void setRequisitionId(?string $requisitionId)
 * @method string|null getConsentExpires()
 * @method void setConsentExpires(?string $consentExpires)
 * @method string|null getLastBalance()
 * @method void setLastBalance(?string $lastBalance)
 * @method string|null getLastCurrency()
 * @method void setLastCurrency(?string $lastCurrency)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class BankAccountMapping extends Entity implements JsonSerializable {
    protected $connectionId;
    protected $externalAccountId;
    protected $externalAccountName;
    protected $budgetAccountId;
    protected $enabled;
    protected $requisitionId;
    protected $consentExpires;
    protected $lastBalance;
    protected $lastCurrency;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('connectionId', 'integer');
        $this->addType('budgetAccountId', 'integer');
        $this->addType('enabled', 'boolean');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'connectionId' => $this->getConnectionId(),
            'externalAccountId' => $this->getExternalAccountId(),
            'externalAccountName' => $this->getExternalAccountName(),
            'budgetAccountId' => $this->getBudgetAccountId(),
            'enabled' => $this->getEnabled() ?? false,
            'requisitionId' => $this->getRequisitionId(),
            'consentExpires' => $this->getConsentExpires(),
            'lastBalance' => $this->getLastBalance(),
            'lastCurrency' => $this->getLastCurrency(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}
