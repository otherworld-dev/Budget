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
 * @method float getTotalAssets()
 * @method void setTotalAssets(float $totalAssets)
 * @method float getTotalLiabilities()
 * @method void setTotalLiabilities(float $totalLiabilities)
 * @method float getNetWorth()
 * @method void setNetWorth(float $netWorth)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class NetWorthSnapshot extends Entity implements JsonSerializable {
    public const SOURCE_AUTO = 'auto';
    public const SOURCE_MANUAL = 'manual';

    protected $userId;
    protected $totalAssets;
    protected $totalLiabilities;
    protected $netWorth;
    protected $date;
    protected $source;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
        $this->addType('totalAssets', 'float');
        $this->addType('totalLiabilities', 'float');
        $this->addType('netWorth', 'float');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'userId' => $this->getUserId(),
            'totalAssets' => $this->getTotalAssets(),
            'totalLiabilities' => $this->getTotalLiabilities(),
            'netWorth' => $this->getNetWorth(),
            'date' => $this->getDate(),
            'source' => $this->getSource(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
