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
 * @method string getStrategy()
 * @method void setStrategy(string $strategy)
 * @method float getExtraPayment()
 * @method void setExtraPayment(float $extraPayment)
 * @method float getLumpSum()
 * @method void setLumpSum(float $lumpSum)
 * @method int getLumpSumMonth()
 * @method void setLumpSumMonth(int $lumpSumMonth)
 * @method string|null getSelectedDebtIds()
 * @method void setSelectedDebtIds(?string $selectedDebtIds)
 * @method string|null getRateOverrides()
 * @method void setRateOverrides(?string $rateOverrides)
 * @method bool|null getIsActive()
 * @method void setIsActive(?bool $isActive)
 * @method float getOriginalTotalDebt()
 * @method void setOriginalTotalDebt(float $originalTotalDebt)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class DebtScenario extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $strategy;
    protected $extraPayment;
    protected $lumpSum;
    protected $lumpSumMonth;
    protected $selectedDebtIds;
    protected $rateOverrides;
    protected $isActive;
    protected $originalTotalDebt;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('extraPayment', 'float');
        $this->addType('lumpSum', 'float');
        $this->addType('lumpSumMonth', 'integer');
        $this->addType('isActive', 'boolean');
        $this->addType('originalTotalDebt', 'float');
    }

    public function getParsedSelectedDebtIds(): array {
        if ($this->selectedDebtIds === null || $this->selectedDebtIds === '') {
            return [];
        }
        return json_decode($this->selectedDebtIds, true) ?? [];
    }

    public function getParsedRateOverrides(): array {
        if ($this->rateOverrides === null || $this->rateOverrides === '') {
            return [];
        }
        return json_decode($this->rateOverrides, true) ?? [];
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'strategy' => $this->getStrategy(),
            'extraPayment' => $this->getExtraPayment(),
            'lumpSum' => $this->getLumpSum(),
            'lumpSumMonth' => $this->getLumpSumMonth(),
            'selectedDebtIds' => $this->getParsedSelectedDebtIds(),
            'rateOverrides' => $this->getParsedRateOverrides(),
            'isActive' => $this->getIsActive() ?? false,
            'originalTotalDebt' => $this->getOriginalTotalDebt(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}
