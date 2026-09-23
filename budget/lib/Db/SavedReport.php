<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A named, re-runnable report configuration (#299). `config` is a JSON string
 * holding the report type, date selection, selected accounts, tags and any
 * report-specific options.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getConfig()
 * @method void setConfig(string $config)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class SavedReport extends Entity {
	protected $userId;
	protected $name;
	protected $config;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
	}

	/**
	 * Serialise for the API, decoding the stored config JSON into an object.
	 */
	public function jsonSerialize(): array {
		$config = json_decode($this->config ?? '{}', true);
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'config' => is_array($config) ? $config : [],
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
