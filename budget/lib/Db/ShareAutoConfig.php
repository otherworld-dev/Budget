<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * One row per (share, entity_type) that the owner has opted into auto-sharing:
 * newly created entities of that type are automatically added to the share at
 * the stored permission. Row presence = enabled (#306).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getShareId()
 * @method void setShareId(int $shareId)
 * @method string getEntityType()
 * @method void setEntityType(string $entityType)
 * @method string getPermission()
 * @method void setPermission(string $permission)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class ShareAutoConfig extends Entity implements JsonSerializable {
	protected $shareId;
	protected $entityType;
	protected $permission;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('shareId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'shareId' => $this->getShareId(),
			'entityType' => $this->getEntityType(),
			'permission' => $this->getPermission(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
