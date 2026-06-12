<?php

declare(strict_types=1);

namespace OCA\Budget\Db;

use OCP\AppFramework\Db\Entity;

/**
 * A recurring-bill (or income) suggestion the user dismissed — keyed by the
 * detector's normalized pattern hash so it never reappears.
 *
 * @method int getId()
 * @method void setId(int $id)
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getSuggestionType()
 * @method void setSuggestionType(string $suggestionType)
 * @method string getPatternHash()
 * @method void setPatternHash(string $patternHash)
 * @method string|null getPattern()
 * @method void setPattern(?string $pattern)
 * @method string getDismissedAt()
 * @method void setDismissedAt(string $dismissedAt)
 */
class DismissedSuggestion extends Entity {
    protected $userId;
    protected $suggestionType;
    protected $patternHash;
    protected $pattern;
    protected $dismissedAt;

    public function __construct() {
        $this->addType('id', 'integer');
    }
}
