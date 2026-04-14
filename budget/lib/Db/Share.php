<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getOwnerUserId()
 * @method void setOwnerUserId(string $ownerUserId)
 * @method string getSharedWithUserId()
 * @method void setSharedWithUserId(string $sharedWithUserId)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class Share extends Entity implements JsonSerializable {
    public $id;
    protected $ownerUserId;
    protected $sharedWithUserId;
    protected $status;
    protected $createdAt;
    protected $updatedAt;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_DECLINED = 'declined';

    public function __construct() {
        $this->addType('id', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'ownerUserId' => $this->getOwnerUserId(),
            'sharedWithUserId' => $this->getSharedWithUserId(),
            'status' => $this->getStatus(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}
