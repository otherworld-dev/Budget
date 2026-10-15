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
 * @method string|null getAnchorDate()
 * @method void setAnchorDate(?string $anchorDate)
 * @method string|null getPostUndoState()
 * @method void setPostUndoState(?string $postUndoState)
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
	/** The date the schedule started from: its day and month are the schedule's */
	protected $anchorDate;
	/** JSON: what the last Post now changed, so it can be put back */
	protected $postUndoState;
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

	/**
	 * What the last Post now changed: the dates before it and the
	 * contribution it recorded. Null when there is nothing to undo.
	 *
	 * @return array{nextDueDate: string, lastPostedDate: ?string, isActive: bool, contributionId: int, contributionDate: string, amount: float}|null
	 */
	public function postUndo(): ?array {
		$raw = $this->getPostUndoState();
		$state = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
		return (is_array($state) && !empty($state['nextDueDate'])) ? $state : null;
	}

	/**
	 * Whether $contribution is the one the last Post now recorded. The date
	 * and amount are checked as well as the id, as a restored backup gives
	 * contributions new ids while this state keeps the old one.
	 */
	public function isLastPost(PensionContribution $contribution): bool {
		$state = $this->postUndo();
		return $state !== null
			&& (int)($state['contributionId'] ?? 0) === $contribution->getId()
			&& $contribution->getPensionId() === $this->getPensionId()
			&& ($state['contributionDate'] ?? null) === $contribution->getDate()
			&& abs((float)($state['amount'] ?? 0) - (float)$contribution->getAmount()) < 0.005;
	}

	/** Put the dates back to before the last Post now */
	public function revertPost(): void {
		$state = $this->postUndo();
		if ($state === null) {
			return;
		}
		$this->setNextDueDate((string)$state['nextDueDate']);
		$this->setLastPostedDate($state['lastPostedDate'] ?? null);
		$this->setIsActive((bool)($state['isActive'] ?? true));
		$this->setPostUndoState(null);
		$this->setUpdatedAt(date('Y-m-d H:i:s'));
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
			'anchorDate' => $this->getAnchorDate(),
			'canUndoPost' => $this->postUndo() !== null,
			'createdAt' => $this->getCreatedAt(),
			'updatedAt' => $this->getUpdatedAt(),
		];
	}
}
