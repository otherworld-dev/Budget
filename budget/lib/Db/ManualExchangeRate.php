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
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method string getRatePerEur()
 * @method void setRatePerEur(string $ratePerEur)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class ManualExchangeRate extends Entity implements JsonSerializable {
	protected $userId;
	protected $currency;
	protected $ratePerEur;
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'currency' => $this->getCurrency(),
			'ratePerEur' => $this->getRatePerEur(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
