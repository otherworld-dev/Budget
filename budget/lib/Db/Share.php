<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getOwnerUserId()
 * @method void setOwnerUserId(string $ownerUserId)
 * @method string getSharedWithUserId()
 * @method void setSharedWithUserId(string $sharedWithUserId)
 * @method string getPermissionLevel()
 * @method void setPermissionLevel(string $permissionLevel)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class Share extends Entity implements JsonSerializable {
	protected $ownerUserId;
	protected $sharedWithUserId;
	protected $permissionLevel;
	protected $createdAt;
	protected $updatedAt;

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'ownerUserId' => $this->ownerUserId,
			'sharedWithUserId' => $this->sharedWithUserId,
			'permissionLevel' => $this->permissionLevel,
			'createdAt' => $this->createdAt,
			'updatedAt' => $this->updatedAt,
		];
	}
}
