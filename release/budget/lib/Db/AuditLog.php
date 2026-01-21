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
 * @method string getAction()
 * @method void setAction(string $action)
 * @method string|null getEntityType()
 * @method void setEntityType(?string $entityType)
 * @method int|null getEntityId()
 * @method void setEntityId(?int $entityId)
 * @method string|null getIpAddress()
 * @method void setIpAddress(?string $ipAddress)
 * @method string|null getUserAgent()
 * @method void setUserAgent(?string $userAgent)
 * @method string|null getDetails()
 * @method void setDetails(?string $details)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class AuditLog extends Entity implements JsonSerializable {
    protected $userId;
    protected $action;
    protected $entityType;
    protected $entityId;
    protected $ipAddress;
    protected $userAgent;
    protected $details;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('entityId', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'action' => $this->getAction(),
            'entityType' => $this->getEntityType(),
            'entityId' => $this->getEntityId(),
            'ipAddress' => $this->getIpAddress(),
            'userAgent' => $this->getUserAgent(),
            'details' => $this->getDetails() ? json_decode($this->getDetails(), true) : null,
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
