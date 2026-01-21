<?php

declare(strict_types=1);

namespace OCA\Budget\Exception;

/**
 * Exception for validation errors.
 *
 * Messages from this exception type are considered safe to expose to users
 * as they contain validation feedback rather than internal system details.
 */
class ValidationException extends \Exception {
}
