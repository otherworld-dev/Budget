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
 * @method string getPattern()
 * @method void setPattern(string $pattern)
 * @method string getField()
 * @method void setField(string $field)
 * @method string getMatchType()
 * @method void setMatchType(string $matchType)
 * @method int|null getCategoryId()
 * @method void setCategoryId(?int $categoryId)
 * @method string|null getVendorName()
 * @method void setVendorName(?string $vendorName)
 * @method int getPriority()
 * @method void setPriority(int $priority)
 * @method bool getActive()
 * @method void setActive(bool $active)
 * @method string|null getActions()
 * @method void setActions(?string $actions)
 * @method bool getApplyOnImport()
 * @method void setApplyOnImport(bool $applyOnImport)
 * @method string|null getCriteria()
 * @method void setCriteria(?string $criteria)
 * @method int getSchemaVersion()
 * @method void setSchemaVersion(int $schemaVersion)
 * @method bool getStopProcessing()
 * @method void setStopProcessing(bool $stopProcessing)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 * @method string|null getUpdatedAt()
 * @method void setUpdatedAt(?string $updatedAt)
 */
class ImportRule extends Entity implements JsonSerializable {
    protected $userId;
    protected $name;
    protected $pattern;
    protected $field;
    protected $matchType;
    protected $categoryId;
    protected $vendorName;
    protected $priority;
    protected $active;
    protected $actions;
    protected $applyOnImport;
    protected $criteria;
    protected $schemaVersion;
    protected $stopProcessing;
    protected $createdAt;
    protected $updatedAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('categoryId', 'integer');
        $this->addType('priority', 'integer');
        $this->addType('active', 'boolean');
        $this->addType('applyOnImport', 'boolean');
        $this->addType('schemaVersion', 'integer');
        $this->addType('stopProcessing', 'boolean');
    }

    /**
     * Serialize the import rule to JSON format
     * Returns all fields in camelCase format for frontend consumption
     */
    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'name' => $this->getName(),
            'pattern' => $this->getPattern(),
            'field' => $this->getField(),
            'matchType' => $this->getMatchType(),
            'categoryId' => $this->getCategoryId(),
            'vendorName' => $this->getVendorName(),
            'priority' => $this->getPriority(),
            'active' => $this->getActive(),
            'actions' => $this->getParsedActions(),
            'applyOnImport' => $this->getApplyOnImport() ?? true,
            'criteria' => $this->getParsedCriteria(),
            'schemaVersion' => $this->getSchemaVersion() ?? 1,
            'stopProcessing' => $this->getStopProcessing() ?? true,
            'createdAt' => $this->getCreatedAt(),
            'updatedAt' => $this->getUpdatedAt(),
        ];
    }

    /**
     * Get parsed actions from JSON string
     * Falls back to legacy categoryId/vendorName if actions is empty
     */
    public function getParsedActions(): array {
        $actionsJson = $this->getActions();
        if ($actionsJson) {
            $actions = json_decode($actionsJson, true);
            if (is_array($actions)) {
                return $actions;
            }
        }

        // Fallback to legacy fields
        $actions = [];
        if ($this->getCategoryId() !== null) {
            $actions['categoryId'] = $this->getCategoryId();
        }
        if ($this->getVendorName() !== null && $this->getVendorName() !== '') {
            $actions['vendor'] = $this->getVendorName();
        }
        return $actions;
    }

    /**
     * Set actions from array (converts to JSON string)
     */
    public function setActionsFromArray(array $actions): void {
        $this->setActions(json_encode($actions));
    }

    /**
     * Get parsed criteria from JSON string
     * Returns array or null for v1 rules
     */
    public function getParsedCriteria(): ?array {
        $criteriaJson = $this->getCriteria();
        if ($criteriaJson) {
            $criteria = json_decode($criteriaJson, true);
            if (is_array($criteria)) {
                return $criteria;
            }
        }
        return null;
    }

    /**
     * Set criteria from array (converts to JSON string)
     */
    public function setCriteriaFromArray(array $criteria): void {
        $this->setCriteria(json_encode($criteria));
    }
}