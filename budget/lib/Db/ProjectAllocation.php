<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * An amount set aside for one subcategory of a project (#391). Carries its
 * own user_id so the backup registry can clear it by user: scoped only
 * through budget_projects it would be cleared after its parents and never
 * found again.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 * @method int getCategoryId()
 * @method void setCategoryId(int $categoryId)
 * @method float getAmount()
 * @method void setAmount(float $amount)
 */
class ProjectAllocation extends Entity implements JsonSerializable {
    protected $userId;
    protected $projectId;
    protected $categoryId;
    protected $amount;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('projectId', 'integer');
        $this->addType('categoryId', 'integer');
        $this->addType('amount', 'float');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'projectId' => $this->getProjectId(),
            'categoryId' => $this->getCategoryId(),
            'amount' => $this->getAmount(),
        ];
    }
}
