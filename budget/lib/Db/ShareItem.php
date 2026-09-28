<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getShareId()
 * @method void setShareId(int $shareId)
 * @method string getEntityType()
 * @method void setEntityType(string $entityType)
 * @method int getEntityId()
 * @method void setEntityId(int $entityId)
 * @method string getPermission()
 * @method void setPermission(string $permission)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class ShareItem extends Entity implements JsonSerializable {
	protected $shareId;
	protected $entityType;
	protected $entityId;
	protected $permission;
	protected $createdAt;
	protected $updatedAt;

	public const PERMISSION_READ = 'read';
	public const PERMISSION_WRITE = 'write';
	/** Write plus what write keeps for the owner (see FULL_CONTROL_TYPES) */
	public const PERMISSION_FULL = 'full';

	public const TYPE_ACCOUNT = 'account';
	public const TYPE_CATEGORY = 'category';
	public const TYPE_BILL = 'bill';
	public const TYPE_RECURRING_INCOME = 'recurring_income';
	public const TYPE_SAVINGS_GOAL = 'savings_goal';
	public const TYPE_IMPORT_RULE = 'import_rule';
	public const TYPE_PROJECT = 'project';

	public const VALID_TYPES = [
		self::TYPE_ACCOUNT,
		self::TYPE_CATEGORY,
		self::TYPE_BILL,
		self::TYPE_RECURRING_INCOME,
		self::TYPE_SAVINGS_GOAL,
		self::TYPE_IMPORT_RULE,
		self::TYPE_PROJECT,
	];

	/**
	 * Types a share may grant Full control on. For bills, recurring income,
	 * savings goals and projects it adds deleting them, which write does not
	 * allow. For categories it adds their structure (type, parent,
	 * subcategories, budgets, scope flags) but never deleting one, as that
	 * cascades through the owner's tree and transactions. Accounts and import
	 * rules have no Full control: deleting an account takes its transactions
	 * with it, and a rule is cheap for the owner to remove.
	 */
	public const FULL_CONTROL_TYPES = [
		self::TYPE_CATEGORY,
		self::TYPE_BILL,
		self::TYPE_RECURRING_INCOME,
		self::TYPE_SAVINGS_GOAL,
		self::TYPE_PROJECT,
	];

	public static function isValidPermission(string $permission, string $entityType): bool {
		if ($permission === self::PERMISSION_FULL) {
			return in_array($entityType, self::FULL_CONTROL_TYPES, true);
		}
		return in_array($permission, [self::PERMISSION_READ, self::PERMISSION_WRITE], true);
	}

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('shareId', 'integer');
		$this->addType('entityId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'shareId' => $this->getShareId(),
			'entityType' => $this->getEntityType(),
			'entityId' => $this->getEntityId(),
			'permission' => $this->getPermission(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
