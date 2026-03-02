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
 * @method int getAssetId()
 * @method void setAssetId(int $assetId)
 * @method float getValue()
 * @method void setValue(float $value)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class AssetSnapshot extends Entity implements JsonSerializable {
	protected $userId;
	protected $assetId;
	protected $value;
	protected $date;
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('assetId', 'integer');
		$this->addType('value', 'float');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'assetId' => $this->getAssetId(),
			'value' => $this->getValue(),
			'date' => $this->getDate(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
