<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\Entity;

/**
 * @method int getAccountId()
 * @method void setAccountId(int $accountId)
 * @method string getImportId()
 * @method void setImportId(string $importId)
 * @method string getDismissedAt()
 * @method void setDismissedAt(string $dismissedAt)
 */
class DismissedImport extends Entity {
    protected $accountId;
    protected $importId;
    protected $dismissedAt;

    public function __construct() {
        $this->addType('accountId', 'integer');
        $this->addType('importId', 'string');
        $this->addType('dismissedAt', 'string');
    }
}
