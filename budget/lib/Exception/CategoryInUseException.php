<?php

declare(strict_types=1);

namespace OCA\Budget\Exception;

/**
 * Thrown when a category cannot be deleted because transactions are still
 * assigned to it. The controller surfaces a machine-readable code so the client
 * can offer to reassign those transactions to No Category and retry (#332).
 */
class CategoryInUseException extends \Exception {
}
