<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A per-viewer "hide from my reports" mute on a category (typically one shared
 * with the user). Row presence = muted for that user; the owner's
 * excluded_from_reports flag is unaffected.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getCategoryId()
 * @method void setCategoryId(int $categoryId)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class CategoryMute extends Entity implements JsonSerializable {
	protected $userId;
	protected $categoryId;
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('categoryId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'categoryId' => $this->getCategoryId(),
		];
	}
}
