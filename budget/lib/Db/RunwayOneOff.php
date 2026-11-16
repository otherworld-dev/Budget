<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A planned one-off on the runway forecast: a dated amount, negative for
 * money out, that the projection applies on top of the monthly average.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getDescription()
 * @method void setDescription(string $description)
 * @method string getAmount()
 * @method void setAmount(string $amount)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class RunwayOneOff extends Entity implements JsonSerializable {
	protected $userId;
	protected $description;
	protected $amount;
	protected $date;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'description' => $this->getDescription(),
			'amount' => $this->getAmount(),
			'date' => $this->getDate(),
		];
	}
}
