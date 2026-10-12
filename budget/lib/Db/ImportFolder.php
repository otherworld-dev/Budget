<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A subfolder of the user's import folder in Files whose statements are
 * imported in the background into one account (#425).
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method int getAccountId()
 * @method void setAccountId(int $accountId)
 * @method int|null getTemplateId()
 * @method void setTemplateId(?int $templateId)
 * @method string|null getMode()
 * @method void setMode(string $mode)
 * @method bool|null getEnabled()
 * @method void setEnabled(bool $enabled)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class ImportFolder extends Entity implements JsonSerializable {
	protected $userId;
	protected $name;
	protected $accountId;
	protected $templateId;
	protected $mode;
	protected $enabled;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('accountId', 'integer');
		$this->addType('templateId', 'integer');
		$this->addType('enabled', 'boolean');
	}

	public function isEnabled(): bool {
		// NULL (a row older than the default, or restored without it) is enabled
		return $this->getEnabled() ?? true;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'accountId' => $this->getAccountId(),
			'templateId' => $this->getTemplateId(),
			'mode' => $this->getMode() ?? 'auto',
			'enabled' => $this->isEnabled(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
