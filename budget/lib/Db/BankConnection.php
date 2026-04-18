<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCA\Budget\Attribute\Encrypted;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getProvider()
 * @method void setProvider(string $provider)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getCredentials()
 * @method void setCredentials(string $credentials)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method string|null getLastSyncAt()
 * @method void setLastSyncAt(?string $lastSyncAt)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class BankConnection extends Entity implements JsonSerializable {
    protected $userId;
    protected $provider;
    protected $name;

    #[Encrypted]
    protected $credentials;

    protected $status;
    protected $lastSyncAt;
    protected $lastError;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
    }

    public function jsonSerialize(): array {
        // Never expose credentials in API responses
        return [
            'id' => $this->getId(),
            'provider' => $this->getProvider(),
            'name' => $this->getName(),
            'status' => $this->getStatus(),
            'lastSyncAt' => $this->getLastSyncAt(),
            'lastError' => $this->getLastError(),
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }
}
