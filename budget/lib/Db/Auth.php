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
 * @method string getPasswordHash()
 * @method void setPasswordHash(string $passwordHash)
 * @method ?string getSessionToken()
 * @method void setSessionToken(?string $sessionToken)
 * @method ?string getSessionExpiresAt()
 * @method void setSessionExpiresAt(?string $sessionExpiresAt)
 * @method int getFailedAttempts()
 * @method void setFailedAttempts(int $failedAttempts)
 * @method ?string getLockedUntil()
 * @method void setLockedUntil(?string $lockedUntil)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class Auth extends Entity implements JsonSerializable {
    public $id;
    protected $userId;
    protected $passwordHash;
    protected $sessionToken;
    protected $sessionExpiresAt;
    protected $failedAttempts;
    protected $lockedUntil;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('failedAttempts', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            // Never expose password hash or session token in JSON
            'sessionExpiresAt' => $this->getSessionExpiresAt(),
            'failedAttempts' => $this->getFailedAttempts(),
            'lockedUntil' => $this->getLockedUntil(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}
