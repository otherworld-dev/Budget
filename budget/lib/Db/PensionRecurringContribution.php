<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A scheduled (recurring) pension contribution (#251). When auto-post is enabled
 * the background job creates the due contribution — and, if a source account is
 * set, the linked bank transfer (#304) — then advances next_due_date.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getPensionId()
 * @method void setPensionId(int $pensionId)
 * @method float getAmount()
 * @method void setAmount(float $amount)
 * @method string getFrequency()
 * @method void setFrequency(string $frequency)
 * @method int|null getSourceAccountId()
 * @method void setSourceAccountId(?int $sourceAccountId)
 * @method bool getAutoPostEnabled()
 * @method void setAutoPostEnabled(bool $autoPostEnabled)
 * @method string getNextDueDate()
 * @method void setNextDueDate(string $nextDueDate)
 * @method string|null getLastPostedDate()
 * @method void setLastPostedDate(?string $lastPostedDate)
 * @method bool getIsActive()
 * @method void setIsActive(bool $isActive)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class PensionRecurringContribution extends Entity implements JsonSerializable {
	protected $userId;
	protected $pensionId;
	protected $amount;
	protected $frequency;
	protected $sourceAccountId;
	protected $autoPostEnabled;
	protected $nextDueDate;
	protected $lastPostedDate;
	protected $isActive;
	protected $note;
	protected $createdAt;
	protected $updatedAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('pensionId', 'integer');
		$this->addType('amount', 'float');
		$this->addType('sourceAccountId', 'integer');
		$this->addType('autoPostEnabled', 'boolean');
		$this->addType('isActive', 'boolean');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'pensionId' => $this->getPensionId(),
			'amount' => $this->getAmount(),
			'frequency' => $this->getFrequency(),
			'sourceAccountId' => $this->getSourceAccountId(),
			'autoPostEnabled' => $this->getAutoPostEnabled() ?? false,
			'nextDueDate' => $this->getNextDueDate(),
			'lastPostedDate' => $this->getLastPostedDate(),
			'isActive' => $this->getIsActive() ?? true,
			'note' => $this->getNote(),
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
