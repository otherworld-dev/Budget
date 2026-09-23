<?php

declare(strict_types=1);

namespace OCA\Budget\Exception;

class ReadOnlyShareException extends \RuntimeException {
	public function __construct() {
		parent::__construct('This shared item is read-only');
	}
}
