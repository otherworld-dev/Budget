<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getCurrentUserId()
 * @method void setCurrentUserId(string $currentUserId)
 * @method string getOwnerUserId()
 * @method void setOwnerUserId(string $ownerUserId)
 * @method string getSessionToken()
 * @method void setSessionToken(string $sessionToken)
 * @method string getExpiresAt()
 * @method void setExpiresAt(string $expiresAt)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class SharedSession extends Entity implements JsonSerializable {
	public $id;
	protected $currentUserId;
	protected $ownerUserId;
	protected $sessionToken;
	protected $expiresAt;
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'currentUserId' => $this->getCurrentUserId(),
			'ownerUserId' => $this->getOwnerUserId(),
			// Never expose session token in JSON
			'expiresAt' => $this->getExpiresAt(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
