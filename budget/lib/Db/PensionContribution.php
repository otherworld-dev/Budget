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
 * @method int getPensionId()
 * @method void setPensionId(int $pensionId)
 * @method float getAmount()
 * @method void setAmount(float $amount)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string|null getNote()
 * @method void setNote(?string $note)
 * @method int|null getTransactionId()
 * @method void setTransactionId(?int $transactionId)
 * @method int|null getSourceAccountId()
 * @method void setSourceAccountId(?int $sourceAccountId)
 * @method string|null getKind()
 * @method void setKind(?string $kind)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class PensionContribution extends Entity implements JsonSerializable {
	public const KIND_CONTRIBUTION = 'contribution';
	public const KIND_WITHDRAWAL = 'withdrawal';

	protected $userId;
	protected $pensionId;
	protected $amount;
	protected $date;
	protected $note;
	protected $transactionId;
	protected $sourceAccountId;
	protected $kind;
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('pensionId', 'integer');
		$this->addType('amount', 'float');
		$this->addType('transactionId', 'integer');
		$this->addType('sourceAccountId', 'integer');
	}

	public function isWithdrawal(): bool {
		return $this->getKind() === self::KIND_WITHDRAWAL;
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'userId' => $this->getUserId(),
			'pensionId' => $this->getPensionId(),
			'amount' => $this->getAmount(),
			'date' => $this->getDate(),
			'note' => $this->getNote(),
			'transactionId' => $this->getTransactionId(),
			'sourceAccountId' => $this->getSourceAccountId(),
			'kind' => $this->getKind() ?? self::KIND_CONTRIBUTION,
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
