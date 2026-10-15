<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * A receipt attached to a transaction. References a file in the user's own
 * Files space by fileId — the app never owns or deletes the file itself.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method int getTransactionId()
 * @method void setTransactionId(int $transactionId)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getFileId()
 * @method void setFileId(int $fileId)
 * @method string|null getFileName()
 * @method void setFileName(?string $fileName)
 * @method string|null getMimeType()
 * @method void setMimeType(?string $mimeType)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class Attachment extends Entity implements JsonSerializable {
	protected $transactionId;
	protected $userId;
	protected $fileId;
	protected $fileName;
	protected $mimeType;
	protected $createdAt;

	public function __construct() {
		$this->addType('id', 'integer');
		$this->addType('transactionId', 'integer');
		$this->addType('fileId', 'integer');
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->getId(),
			'transactionId' => $this->getTransactionId(),
			'fileId' => $this->getFileId(),
			'fileName' => $this->getFileName(),
			'mimeType' => $this->getMimeType(),
			'createdAt' => $this->getCreatedAt(),
		];
	}
}
