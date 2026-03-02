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
 * @method string|null getDescription()
 * @method void setDescription(?string $description)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method float|null getCurrentValue()
 * @method void setCurrentValue(?float $currentValue)
 * @method float|null getPurchasePrice()
 * @method void setPurchasePrice(?float $purchasePrice)
 * @method string|null getPurchaseDate()
 * @method void setPurchaseDate(?string $purchaseDate)
 * @method float|null getAnnualChangeRate()
 * @method void setAnnualChangeRate(?float $annualChangeRate)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class Asset extends Entity implements JsonSerializable {
	public const TYPE_REAL_ESTATE = 'real_estate';
	public const TYPE_VEHICLE = 'vehicle';
	public const TYPE_JEWELRY = 'jewelry';
	public const TYPE_COLLECTIBLES = 'collectibles';
	public const TYPE_OTHER = 'other';

	public const VALID_TYPES = [
		self::TYPE_REAL_ESTATE,
		self::TYPE_VEHICLE,
		self::TYPE_JEWELRY,
		self::TYPE_COLLECTIBLES,
		self::TYPE_OTHER,
	];

	protected $userId;
	protected $name;
	protected $type;
	protected $description;
	protected $currency;
	protected $currentValue;
	protected $purchasePrice;
	protected $purchaseDate;
	protected $annualChangeRate;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('currentValue', 'float');
		$this->addType('purchasePrice', 'float');
		$this->addType('annualChangeRate', 'float');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'name' => $this->getName(),
			'type' => $this->getType(),
			'description' => $this->getDescription(),
			'currency' => $this->getCurrency(),
			'currentValue' => $this->getCurrentValue(),
			'purchasePrice' => $this->getPurchasePrice(),
			'purchaseDate' => $this->getPurchaseDate(),
			'annualChangeRate' => $this->getAnnualChangeRate(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
