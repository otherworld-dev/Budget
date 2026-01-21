<?php

declare(strict_types=1);

namespace OCA\Budget\Attribute;

use Attribute;

/**
 * Attribute to mark entity properties as encrypted.
 * Properties marked with this attribute will be automatically encrypted
 * before database storage and decrypted after retrieval.
 *
 * Usage:
 * ```php
 * class Account extends Entity {
 *     #[Encrypted]
 *     protected $accountNumber;
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Encrypted {
    /**
     * @param bool $nullable Whether null values are allowed (skip encryption)
     */
    public function __construct(
        public readonly bool $nullable = true
    ) {
    }
}
