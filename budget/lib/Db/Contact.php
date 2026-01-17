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
 * @method string getName()
 * @method void setName(string $name)
 * @method string|null getEmail()
 * @method void setEmail(?string $email)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class Contact extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $email;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'email' => $this->getEmail(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
