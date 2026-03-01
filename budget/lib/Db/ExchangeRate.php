<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use JsonSerializable;
use OCP\AppFramework\Db\Entity;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method string getCurrency()
 * @method void setCurrency(string $currency)
 * @method string getRatePerEur()
 * @method void setRatePerEur(string $ratePerEur)
 * @method string getDate()
 * @method void setDate(string $date)
 * @method string getSource()
 * @method void setSource(string $source)
 * @method string getCreatedAt()
 * @method void setCreatedAt(string $createdAt)
 */
class ExchangeRate extends Entity implements JsonSerializable {
    public const SOURCE_ECB = 'ecb';
    public const SOURCE_COINGECKO = 'coingecko';

    protected $currency;
    protected $ratePerEur;
    protected $date;
    protected $source;
    protected $createdAt;

    public function __construct() {
        $this->addType('id', 'integer');
    }

    public function jsonSerialize(): array {
        return [
            'id' => $this->getId(),
            'currency' => $this->getCurrency(),
            'ratePerEur' => $this->getRatePerEur(),
            'date' => $this->getDate(),
            'source' => $this->getSource(),
            'createdAt' => $this->getCreatedAt(),
        ];
    }
}
