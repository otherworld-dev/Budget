<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Remembers which destination account a source account (OFX account id/number
 * or QIF account name) was routed to, so future imports can pre-fill routing.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getFormat()
 * @method void setFormat(string $format)
 * @method string getSourceKey()
 * @method void setSourceKey(string $sourceKey)
 * @method int getBudgetAccountId()
 * @method void setBudgetAccountId(int $budgetAccountId)
 * @method string getUpdatedAt()
 * @method void setUpdatedAt(string $updatedAt)
 */
class ImportAccountLink extends Entity {
    protected $userId;
    protected $format;
    protected $sourceKey;
    protected $budgetAccountId;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('budgetAccountId', 'integer');
    }
}
