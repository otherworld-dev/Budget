<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCA\Budget\Attribute\Encrypted;
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
 * @method float|null getMinimumPayment()
 * @method void setMinimumPayment(?float $minimumPayment)
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

    #[Encrypted]
    protected $accountNumber;

    #[Encrypted]
    protected $routingNumber;

    #[Encrypted]
    protected $sortCode;

    #[Encrypted]
    protected $iban;

    #[Encrypted]
    protected $swiftBic;

    protected $accountHolderName;
    protected $openingDate;
    protected $interestRate;
    protected $creditLimit;
    protected $overdraftLimit;
    protected $minimumPayment;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('balance', 'float');
        $this->addType('interestRate', 'float');
        $this->addType('creditLimit', 'float');
        $this->addType('overdraftLimit', 'float');
        $this->addType('minimumPayment', 'float');
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

    /**
     * Default serialization returns masked sensitive data.
     */
    public function jsonSerialize(): array {
        return $this->toArrayMasked();
    }

    /**
     * Get array representation with sensitive fields masked.
     */
    public function toArrayMasked(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'type' => $this->getType(),
            'balance' => $this->getBalance(),
            'currency' => $this->getCurrency(),
            'institution' => $this->getInstitution(),
            'accountNumber' => $this->maskAccountNumber($this->getAccountNumber()),
            'routingNumber' => $this->maskRoutingNumber($this->getRoutingNumber()),
            'sortCode' => $this->maskSortCode($this->getSortCode()),
            'iban' => $this->maskIban($this->getIban()),
            'swiftBic' => $this->maskSwiftBic($this->getSwiftBic()),
            'accountHolderName' => $this->getAccountHolderName(),
            'openingDate' => $this->getOpeningDate(),
            'interestRate' => $this->getInterestRate(),
            'creditLimit' => $this->getCreditLimit(),
            'overdraftLimit' => $this->getOverdraftLimit(),
            'minimumPayment' => $this->getMinimumPayment(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
            'hasSensitiveData' => $this->hasSensitiveData(),
        ];
    }

    /**
     * Get array representation with all fields (including sensitive) unmasked.
     * Only use this for the reveal endpoint with proper audit logging.
     */
    public function toArrayFull(): array {
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
            'minimumPayment' => $this->getMinimumPayment(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }

    /**
     * Check if this account has any sensitive banking data.
     */
    public function hasSensitiveData(): bool {
        return !empty($this->getAccountNumber())
            || !empty($this->getRoutingNumber())
            || !empty($this->getSortCode())
            || !empty($this->getIban())
            || !empty($this->getSwiftBic());
    }

    /**
     * Get list of which sensitive fields are populated.
     */
    public function getPopulatedSensitiveFields(): array {
        $fields = [];
        if (!empty($this->getAccountNumber())) $fields[] = 'accountNumber';
        if (!empty($this->getRoutingNumber())) $fields[] = 'routingNumber';
        if (!empty($this->getSortCode())) $fields[] = 'sortCode';
        if (!empty($this->getIban())) $fields[] = 'iban';
        if (!empty($this->getSwiftBic())) $fields[] = 'swiftBic';
        return $fields;
    }

    /**
     * Mask account number: show last 4 digits only.
     * Example: "12345678" -> "****5678"
     */
    private function maskAccountNumber(?string $value): ?string {
        if ($value === null || strlen($value) < 4) {
            return $value;
        }
        return str_repeat('*', strlen($value) - 4) . substr($value, -4);
    }

    /**
     * Mask routing number: show last 4 digits only.
     * Example: "123456789" -> "*****6789"
     */
    private function maskRoutingNumber(?string $value): ?string {
        if ($value === null || strlen($value) < 4) {
            return $value;
        }
        return str_repeat('*', strlen($value) - 4) . substr($value, -4);
    }

    /**
     * Mask sort code: show last 2 digits only.
     * Example: "12-34-56" -> "**-**-56"
     */
    private function maskSortCode(?string $value): ?string {
        if ($value === null || strlen($value) < 2) {
            return $value;
        }
        // Handle formatted (12-34-56) and unformatted (123456)
        if (strpos($value, '-') !== false) {
            $parts = explode('-', $value);
            if (count($parts) === 3) {
                return '**-**-' . $parts[2];
            }
        }
        return str_repeat('*', strlen($value) - 2) . substr($value, -2);
    }

    /**
     * Mask IBAN: show country code and last 4 characters.
     * Example: "DE89370400440532013000" -> "DE**************3000"
     */
    private function maskIban(?string $value): ?string {
        if ($value === null || strlen($value) < 6) {
            return $value;
        }
        $countryCode = substr($value, 0, 2);
        $lastFour = substr($value, -4);
        $middleLength = strlen($value) - 6;
        return $countryCode . str_repeat('*', $middleLength) . $lastFour;
    }

    /**
     * Mask SWIFT/BIC: show first 4 and last 3 characters.
     * Example: "DEUTDEFF500" -> "DEUT***500"
     */
    private function maskSwiftBic(?string $value): ?string {
        if ($value === null || strlen($value) < 7) {
            return $value;
        }
        $first = substr($value, 0, 4);
        $last = substr($value, -3);
        $middleLength = strlen($value) - 7;
        return $first . str_repeat('*', $middleLength) . $last;
    }
}